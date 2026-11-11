<?php

namespace RoyalPay\Database;

defined('ABSPATH') || exit;

/**
 * Versioned Database Migration Runner for Royal Pay
 * Ensures all tables use MariaDB / MySQL InnoDB, utf8mb4, and integer minor units.
 */
class MigrationRunner {

    const DB_VERSION_OPTION = 'royalpay_db_version';
    const CURRENT_DB_VERSION = '1.0.0';

    public static function runMigrations(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'rpa_';

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // 1. Wallets Table
        $sql_wallets = "CREATE TABLE IF NOT EXISTS `{$prefix}wallets` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `provider` ENUM('bkash', 'nagad', 'rocket') NOT NULL,
            `account_number` VARCHAR(32) NOT NULL,
            `account_type` ENUM('merchant', 'personal', 'agent') NOT NULL DEFAULT 'personal',
            `account_label` VARCHAR(100) NULL,
            `encrypted_credentials` TEXT NULL,
            `daily_limit_minor` BIGINT(20) NOT NULL DEFAULT 2500000,
            `monthly_limit_minor` BIGINT(20) NOT NULL DEFAULT 50000000,
            `current_daily_total_minor` BIGINT(20) NOT NULL DEFAULT 0,
            `status` ENUM('active', 'disabled') NOT NULL DEFAULT 'active',
            `sort_order` INT(11) NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_provider_status` (`provider`, `status`),
            KEY `idx_account_number` (`account_number`)
        ) ENGINE=InnoDB {$charset_collate};";
        dbDelta($sql_wallets);

        // 2. Devices Table
        $sql_devices = "CREATE TABLE IF NOT EXISTS `{$prefix}devices` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `device_uuid` VARCHAR(64) NOT NULL,
            `device_name` VARCHAR(100) NOT NULL,
            `model` VARCHAR(100) NULL,
            `android_version` VARCHAR(32) NULL,
            `app_version` VARCHAR(32) NULL,
            `token_hash` VARCHAR(64) NOT NULL,
            `token_secret` VARCHAR(64) NOT NULL,
            `battery_percentage` INT(4) NULL,
            `is_charging` TINYINT(1) NOT NULL DEFAULT 0,
            `network_type` VARCHAR(32) NULL,
            `sim_operator` VARCHAR(64) NULL,
            `last_heartbeat_at` DATETIME NULL,
            `status` ENUM('active', 'suspended', 'revoked') NOT NULL DEFAULT 'active',
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_device_uuid` (`device_uuid`),
            UNIQUE KEY `uq_token_hash` (`token_hash`)
        ) ENGINE=InnoDB {$charset_collate};";
        dbDelta($sql_devices);

        // 3. Transactions Table
        $sql_transactions = "CREATE TABLE IF NOT EXISTS `{$prefix}transactions` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `wallet_id` BIGINT(20) UNSIGNED NULL,
            `provider` ENUM('bkash', 'nagad', 'rocket') NOT NULL,
            `transaction_id` VARCHAR(64) NOT NULL,
            `amount_minor` BIGINT(20) NOT NULL,
            `currency` VARCHAR(8) NOT NULL DEFAULT 'BDT',
            `sender_number` VARCHAR(32) NULL,
            `receiver_number` VARCHAR(32) NULL,
            `reference` VARCHAR(64) NULL,
            `verification_source` ENUM('official_api', 'android_sms', 'statement_import', 'manual_entry') NOT NULL DEFAULT 'android_sms',
            `status` ENUM('unmatched', 'matched', 'duplicate', 'review_required', 'reversed') NOT NULL DEFAULT 'unmatched',
            `raw_data` TEXT NULL,
            `transaction_time` DATETIME NOT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_provider_trxid` (`provider`, `transaction_id`),
            KEY `idx_reference` (`reference`),
            KEY `idx_amount_minor` (`amount_minor`),
            KEY `idx_status` (`status`),
            KEY `idx_transaction_time` (`transaction_time`)
        ) ENGINE=InnoDB {$charset_collate};";
        dbDelta($sql_transactions);

        // 4. Payment Matches Table (1-to-1 guarantee between order & transaction)
        $sql_matches = "CREATE TABLE IF NOT EXISTS `{$prefix}payment_matches` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `order_id` BIGINT(20) UNSIGNED NOT NULL,
            `transaction_id` BIGINT(20) UNSIGNED NOT NULL,
            `matched_amount_minor` BIGINT(20) NOT NULL,
            `match_rule` ENUM('exact_ref_trxid', 'exact_trxid', 'manual_approval') NOT NULL,
            `matched_by_user_id` BIGINT(20) UNSIGNED NULL,
            `matched_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_order_id` (`order_id`),
            UNIQUE KEY `uq_transaction_id` (`transaction_id`)
        ) ENGINE=InnoDB {$charset_collate};";
        dbDelta($sql_matches);

        // 5. SMS Logs Table
        $sql_sms = "CREATE TABLE IF NOT EXISTS `{$prefix}sms_logs` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `device_id` BIGINT(20) UNSIGNED NULL,
            `sender` VARCHAR(64) NOT NULL,
            `body` TEXT NOT NULL,
            `message_hash` VARCHAR(64) NOT NULL,
            `parsed_provider` VARCHAR(32) NULL,
            `parsed_trxid` VARCHAR(64) NULL,
            `parsed_amount_minor` BIGINT(20) NULL,
            `parsed_reference` VARCHAR(64) NULL,
            `parse_status` ENUM('valid', 'unrecognized', 'failed', 'duplicate') NOT NULL,
            `received_at` DATETIME NOT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_message_hash` (`message_hash`),
            KEY `idx_parsed_trxid` (`parsed_trxid`),
            KEY `idx_received_at` (`received_at`)
        ) ENGINE=InnoDB {$charset_collate};";
        dbDelta($sql_sms);

        // 6. Audit & Security Logs Table
        $sql_audit = "CREATE TABLE IF NOT EXISTS `{$prefix}audit_logs` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` BIGINT(20) UNSIGNED NULL,
            `action` VARCHAR(64) NOT NULL,
            `entity_type` VARCHAR(64) NOT NULL,
            `entity_id` BIGINT(20) UNSIGNED NULL,
            `ip_address` VARCHAR(45) NULL,
            `user_agent` VARCHAR(255) NULL,
            `details` TEXT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_action` (`action`),
            KEY `idx_entity` (`entity_type`, `entity_id`),
            KEY `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB {$charset_collate};";
        dbDelta($sql_audit);

        // 7. Background Queue Table
        $sql_jobs = "CREATE TABLE IF NOT EXISTS `{$prefix}jobs` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `queue` VARCHAR(64) NOT NULL DEFAULT 'default',
            `payload` LONGTEXT NOT NULL,
            `attempts` TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            `reserved_at` DATETIME NULL,
            `available_at` DATETIME NOT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_queue_reserved_available` (`queue`, `reserved_at`, `available_at`)
        ) ENGINE=InnoDB {$charset_collate};";
        dbDelta($sql_jobs);

        update_option(self::DB_VERSION_OPTION, self::CURRENT_DB_VERSION);
    }
}
