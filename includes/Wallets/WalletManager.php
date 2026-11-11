<?php

namespace RoyalPay\Wallets;

defined('ABSPATH') || exit;

use RoyalPay\Core\Security;
use RoyalPay\Logging\AuditLogger;

/**
 * MFS Wallet Management for bKash, Nagad, and Rocket
 */
class WalletManager {

    /**
     * Get all configured wallets, optionally filtered by provider and status
     */
    public static function getWallets(?string $provider = null, string $status = 'active'): array {
        global $wpdb;
        $table = $wpdb->prefix . 'rpa_wallets';

        $where = [];
        $params = [];

        if ($provider) {
            $where[] = '`provider` = %s';
            $params[] = $provider;
        }

        if ($status !== 'all') {
            $where[] = '`status` = %s';
            $params[] = $status;
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $query = "SELECT * FROM `{$table}` {$whereSql} ORDER BY `sort_order` ASC, `id` ASC";

        if (!empty($params)) {
            return $wpdb->get_results($wpdb->prepare($query, ...$params), ARRAY_A) ?: [];
        }

        return $wpdb->get_results($query, ARRAY_A) ?: [];
    }

    /**
     * Retrieve a single wallet by ID
     */
    public static function getWalletById(int $id): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'rpa_wallets';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE `id` = %d", $id), ARRAY_A);
        return $row ?: null;
    }

    /**
     * Retrieve wallet by account number and provider
     */
    public static function getWalletByNumber(string $provider, string $accountNumber): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'rpa_wallets';
        $cleanNumber = preg_replace('/[^0-9]/', '', $accountNumber);
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM `{$table}` WHERE `provider` = %s AND `account_number` = %s", $provider, $cleanNumber),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Create or update a wallet
     */
    public static function saveWallet(array $data): int {
        global $wpdb;
        $table = $wpdb->prefix . 'rpa_wallets';

        $id = !empty($data['id']) ? (int) $data['id'] : 0;
        $provider = sanitize_text_field($data['provider'] ?? 'bkash');
        $accountNumber = preg_replace('/[^0-9]/', '', sanitize_text_field($data['account_number'] ?? ''));
        $accountType = in_array($data['account_type'] ?? '', ['merchant', 'personal', 'agent'], true) ? $data['account_type'] : 'personal';
        $accountLabel = sanitize_text_field($data['account_label'] ?? '');
        $status = in_array($data['status'] ?? '', ['active', 'disabled'], true) ? $data['status'] : 'active';
        $dailyLimit = !empty($data['daily_limit']) ? Security::toMinorUnits($data['daily_limit']) : 2500000;
        $monthlyLimit = !empty($data['monthly_limit']) ? Security::toMinorUnits($data['monthly_limit']) : 50000000;

        $credentialsEnc = null;
        if (!empty($data['credentials']) && is_array($data['credentials'])) {
            $credentialsEnc = Security::encrypt(json_encode($data['credentials']));
        }

        $now = current_time('mysql');

        if ($id > 0) {
            $updateData = [
                'provider'            => $provider,
                'account_number'      => $accountNumber,
                'account_type'        => $accountType,
                'account_label'       => $accountLabel,
                'daily_limit_minor'   => $dailyLimit,
                'monthly_limit_minor' => $monthlyLimit,
                'status'              => $status,
                'updated_at'          => $now,
            ];
            if ($credentialsEnc !== null) {
                $updateData['encrypted_credentials'] = $credentialsEnc;
            }

            $wpdb->update($table, $updateData, ['id' => $id]);
            AuditLogger::log('wallet_updated', 'wallet', $id, ['provider' => $provider, 'account' => $accountNumber]);
            return $id;
        }

        $wpdb->insert($table, [
            'provider'              => $provider,
            'account_number'        => $accountNumber,
            'account_type'          => $accountType,
            'account_label'         => $accountLabel,
            'encrypted_credentials' => $credentialsEnc,
            'daily_limit_minor'     => $dailyLimit,
            'monthly_limit_minor'   => $monthlyLimit,
            'status'                => $status,
            'created_at'            => $now,
            'updated_at'            => $now,
        ]);

        $newId = $wpdb->insert_id;
        AuditLogger::log('wallet_created', 'wallet', $newId, ['provider' => $provider, 'account' => $accountNumber]);
        return $newId;
    }

    /**
     * Delete a wallet
     */
    public static function deleteWallet(int $id): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'rpa_wallets';
        $wallet = self::getWalletById($id);
        if (!$wallet) {
            return false;
        }

        $result = $wpdb->delete($table, ['id' => $id], ['%d']);
        if ($result) {
            AuditLogger::log('wallet_deleted', 'wallet', $id, $wallet);
            return true;
        }
        return false;
    }
}
