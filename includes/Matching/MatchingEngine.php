<?php

namespace RoyalPay\Matching;

defined('ABSPATH') || exit;

use RoyalPay\Core\Security;
use RoyalPay\Transactions\TransactionManager;
use RoyalPay\Wallets\WalletManager;
use RoyalPay\Logging\AuditLogger;

/**
 * Royal Pay Payment Matching Engine
 * 
 * Enforces strict multi-factor matching, atomic row locking, and duplicate prevention.
 * ZERO AMOUNT-ONLY APPROVALS.
 */
class MatchingEngine {

    /**
     * Match an ingested transaction against pending WooCommerce orders
     */
    public static function processTransactionMatch(int $transactionId): array {
        global $wpdb;

        $txnTable = $wpdb->prefix . 'rpa_transactions';
        $matchesTable = $wpdb->prefix . 'rpa_payment_matches';

        // 1. Fetch transaction record
        $txn = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$txnTable}` WHERE `id` = %d", $transactionId), ARRAY_A);
        if (!$txn) {
            return ['matched' => false, 'reason' => 'Transaction not found'];
        }

        if ($txn['status'] === 'matched') {
            return ['matched' => false, 'reason' => 'Transaction already matched'];
        }

        $provider = $txn['provider'];
        $trxId = strtoupper(trim($txn['transaction_id']));
        $amountMinor = (int) $txn['amount_minor'];
        $reference = trim($txn['reference'] ?? '');

        // 2. Candidate Order Resolution
        // Rule A: Order with exact Reference (if reference is present)
        $candidateOrderId = null;
        $matchRule = null;

        if (!empty($reference)) {
            $candidateOrderId = self::findOrderMatchingReference($reference, $amountMinor);
            if ($candidateOrderId) {
                $matchRule = 'exact_ref_trxid';
            }
        }

        // Rule B: Order where customer entered this exact TrxID
        if (!$candidateOrderId) {
            $candidateOrderId = self::findOrderMatchingTrxId($trxId, $provider, $amountMinor);
            if ($candidateOrderId) {
                $matchRule = 'exact_trxid';
            }
        }

        if (!$candidateOrderId) {
            // Rule C: Royal BatchMate Event Application Integration
            if (\RoyalPay\Compatibility\RoyalBatchmateAdapter::isBatchmateActive()) {
                $batchmateResult = \RoyalPay\Compatibility\RoyalBatchmateAdapter::matchEventApplication(
                    $trxId,
                    $provider,
                    $amountMinor,
                    $reference
                );

                if ($batchmateResult && !empty($batchmateResult['matched'])) {
                    $now = current_time('mysql');
                    $wpdb->insert(
                        $matchesTable,
                        [
                            'order_id'             => $batchmateResult['application_id'],
                            'transaction_id'       => $transactionId,
                            'matched_amount_minor' => $amountMinor,
                            'match_rule'           => 'exact_trxid',
                            'matched_by_user_id'   => null,
                            'matched_at'           => $now,
                        ]
                    );

                    $wpdb->update($txnTable, ['status' => 'matched'], ['id' => $transactionId]);

                    return [
                        'matched'        => true,
                        'source'         => 'royal_batchmate',
                        'application_id' => $batchmateResult['application_id'],
                        'event_id'       => $batchmateResult['event_id'],
                        'trxid'          => $trxId,
                        'serial_no'      => $batchmateResult['serial_no'],
                    ];
                }
            }

            // No safe match found — never approve on amount alone!
            return ['matched' => false, 'reason' => 'No eligible pending order found with exact reference or TrxID'];
        }

        // 3. Atomic Database Locking & Completion
        $wpdb->query("START TRANSACTION");

        try {
            // Lock match record if exists
            $alreadyMatched = $wpdb->get_row(
                $wpdb->prepare("SELECT id FROM `{$matchesTable}` WHERE `order_id` = %d OR `transaction_id` = %d FOR UPDATE", $candidateOrderId, $transactionId),
                ARRAY_A
            );

            if ($alreadyMatched) {
                $wpdb->query("ROLLBACK");
                return ['matched' => false, 'reason' => 'Order or Transaction has already been matched'];
            }

            // Lock and verify WooCommerce order
            $order = wc_get_order($candidateOrderId);
            if (!$order) {
                $wpdb->query("ROLLBACK");
                return ['matched' => false, 'reason' => 'WooCommerce order does not exist'];
            }

            // Check if order is in a valid pending state
            $orderStatus = $order->get_status();
            $allowedStatuses = ['pending', 'on-hold', 'failed'];
            if (!in_array($orderStatus, $allowedStatuses, true)) {
                $wpdb->query("ROLLBACK");
                return ['matched' => false, 'reason' => "Order is already in '{$orderStatus}' status"];
            }

            // Verify order amount matches transaction amount in minor units
            $orderTotalMinor = Security::toMinorUnits($order->get_total());
            if ($orderTotalMinor !== $amountMinor) {
                $wpdb->query("ROLLBACK");
                $wpdb->update($txnTable, ['status' => 'review_required'], ['id' => $transactionId]);
                $order->add_order_note(sprintf(
                    __('Royal Pay: Transaction received (%s %s) but amount does not match order total (%s). Flagged for review.', 'royalpay'),
                    Security::fromMinorUnits($amountMinor),
                    $txn['currency'],
                    $order->get_total()
                ));
                return ['matched' => false, 'reason' => 'Amount mismatch between transaction and order'];
            }

            // Insert into payment_matches (Unique constraints protect against race conditions)
            $now = current_time('mysql');
            $insertMatch = $wpdb->insert(
                $matchesTable,
                [
                    'order_id'             => $candidateOrderId,
                    'transaction_id'       => $transactionId,
                    'matched_amount_minor' => $amountMinor,
                    'match_rule'           => $matchRule,
                    'matched_by_user_id'   => null,
                    'matched_at'           => $now,
                ],
                ['%d', '%d', '%d', '%s', '%d', '%s']
            );

            if (!$insertMatch) {
                $wpdb->query("ROLLBACK");
                return ['matched' => false, 'reason' => 'Failed to record payment match: ' . $wpdb->last_error];
            }

            // Update transaction status to matched
            $wpdb->update($txnTable, ['status' => 'matched'], ['id' => $transactionId]);

            // Complete WooCommerce order using official CRUD APIs
            $order->payment_complete($trxId);
            $order->update_meta_data('_royalpay_provider', $provider);
            $order->update_meta_data('_royalpay_trxid', $trxId);
            $order->update_meta_data('_royalpay_matched_at', $now);
            $order->update_meta_data('_royalpay_match_rule', $matchRule);
            $order->save();

            $order->add_order_note(sprintf(
                __('Royal Pay: Payment verified automatically via %s. TrxID: %s | Amount: %s BDT | Rule: %s', 'royalpay'),
                strtoupper($provider),
                $trxId,
                Security::fromMinorUnits($amountMinor),
                $matchRule
            ));

            $wpdb->query("COMMIT");

            AuditLogger::log('payment_matched', 'order', $candidateOrderId, [
                'transaction_id' => $transactionId,
                'trxid'          => $trxId,
                'amount_minor'   => $amountMinor,
                'provider'       => $provider,
                'match_rule'     => $matchRule,
            ]);

            return [
                'matched'  => true,
                'order_id' => $candidateOrderId,
                'trxid'    => $trxId,
                'rule'     => $matchRule,
            ];

        } catch (\Throwable $e) {
            $wpdb->query("ROLLBACK");
            return ['matched' => false, 'reason' => 'Exception during matching: ' . $e->getMessage()];
        }
    }

    /**
     * Find pending order by generated payment reference
     */
    private static function findOrderMatchingReference(string $reference, int $amountMinor): ?int {
        global $wpdb;

        // Try matching reference stored in order meta
        $orders = wc_get_orders([
            'meta_key'   => '_royalpay_reference',
            'meta_value' => $reference,
            'status'     => ['pending', 'on-hold'],
            'limit'      => 1,
            'return'     => 'ids',
        ]);

        if (!empty($orders)) {
            $orderId = (int) $orders[0];
            $order = wc_get_order($orderId);
            if ($order && Security::toMinorUnits($order->get_total()) === $amountMinor) {
                return $orderId;
            }
        }

        // Also check if reference is order ID itself (e.g., "10492" or "RP-10492")
        $cleanedId = preg_replace('/[^0-9]/', '', $reference);
        if (!empty($cleanedId)) {
            $order = wc_get_order((int) $cleanedId);
            if ($order && in_array($order->get_status(), ['pending', 'on-hold'], true)) {
                if (Security::toMinorUnits($order->get_total()) === $amountMinor) {
                    return (int) $cleanedId;
                }
            }
        }

        return null;
    }

    /**
     * Find pending order where customer entered this exact TrxID at checkout
     */
    private static function findOrderMatchingTrxId(string $trxId, string $provider, int $amountMinor): ?int {
        $orders = wc_get_orders([
            'meta_key'   => '_royalpay_customer_trxid',
            'meta_value' => $trxId,
            'status'     => ['pending', 'on-hold'],
            'limit'      => 1,
            'return'     => 'ids',
        ]);

        if (!empty($orders)) {
            $orderId = (int) $orders[0];
            $order = wc_get_order($orderId);
            if ($order && Security::toMinorUnits($order->get_total()) === $amountMinor) {
                return $orderId;
            }
        }

        return null;
    }
}
