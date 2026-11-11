<?php

namespace RoyalPay\Transactions;

defined('ABSPATH') || exit;

use RoyalPay\Core\Security;
use RoyalPay\Logging\AuditLogger;

/**
 * Transaction and SMS Log Management
 */
class TransactionManager {

    /**
     * Get transactions with optional status, provider, or search filters
     */
    public static function getTransactions(array $args = []): array {
        global $wpdb;
        $table = $wpdb->prefix . 'rpa_transactions';

        $where = [];
        $params = [];

        if (!empty($args['provider'])) {
            $where[] = '`provider` = %s';
            $params[] = sanitize_text_field($args['provider']);
        }

        if (!empty($args['status'])) {
            $where[] = '`status` = %s';
            $params[] = sanitize_text_field($args['status']);
        }

        if (!empty($args['search'])) {
            $search = '%' . $wpdb->esc_like(sanitize_text_field($args['search'])) . '%';
            $where[] = '(`transaction_id` LIKE %s OR `reference` LIKE %s OR `sender_number` LIKE %s)';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $limit = isset($args['limit']) ? (int) $args['limit'] : 50;
        $offset = isset($args['offset']) ? (int) $args['offset'] : 0;

        $query = "SELECT * FROM `{$table}` {$whereSql} ORDER BY `id` DESC LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;

        return $wpdb->get_results($wpdb->prepare($query, ...$params), ARRAY_A) ?: [];
    }

    /**
     * Retrieve transaction by Provider & Transaction ID
     */
    public static function getTransactionByTrxId(string $provider, string $trxId): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'rpa_transactions';
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM `{$table}` WHERE `provider` = %s AND `transaction_id` = %s", $provider, strtoupper(trim($trxId))),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Save newly ingested transaction
     * Enforces unique constraint on (provider, transaction_id)
     */
    public static function ingestTransaction(array $data): array {
        global $wpdb;
        $table = $wpdb->prefix . 'rpa_transactions';

        $provider = strtolower(sanitize_text_field($data['provider'] ?? ''));
        $trxId    = strtoupper(trim(sanitize_text_field($data['transaction_id'] ?? '')));
        $amountMinor = isset($data['amount_minor']) ? (int) $data['amount_minor'] : Security::toMinorUnits($data['amount'] ?? 0);
        $currency = sanitize_text_field($data['currency'] ?? 'BDT');
        $sender = preg_replace('/[^0-9]/', '', sanitize_text_field($data['sender_number'] ?? ''));
        $receiver = preg_replace('/[^0-9]/', '', sanitize_text_field($data['receiver_number'] ?? ''));
        $reference = sanitize_text_field($data['reference'] ?? '');
        $source = sanitize_text_field($data['verification_source'] ?? 'android_sms');
        $txnTime = !empty($data['transaction_time']) ? sanitize_text_field($data['transaction_time']) : current_time('mysql');

        if (empty($provider) || empty($trxId) || $amountMinor <= 0) {
            return ['success' => false, 'code' => 'INVALID_PARAMS', 'message' => 'Missing provider, transaction ID, or valid amount.'];
        }

        // Check if duplicate transaction already exists
        $existing = self::getTransactionByTrxId($provider, $trxId);
        if ($existing) {
            return [
                'success' => false,
                'code'    => 'DUPLICATE_TRANSACTION',
                'message' => 'Transaction ID already exists in the system.',
                'id'      => $existing['id'],
                'data'    => $existing,
            ];
        }

        $now = current_time('mysql');
        $result = $wpdb->insert(
            $table,
            [
                'wallet_id'           => !empty($data['wallet_id']) ? (int) $data['wallet_id'] : null,
                'provider'            => $provider,
                'transaction_id'      => $trxId,
                'amount_minor'        => $amountMinor,
                'currency'            => $currency,
                'sender_number'       => $sender,
                'receiver_number'     => $receiver,
                'reference'           => $reference,
                'verification_source' => $source,
                'status'              => 'unmatched',
                'raw_data'            => !empty($data['raw_data']) ? json_encode($data['raw_data']) : null,
                'transaction_time'    => $txnTime,
                'created_at'          => $now,
            ]
        );

        if (!$result) {
            return ['success' => false, 'code' => 'DB_ERROR', 'message' => $wpdb->last_error];
        }

        $insertedId = $wpdb->insert_id;
        AuditLogger::log('transaction_ingested', 'transaction', $insertedId, [
            'provider' => $provider,
            'trxid'    => $trxId,
            'amount'   => $amountMinor,
            'source'   => $source,
        ]);

        return [
            'success' => true,
            'id'      => $insertedId,
            'trxid'   => $trxId,
            'amount'  => $amountMinor,
        ];
    }

    /**
     * Strict whitelist of official telecom sender IDs for Bangladesh MFS
     */
    const ALLOWED_SENDERS = [
        'bkash'  => ['bkash', '16247'],
        'nagad'  => ['nagad', '16167'],
        'rocket' => ['rocket', '16216'],
    ];

    /**
     * Verify if an incoming SMS sender ID is legitimate
     */
    public static function isValidSender(string $sender, ?string $provider = null): bool {
        $cleanSender = strtolower(trim($sender));
        
        if ($provider && isset(self::ALLOWED_SENDERS[$provider])) {
            return in_array($cleanSender, self::ALLOWED_SENDERS[$provider], true);
        }

        foreach (self::ALLOWED_SENDERS as $allowedList) {
            if (in_array($cleanSender, $allowedList, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ingest and log an incoming raw SMS from Android Agent
     */
    public static function logSms(array $smsData): array {
        global $wpdb;
        $table = $wpdb->prefix . 'rpa_sms_logs';

        $deviceId = !empty($smsData['device_id']) ? (int) $smsData['device_id'] : null;
        $sender   = sanitize_text_field($smsData['sender'] ?? '');
        $body     = trim($smsData['body'] ?? '');
        $parsedProvider = strtolower(sanitize_text_field($smsData['parsed_provider'] ?? ''));

        // Strict Sender ID Whitelist Verification
        if (!self::isValidSender($sender, $parsedProvider ?: null)) {
            AuditLogger::log('fake_sms_rejected', 'sms', null, [
                'sender'   => $sender,
                'device_id'=> $deviceId,
                'body'     => substr($body, 0, 100),
                'reason'   => 'Sender ID is not in official telecom whitelist (bKash/Nagad/Rocket)'
            ]);

            return [
                'success' => false,
                'code'    => 'INVALID_SENDER',
                'message' => 'SMS rejected: Sender ID is not an official MFS provider channel.'
            ];
        }
        $msgHash  = hash('sha256', $sender . '|' . $body);

        // Check duplicate SMS
        $existing = $wpdb->get_row($wpdb->prepare("SELECT id FROM `{$table}` WHERE `message_hash` = %s", $msgHash), ARRAY_A);
        if ($existing) {
            return ['success' => false, 'code' => 'DUPLICATE_SMS', 'id' => $existing['id']];
        }

        $now = current_time('mysql');
        $wpdb->insert(
            $table,
            [
                'device_id'           => $deviceId,
                'sender'              => $sender,
                'body'                => $body,
                'message_hash'        => $msgHash,
                'parsed_provider'     => sanitize_text_field($smsData['parsed_provider'] ?? ''),
                'parsed_trxid'        => strtoupper(sanitize_text_field($smsData['parsed_trxid'] ?? '')),
                'parsed_amount_minor' => !empty($smsData['parsed_amount_minor']) ? (int) $smsData['parsed_amount_minor'] : null,
                'parsed_reference'    => sanitize_text_field($smsData['parsed_reference'] ?? ''),
                'parse_status'        => sanitize_text_field($smsData['parse_status'] ?? 'valid'),
                'received_at'         => !empty($smsData['received_at']) ? sanitize_text_field($smsData['received_at']) : $now,
                'created_at'          => $now,
            ]
        );

        return ['success' => true, 'id' => $wpdb->insert_id];
    }
}
