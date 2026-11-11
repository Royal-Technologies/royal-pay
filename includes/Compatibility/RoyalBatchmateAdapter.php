<?php

namespace RoyalPay\Compatibility;

defined('ABSPATH') || exit;

use RoyalPay\Core\Security;
use RoyalPay\Logging\AuditLogger;

/**
 * Royal BatchMate Integration Adapter
 * 
 * Automatically matches and approves Event Applications and Membership Payments
 * in the Royal BatchMate plugin when verified MFS transactions arrive.
 */
class RoyalBatchmateAdapter {

    /**
     * Check if Royal BatchMate plugin tables exist
     */
    public static function isBatchmateActive(): bool {
        global $wpdb;
        if (!isset($wpdb) || empty($wpdb)) {
            return false;
        }
        $table = $wpdb->prefix . 'batchmate_event_application';
        return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table;
    }

    /**
     * Attempt to match a transaction with a pending Royal BatchMate Event Application
     */
    public static function matchEventApplication(string $trxId, string $provider, int $amountMinor, string $reference = ''): ?array {
        global $wpdb;

        if (!self::isBatchmateActive()) {
            return null;
        }

        $appTable = $wpdb->prefix . 'batchmate_event_application';
        $amountFloat = (float) ($amountMinor / 100);

        // 1. Search for pending event application by exact TrxID in payment_details
        $sql = "SELECT * FROM `{$appTable}` 
                WHERE `status` = 'pending' 
                  AND (
                      `payment_details` LIKE %s 
                      OR `payment_details` = %s
                  ) 
                ORDER BY `id` DESC LIMIT 1";

        $likeTrx = '%' . $wpdb->esc_like($trxId) . '%';
        $app = $wpdb->get_row($wpdb->prepare($sql, $likeTrx, $trxId), ARRAY_A);

        // If not found by TrxID, and reference is provided (e.g. BM-EVT-12)
        if (!$app && !empty($reference)) {
            $likeRef = '%' . $wpdb->esc_like($reference) . '%';
            $app = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM `{$appTable}` WHERE `status` = 'pending' AND `payment_details` LIKE %s LIMIT 1",
                $likeRef
            ), ARRAY_A);
        }

        if (!$app) {
            return null;
        }

        // Verify amount
        $appAmountFloat = (float) $app['amount'];
        $appAmountMinor = Security::toMinorUnits($appAmountFloat);

        if ($appAmountMinor !== $amountMinor) {
            AuditLogger::log('batchmate_amount_mismatch', 'batchmate_event_application', $app['id'], [
                'expected_minor' => $appAmountMinor,
                'received_minor' => $amountMinor,
                'trxid'          => $trxId,
            ]);
            return null;
        }

        // Execute BatchMate Event Approval
        return self::approveEventApplication((int) $app['id'], $trxId, $provider, $amountMinor);
    }

    /**
     * Programmatically approve a BatchMate Event Application
     */
    public static function approveEventApplication(int $appId, string $trxId, string $provider, int $amountMinor): ?array {
        global $wpdb;

        $appTable    = $wpdb->prefix . 'batchmate_event_application';
        $memberTable = $wpdb->prefix . 'batchmate_event_members';
        $eventTable  = $wpdb->prefix . 'batchmate_event';

        $app = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$appTable}` WHERE `id` = %d", $appId), ARRAY_A);
        if (!$app || $app['status'] === 'approved') {
            return null;
        }

        $eventId = (int) $app['event_id'];
        $memberId = (int) $app['member_id'];
        $rawdataId = (int) $app['rawdata_id'];

        $wpdb->query("START TRANSACTION");

        try {
            // Check if already registered
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(id) FROM `{$memberTable}` WHERE `event_id` = %d AND `member_id` = %d AND `rawdata_id` = %d",
                $eventId, $memberId, $rawdataId
            ));

            if ($exists > 0) {
                $wpdb->query("ROLLBACK");
                return null;
            }

            // Calculate next serial number and register event member if auto-serial is enabled
            $autoSerial = get_option('royalpay_batchmate_auto_serial', '1') == '1';
            $nextSerial = 0;

            if ($autoSerial) {
                $nextSerial = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COALESCE(MAX(serial_no), 0) + 1 FROM `{$memberTable}` WHERE `event_id` = %d",
                    $eventId
                ));

                // Insert into event_members
                $wpdb->insert($memberTable, [
                    'event_id'       => $eventId,
                    'member_id'      => $memberId,
                    'application_id' => $appId,
                    'rawdata_id'     => $rawdataId,
                    'serial_no'      => $nextSerial,
                    'approved_by'    => 1, // System / Admin
                ]);
            }

            // Update application status per configured setting
            $targetStatus = get_option('royalpay_batchmate_paid_status', 'approved');

            $wpdb->update($appTable, [
                'status'           => $targetStatus,
                'payment_method'   => strtoupper($provider),
                'payment_receiver' => 'Royal Pay Auto',
            ], ['id' => $appId]);

            // Also record in batchmate_payments table if it exists
            $paymentsTable = $wpdb->prefix . 'batchmate_payments';
            if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $paymentsTable)) === $paymentsTable) {
                $wpdb->insert($paymentsTable, [
                    'member_id'       => $memberId ?: null,
                    'amount'          => ($amountMinor / 100),
                    'payment_date'    => current_time('Y-m-d'),
                    'payment_mode_id' => 1,
                    'purpose_id'      => null,
                    'transaction'     => $trxId,
                    'note'            => sprintf('Verified via Royal Pay (%s) for Event App #%d', strtoupper($provider), $appId),
                    'created_by'      => 1,
                ]);
            }

            $wpdb->query("COMMIT");

            // Trigger BatchMate confirmation email if enabled
            $sendEmail = get_option('royalpay_batchmate_send_email', '1') == '1';
            if ($sendEmail && function_exists('BatchMate_send_event_confirmation_email')) {
                try {
                    \BatchMate_send_event_confirmation_email($appId, $nextSerial, null);
                } catch (\Throwable $e) {
                    // Suppress email exceptions
                }
            }

            // External Webhook Notification if enabled
            if (get_option('royalpay_enable_webhook', '0') == '1') {
                $webhookUrl = get_option('royalpay_webhook_url', '');
                if (!empty($webhookUrl)) {
                    wp_remote_post($webhookUrl, [
                        'timeout'   => 5,
                        'blocking'  => false,
                        'headers'   => ['Content-Type' => 'application/json'],
                        'body'      => json_encode([
                            'event'          => 'payment.matched',
                            'source'         => 'royal_batchmate',
                            'application_id' => $appId,
                            'event_id'       => $eventId,
                            'serial_no'      => $nextSerial,
                            'transaction_id' => $trxId,
                            'provider'       => $provider,
                            'amount'         => ($amountMinor / 100),
                            'status'         => $targetStatus,
                            'timestamp'      => current_time('mysql'),
                        ]),
                    ]);
                }
            }

            AuditLogger::log('batchmate_event_approved', 'batchmate_event_application', $appId, [
                'event_id'     => $eventId,
                'trxid'        => $trxId,
                'provider'     => $provider,
                'serial_no'    => $nextSerial,
                'amount_minor' => $amountMinor,
            ]);

            return [
                'matched'        => true,
                'application_id' => $appId,
                'event_id'       => $eventId,
                'serial_no'      => $nextSerial,
                'trxid'          => $trxId,
            ];

        } catch (\Throwable $e) {
            $wpdb->query("ROLLBACK");
            return null;
        }
    }
}
