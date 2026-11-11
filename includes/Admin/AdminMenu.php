<?php

namespace RoyalPay\Admin;

defined('ABSPATH') || exit;

use RoyalPay\License\LicenseManager;
use RoyalPay\Wallets\WalletManager;
use RoyalPay\Devices\DeviceManager;
use RoyalPay\Transactions\TransactionManager;
use RoyalPay\Core\Security;

/**
 * Admin Menus and Dashboard Controllers
 */
class AdminMenu {

    private static ?AdminMenu $instance = null;

    public static function getInstance(): AdminMenu {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void {
        add_action('admin_menu', [$this, 'registerMenus']);
        add_action('admin_init', [$this, 'handleFormSubmissions']);
    }

    public function registerMenus(): void {
        add_menu_page(
            __('Royal Pay', 'royalpay'),
            __('Royal Pay', 'royalpay'),
            'manage_woocommerce',
            'royalpay',
            [$this, 'renderDashboard'],
            'dashicons-money-alt',
            56
        );

        add_submenu_page(
            'royalpay',
            __('Dashboard', 'royalpay'),
            __('Dashboard', 'royalpay'),
            'manage_woocommerce',
            'royalpay',
            [$this, 'renderDashboard']
        );

        add_submenu_page(
            'royalpay',
            __('Wallets', 'royalpay'),
            __('Wallets', 'royalpay'),
            'manage_woocommerce',
            'royalpay-wallets',
            [$this, 'renderWallets']
        );

        add_submenu_page(
            'royalpay',
            __('Devices', 'royalpay'),
            __('Devices', 'royalpay'),
            'manage_woocommerce',
            'royalpay-devices',
            [$this, 'renderDevices']
        );

        add_submenu_page(
            'royalpay',
            __('Transactions', 'royalpay'),
            __('Transactions', 'royalpay'),
            'manage_woocommerce',
            'royalpay-transactions',
            [$this, 'renderTransactions']
        );

        add_submenu_page(
            'royalpay',
            __('SMS Logs', 'royalpay'),
            __('SMS Logs', 'royalpay'),
            'manage_woocommerce',
            'royalpay-sms-logs',
            [$this, 'renderSmsLogs']
        );

        add_submenu_page(
            'royalpay',
            __('License', 'royalpay'),
            __('License', 'royalpay'),
            'manage_woocommerce',
            'royalpay-license',
            [$this, 'renderLicense']
        );
    }

    public function handleFormSubmissions(): void {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        // Handle License Activation
        if (isset($_POST['royalpay_activate_license'])) {
            check_admin_referer('royalpay_license_action', 'royalpay_license_nonce');
            $key = sanitize_text_field($_POST['royalpay_license_key'] ?? '');
            $res = LicenseManager::getInstance()->activateLicense($key);
            if ($res['success']) {
                add_settings_error('royalpay_notices', 'license_success', $res['message'], 'updated');
            } else {
                add_settings_error('royalpay_notices', 'license_error', $res['message'], 'error');
            }
        }

        // Handle License Deactivation
        if (isset($_POST['royalpay_deactivate_license'])) {
            check_admin_referer('royalpay_license_action', 'royalpay_license_nonce');
            LicenseManager::getInstance()->deactivateLicense();
            add_settings_error('royalpay_notices', 'license_deactivated', __('License deactivated.', 'royalpay'), 'updated');
        }

        // Handle Wallet Save
        if (isset($_POST['royalpay_save_wallet'])) {
            check_admin_referer('royalpay_wallet_action', 'royalpay_wallet_nonce');
            WalletManager::saveWallet($_POST);
            add_settings_error('royalpay_notices', 'wallet_saved', __('Wallet saved successfully.', 'royalpay'), 'updated');
        }

        // Handle Wallet Delete
        if (isset($_GET['action'], $_GET['wallet_id']) && $_GET['action'] === 'delete_wallet') {
            check_admin_referer('delete_wallet_' . (int)$_GET['wallet_id']);
            WalletManager::deleteWallet((int)$_GET['wallet_id']);
            wp_safe_redirect(admin_url('admin.php?page=royalpay-wallets&deleted=1'));
            exit;
        }

        // Handle Device Revoke
        if (isset($_GET['action'], $_GET['device_id']) && $_GET['action'] === 'revoke_device') {
            check_admin_referer('revoke_device_' . (int)$_GET['device_id']);
            DeviceManager::revokeDevice((int)$_GET['device_id']);
            wp_safe_redirect(admin_url('admin.php?page=royalpay-devices&revoked=1'));
            exit;
        }
    }

    /**
     * Dashboard View
     */
    public function renderDashboard(): void {
        global $wpdb;

        $txnTable = $wpdb->prefix . 'rpa_transactions';
        $devicesTable = $wpdb->prefix . 'rpa_devices';

        $totalTransactions = $wpdb->get_var("SELECT COUNT(*) FROM `{$txnTable}`") ?: 0;
        $matchedTransactions = $wpdb->get_var("SELECT COUNT(*) FROM `{$txnTable}` WHERE `status` = 'matched'") ?: 0;
        $totalVolumeMinor = $wpdb->get_var("SELECT SUM(`amount_minor`) FROM `{$txnTable}` WHERE `status` = 'matched'") ?: 0;
        $activeDevicesCount = $wpdb->get_var("SELECT COUNT(*) FROM `{$devicesTable}` WHERE `status` = 'active'") ?: 0;

        $recentTxns = TransactionManager::getTransactions(['limit' => 10]);
        $isLicensed = LicenseManager::getInstance()->isLicensed();

        ?>
        <div class="wrap royalpay-admin-wrap">
            <h1 class="wp-heading-inline">👑 <?php esc_html_e('Royal Pay Dashboard', 'royalpay'); ?></h1>
            <hr class="wp-header-end">

            <?php settings_errors('royalpay_notices'); ?>

            <?php if (!$isLicensed): ?>
                <div class="notice notice-warning is-dismissible" style="padding:12px; border-left-color:#f59e0b;">
                    <p style="margin:0; font-size:14px;">
                        ⚠️ <strong><?php esc_html_e('License Inactive:', 'royalpay'); ?></strong>
                        <?php esc_html_e('Please activate your Royal Pay license key to enable automated payment verification.', 'royalpay'); ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=royalpay-license')); ?>" class="button button-small" style="margin-left:8px;"><?php esc_html_e('Activate Now', 'royalpay'); ?></a>
                    </p>
                </div>
            <?php endif; ?>

            <div class="royalpay-stats-grid">
                <div class="royalpay-card stat-card">
                    <div class="stat-icon" style="background:#dbeafe; color:#1d4ed8;">💰</div>
                    <div class="stat-info">
                        <h3><?php echo esc_html(Security::fromMinorUnits((int)$totalVolumeMinor)); ?> BDT</h3>
                        <p><?php esc_html_e('Total Verified Volume', 'royalpay'); ?></p>
                    </div>
                </div>

                <div class="royalpay-card stat-card">
                    <div class="stat-icon" style="background:#d1fae5; color:#047857;">✅</div>
                    <div class="stat-info">
                        <h3><?php echo esc_html($matchedTransactions); ?></h3>
                        <p><?php esc_html_e('Verified Orders', 'royalpay'); ?></p>
                    </div>
                </div>

                <div class="royalpay-card stat-card">
                    <div class="stat-icon" style="background:#fef3c7; color:#b45309;">📥</div>
                    <div class="stat-info">
                        <h3><?php echo esc_html($totalTransactions); ?></h3>
                        <p><?php esc_html_e('Total Ingested Txns', 'royalpay'); ?></p>
                    </div>
                </div>

                <div class="royalpay-card stat-card">
                    <div class="stat-icon" style="background:#ede9fe; color:#6d28d9;">📱</div>
                    <div class="stat-info">
                        <h3><?php echo esc_html($activeDevicesCount); ?></h3>
                        <p><?php esc_html_e('Active Android Agents', 'royalpay'); ?></p>
                    </div>
                </div>
            </div>

            <div class="royalpay-card" style="margin-top:24px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                    <h2 style="margin:0;"><?php esc_html_e('Recent Ingested Transactions', 'royalpay'); ?></h2>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=royalpay-transactions')); ?>" class="button"><?php esc_html_e('View All', 'royalpay'); ?></a>
                </div>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('TrxID', 'royalpay'); ?></th>
                            <th><?php esc_html_e('Provider', 'royalpay'); ?></th>
                            <th><?php esc_html_e('Amount', 'royalpay'); ?></th>
                            <th><?php esc_html_e('Sender', 'royalpay'); ?></th>
                            <th><?php esc_html_e('Reference', 'royalpay'); ?></th>
                            <th><?php esc_html_e('Status', 'royalpay'); ?></th>
                            <th><?php esc_html_e('Time', 'royalpay'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentTxns)): ?>
                            <tr><td colspan="7" style="text-align:center; padding:20px;"><?php esc_html_e('No transactions recorded yet.', 'royalpay'); ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($recentTxns as $t): ?>
                                <tr>
                                    <td><strong><?php echo esc_html($t['transaction_id']); ?></strong></td>
                                    <td><span class="badge badge-<?php echo esc_attr($t['provider']); ?>"><?php echo esc_html(strtoupper($t['provider'])); ?></span></td>
                                    <td><strong><?php echo esc_html(Security::fromMinorUnits($t['amount_minor'])); ?> BDT</strong></td>
                                    <td><?php echo esc_html($t['sender_number'] ?: '—'); ?></td>
                                    <td><code><?php echo esc_html($t['reference'] ?: '—'); ?></code></td>
                                    <td>
                                        <span class="status-badge status-<?php echo esc_attr($t['status']); ?>">
                                            <?php echo esc_html(ucwords(str_replace('_', ' ', $t['status']))); ?>
                                        </span>
                                    </td>
                                    <td><?php echo esc_html($t['transaction_time']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php self::renderFooter(); ?>
        </div>
        <?php
    }

    /**
     * Wallets View
     */
    public function renderWallets(): void {
        $wallets = WalletManager::getWallets(null, 'all');
        ?>
        <div class="wrap royalpay-admin-wrap">
            <h1 class="wp-heading-inline">💼 <?php esc_html_e('MFS Wallets Configuration', 'royalpay'); ?></h1>
            <hr class="wp-header-end">
            <?php settings_errors('royalpay_notices'); ?>

            <div style="display:grid; grid-template-columns: 2fr 1fr; gap:24px; margin-top:20px;">
                <div class="royalpay-card">
                    <h2><?php esc_html_e('Active Numbers & Accounts', 'royalpay'); ?></h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Provider', 'royalpay'); ?></th>
                                <th><?php esc_html_e('Account Number', 'royalpay'); ?></th>
                                <th><?php esc_html_e('Type', 'royalpay'); ?></th>
                                <th><?php esc_html_e('Label', 'royalpay'); ?></th>
                                <th><?php esc_html_e('Status', 'royalpay'); ?></th>
                                <th><?php esc_html_e('Actions', 'royalpay'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($wallets)): ?>
                                <tr><td colspan="6" style="text-align:center; padding:20px;"><?php esc_html_e('No wallets configured yet. Add your bKash or Nagad number on the right.', 'royalpay'); ?></td></tr>
                            <?php else: ?>
                                <?php foreach ($wallets as $w): ?>
                                    <tr>
                                        <td><span class="badge badge-<?php echo esc_attr($w['provider']); ?>"><?php echo esc_html(strtoupper($w['provider'])); ?></span></td>
                                        <td><strong><?php echo esc_html($w['account_number']); ?></strong></td>
                                        <td><?php echo esc_html(ucfirst($w['account_type'])); ?></td>
                                        <td><?php echo esc_html($w['account_label'] ?: '—'); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $w['status'] === 'active' ? 'matched' : 'duplicate'; ?>">
                                                <?php echo esc_html(ucfirst($w['status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                            $delUrl = wp_nonce_url(
                                                admin_url('admin.php?page=royalpay-wallets&action=delete_wallet&wallet_id=' . $w['id']),
                                                'delete_wallet_' . $w['id']
                                            );
                                            ?>
                                            <a href="<?php echo esc_url($delUrl); ?>" onclick="return confirm('Delete this wallet?');" style="color:#ef4444;"><?php esc_html_e('Delete', 'royalpay'); ?></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="royalpay-card">
                    <h2><?php esc_html_e('Add New MFS Wallet', 'royalpay'); ?></h2>
                    <form method="POST">
                        <?php wp_nonce_field('royalpay_wallet_action', 'royalpay_wallet_nonce'); ?>
                        <p>
                            <label style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e('Provider:', 'royalpay'); ?></label>
                            <select name="provider" style="width:100%;" required>
                                <option value="bkash">bKash</option>
                                <option value="nagad">Nagad</option>
                                <option value="rocket">Rocket</option>
                            </select>
                        </p>

                        <p>
                            <label style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e('Account Number:', 'royalpay'); ?></label>
                            <input type="text" name="account_number" placeholder="017XXXXXXXX" style="width:100%;" required />
                        </p>

                        <p>
                            <label style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e('Account Type:', 'royalpay'); ?></label>
                            <select name="account_type" style="width:100%;">
                                <option value="personal"><?php esc_html_e('Personal (Send Money)', 'royalpay'); ?></option>
                                <option value="merchant"><?php esc_html_e('Merchant (Payment)', 'royalpay'); ?></option>
                                <option value="agent"><?php esc_html_e('Agent (Cash In)', 'royalpay'); ?></option>
                            </select>
                        </p>

                        <p>
                            <label style="display:block; font-weight:600; margin-bottom:4px;"><?php esc_html_e('Account Label (Optional):', 'royalpay'); ?></label>
                            <input type="text" name="account_label" placeholder="e.g. Primary bKash SIM" style="width:100%;" />
                        </p>

                        <p>
                            <button type="submit" name="royalpay_save_wallet" class="button button-primary" style="width:100%; height:40px;">
                                <?php esc_html_e('Save Wallet', 'royalpay'); ?>
                            </button>
                        </p>
                    </form>
                </div>
            </div>
            <?php self::renderFooter(); ?>
        </div>
        <?php
    }

    /**
     * Devices View (Pairing QR Code & Connected Phones)
     */
    public function renderDevices(): void {
        $devices = DeviceManager::getDevices();
        $pairing = DeviceManager::generatePairingPayload();
        $apkUrl = ROYALPAY_PLUGIN_URL . 'apk/royal-pay-agent.apk';
        ?>
        <div class="wrap royalpay-admin-wrap">
            <div style="display:flex; justify-content:space-between; align-items:center;">
                <h1 class="wp-heading-inline">📱 <?php esc_html_e('Android Agent Devices', 'royalpay'); ?></h1>
                <a href="<?php echo esc_url($apkUrl); ?>" class="button-download-apk" download>
                    📥 <?php esc_html_e('Download Royal Pay Agent (APK)', 'royalpay'); ?>
                </a>
            </div>
            <hr class="wp-header-end">
            <?php settings_errors('royalpay_notices'); ?>

            <div style="display:grid; grid-template-columns: 2fr 1fr; gap:24px; margin-top:20px;">
                <div class="royalpay-card">
                    <h2><?php esc_html_e('Connected Android Agents', 'royalpay'); ?></h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Device Name', 'royalpay'); ?></th>
                                <th><?php esc_html_e('Model / Android', 'royalpay'); ?></th>
                                <th><?php esc_html_e('Battery', 'royalpay'); ?></th>
                                <th><?php esc_html_e('Network / SIM', 'royalpay'); ?></th>
                                <th><?php esc_html_e('Last Seen', 'royalpay'); ?></th>
                                <th><?php esc_html_e('Status', 'royalpay'); ?></th>
                                <th><?php esc_html_e('Action', 'royalpay'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($devices)): ?>
                                <tr><td colspan="7" style="text-align:center; padding:20px;"><?php esc_html_e('No devices connected yet. Scan the QR code on the right with Royal Pay Agent.', 'royalpay'); ?></td></tr>
                            <?php else: ?>
                                <?php foreach ($devices as $d): ?>
                                    <tr>
                                        <td><strong><?php echo esc_html($d['device_name']); ?></strong></td>
                                        <td><?php echo esc_html($d['model'] ?: 'Android'); ?> (v<?php echo esc_html($d['android_version'] ?: '14'); ?>)</td>
                                        <td>
                                            <?php if ($d['battery_percentage'] !== null): ?>
                                                🔋 <?php echo esc_html($d['battery_percentage']); ?>%
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html($d['sim_operator'] ?: 'Cellular'); ?></td>
                                        <td><?php echo esc_html($d['last_heartbeat_at'] ?: 'Never'); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $d['status'] === 'active' ? 'matched' : 'duplicate'; ?>">
                                                <?php echo esc_html(ucfirst($d['status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                            $revUrl = wp_nonce_url(
                                                admin_url('admin.php?page=royalpay-devices&action=revoke_device&device_id=' . $d['id']),
                                                'revoke_device_' . $d['id']
                                            );
                                            ?>
                                            <a href="<?php echo esc_url($revUrl); ?>" onclick="return confirm('Revoke this device? It will stop syncing SMS.');" style="color:#ef4444;"><?php esc_html_e('Revoke', 'royalpay'); ?></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="royalpay-card" style="text-align:center;">
                    <h2>📲 <?php esc_html_e('Connect New Device', 'royalpay'); ?></h2>
                    <p style="font-size:13px; color:#4b5563; margin-bottom:16px;">
                        <?php esc_html_e('Open Royal Pay Agent on your phone and scan this QR code to connect instantly:', 'royalpay'); ?>
                    </p>

                    <!-- QR Code Rendering via Google Chart API / SVG -->
                    <div style="background:#fff; padding:12px; display:inline-block; border-radius:8px; border:1px solid #e5e7eb; box-shadow:0 1px 3px rgba(0,0,0,0.1);">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?php echo urlencode($pairing['qr_payload']); ?>" alt="Pairing QR Code" style="width:200px; height:200px; display:block;" />
                    </div>

                    <div style="margin-top:14px; text-align:left; background:#f9fafb; padding:10px; border-radius:6px; font-size:12px; word-break:break-all;">
                        <strong><?php esc_html_e('API URL:', 'royalpay'); ?></strong><br>
                        <code><?php echo esc_html($pairing['api_url']); ?></code><br><br>
                        <strong><?php esc_html_e('Pairing Token:', 'royalpay'); ?></strong><br>
                        <code><?php echo esc_html($pairing['pairing_token']); ?></code>
                    </div>
                </div>
            </div>
            <?php self::renderFooter(); ?>
        </div>
        <?php
    }

    /**
     * Transactions Ledger View
     */
    public function renderTransactions(): void {
        $filterProvider = sanitize_text_field($_GET['provider'] ?? '');
        $filterStatus   = sanitize_text_field($_GET['status'] ?? '');
        $search         = sanitize_text_field($_GET['s'] ?? '');

        $transactions = TransactionManager::getTransactions([
            'provider' => $filterProvider,
            'status'   => $filterStatus,
            'search'   => $search,
            'limit'    => 50,
        ]);

        ?>
        <div class="wrap royalpay-admin-wrap">
            <h1 class="wp-heading-inline">📜 <?php esc_html_e('Transactions Ledger', 'royalpay'); ?></h1>
            <hr class="wp-header-end">

            <div class="tablenav top" style="margin-top:16px;">
                <form method="GET" style="display:flex; gap:10px; align-items:center;">
                    <input type="hidden" name="page" value="royalpay-transactions" />

                    <select name="provider">
                        <option value=""><?php esc_html_e('All Providers', 'royalpay'); ?></option>
                        <option value="bkash" <?php selected($filterProvider, 'bkash'); ?>>bKash</option>
                        <option value="nagad" <?php selected($filterProvider, 'nagad'); ?>>Nagad</option>
                        <option value="rocket" <?php selected($filterProvider, 'rocket'); ?>>Rocket</option>
                    </select>

                    <select name="status">
                        <option value=""><?php esc_html_e('All Statuses', 'royalpay'); ?></option>
                        <option value="matched" <?php selected($filterStatus, 'matched'); ?>><?php esc_html_e('Matched & Completed', 'royalpay'); ?></option>
                        <option value="unmatched" <?php selected($filterStatus, 'unmatched'); ?>><?php esc_html_e('Unmatched', 'royalpay'); ?></option>
                        <option value="review_required" <?php selected($filterStatus, 'review_required'); ?>><?php esc_html_e('Review Required', 'royalpay'); ?></option>
                        <option value="duplicate" <?php selected($filterStatus, 'duplicate'); ?>><?php esc_html_e('Duplicate', 'royalpay'); ?></option>
                    </select>

                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_html_e('Search TrxID, Sender, Ref...', 'royalpay'); ?>" />
                    <button type="submit" class="button"><?php esc_html_e('Filter', 'royalpay'); ?></button>
                </form>
            </div>

            <table class="wp-list-table widefat fixed striped" style="margin-top:12px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('ID', 'royalpay'); ?></th>
                        <th><?php esc_html_e('Provider', 'royalpay'); ?></th>
                        <th><?php esc_html_e('Transaction ID', 'royalpay'); ?></th>
                        <th><?php esc_html_e('Amount', 'royalpay'); ?></th>
                        <th><?php esc_html_e('Sender Number', 'royalpay'); ?></th>
                        <th><?php esc_html_e('Reference', 'royalpay'); ?></th>
                        <th><?php esc_html_e('Source', 'royalpay'); ?></th>
                        <th><?php esc_html_e('Status', 'royalpay'); ?></th>
                        <th><?php esc_html_e('Received At', 'royalpay'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transactions)): ?>
                        <tr><td colspan="9" style="text-align:center; padding:20px;"><?php esc_html_e('No transactions found.', 'royalpay'); ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($transactions as $t): ?>
                            <tr>
                                <td>#<?php echo esc_html($t['id']); ?></td>
                                <td><span class="badge badge-<?php echo esc_attr($t['provider']); ?>"><?php echo esc_html(strtoupper($t['provider'])); ?></span></td>
                                <td><strong><code><?php echo esc_html($t['transaction_id']); ?></code></strong></td>
                                <td><strong><?php echo esc_html(Security::fromMinorUnits($t['amount_minor'])); ?> BDT</strong></td>
                                <td><?php echo esc_html($t['sender_number'] ?: '—'); ?></td>
                                <td><code><?php echo esc_html($t['reference'] ?: '—'); ?></code></td>
                                <td><span style="font-size:11px; text-transform:uppercase; color:#6b7280;"><?php echo esc_html($t['verification_source']); ?></span></td>
                                <td>
                                    <span class="status-badge status-<?php echo esc_attr($t['status']); ?>">
                                        <?php echo esc_html(ucwords(str_replace('_', ' ', $t['status']))); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($t['transaction_time']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php self::renderFooter(); ?>
        </div>
        <?php
    }

    /**
     * SMS Logs View
     */
    public function renderSmsLogs(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'rpa_sms_logs';
        $logs = $wpdb->get_results("SELECT * FROM `{$table}` ORDER BY `id` DESC LIMIT 50", ARRAY_A) ?: [];

        ?>
        <div class="wrap royalpay-admin-wrap">
            <h1 class="wp-heading-inline">💬 <?php esc_html_e('Incoming SMS Logs', 'royalpay'); ?></h1>
            <hr class="wp-header-end">

            <div class="royalpay-card" style="margin-top:20px;">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:70px;"><?php esc_html_e('ID', 'royalpay'); ?></th>
                            <th style="width:120px;"><?php esc_html_e('Sender', 'royalpay'); ?></th>
                            <th><?php esc_html_e('SMS Body', 'royalpay'); ?></th>
                            <th style="width:140px;"><?php esc_html_e('Parsed TrxID', 'royalpay'); ?></th>
                            <th style="width:120px;"><?php esc_html_e('Parsed Amount', 'royalpay'); ?></th>
                            <th style="width:110px;"><?php esc_html_e('Status', 'royalpay'); ?></th>
                            <th style="width:160px;"><?php esc_html_e('Received At', 'royalpay'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr><td colspan="7" style="text-align:center; padding:20px;"><?php esc_html_e('No SMS messages ingested yet.', 'royalpay'); ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($logs as $l): ?>
                                <tr>
                                    <td>#<?php echo esc_html($l['id']); ?></td>
                                    <td>
                                        <span class="badge badge-<?php echo strtolower($l['sender']) === 'bkash' ? 'bkash' : (strtolower($l['sender']) === 'nagad' ? 'nagad' : 'rocket'); ?>">
                                            <?php echo esc_html($l['sender']); ?>
                                        </span>
                                    </td>
                                    <td style="font-size:12px; font-family:monospace;"><?php echo esc_html($l['body']); ?></td>
                                    <td><strong><?php echo esc_html($l['parsed_trxid'] ?: '—'); ?></strong></td>
                                    <td><?php echo $l['parsed_amount_minor'] ? esc_html(Security::fromMinorUnits($l['parsed_amount_minor'])) . ' BDT' : '—'; ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $l['parse_status'] === 'valid' ? 'matched' : 'duplicate'; ?>">
                                            <?php echo esc_html(ucfirst($l['parse_status'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo esc_html($l['received_at']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php self::renderFooter(); ?>
        </div>
        <?php
    }

    /**
     * RoyalAuth v2.0 License View
     */
    public function renderLicense(): void {
        $isLicensed = LicenseManager::getInstance()->isLicensed();
        $licenseKey = get_option('royalpay_license_key', '');
        $rawCache   = get_option('royalpay_license_cache', '');
        $data       = !empty($rawCache) ? json_decode($rawCache, true) : null;
        $licenseInfo = $data['license_data'] ?? [];

        ?>
        <div class="wrap royalpay-admin-wrap">
            <h1 class="wp-heading-inline">🔑 <?php esc_html_e('Royal Pay License Activation', 'royalpay'); ?></h1>
            <hr class="wp-header-end">
            <?php settings_errors('royalpay_notices'); ?>

            <div style="max-width:650px; margin-top:20px;">
                <div class="royalpay-card">
                    <h2><?php esc_html_e('RoyalAuth v2.0 License Status', 'royalpay'); ?></h2>
                    <p style="color:#6b7280; font-size:13px; margin-bottom:20px;">
                        <?php esc_html_e('Powered by RoyalTechBD Resilient Local-First Architecture with Ed25519 digital signature validation.', 'royalpay'); ?>
                    </p>

                    <div style="display:flex; align-items:center; gap:12px; padding:14px; border-radius:8px; margin-bottom:20px; background:<?php echo $isLicensed ? '#ecfdf5; border:1px solid #a7f3d0;' : '#fef2f2; border:1px solid #fecaca;'; ?>">
                        <div style="font-size:24px;"><?php echo $isLicensed ? '🛡️' : '⚠️'; ?></div>
                        <div>
                            <strong style="color:<?php echo $isLicensed ? '#065f46' : '#991b1b'; ?>; font-size:15px;">
                                <?php echo $isLicensed ? esc_html__('License Active & Verified', 'royalpay') : esc_html__('License Inactive / Not Activated', 'royalpay'); ?>
                            </strong>
                            <div style="font-size:12px; color:#4b5563; margin-top:2px;">
                                <?php if ($isLicensed && !empty($licenseInfo)): ?>
                                    Type: <strong><?php echo esc_html(ucfirst($licenseInfo['type'] ?? 'Lifetime')); ?></strong> | 
                                    Domain: <strong><?php echo esc_html($licenseInfo['domain'] ?? ''); ?></strong> | 
                                    Version: <strong>v<?php echo esc_html($licenseInfo['version'] ?? '1'); ?></strong>
                                <?php else: ?>
                                    <?php esc_html_e('Enter your license key provided by RoyalTechBD to activate.', 'royalpay'); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <form method="POST">
                        <?php wp_nonce_field('royalpay_license_action', 'royalpay_license_nonce'); ?>

                        <p>
                            <label style="display:block; font-weight:600; margin-bottom:6px;"><?php esc_html_e('RoyalTechBD License Key:', 'royalpay'); ?></label>
                            <input type="text" name="royalpay_license_key" value="<?php echo esc_attr($licenseKey); ?>" placeholder="e.g. RPA-XXXX-XXXX-XXXX" style="width:100%; height:42px; font-family:monospace; font-size:14px;" <?php echo $isLicensed ? 'readonly' : 'required'; ?> />
                        </p>

                        <div style="display:flex; gap:10px; margin-top:16px;">
                            <?php if ($isLicensed): ?>
                                <button type="submit" name="royalpay_deactivate_license" class="button button-secondary" onclick="return confirm('Deactivate license?');">
                                    <?php esc_html_e('Deactivate License', 'royalpay'); ?>
                                </button>
                                <button type="submit" name="royalpay_activate_license" class="button button-primary">
                                    🔄 <?php esc_html_e('Refresh / Re-Sync', 'royalpay'); ?>
                                </button>
                            <?php else: ?>
                                <button type="submit" name="royalpay_activate_license" class="button button-primary" style="height:40px; padding:0 24px;">
                                    <?php esc_html_e('Activate License', 'royalpay'); ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
            <?php self::renderFooter(); ?>
        </div>
        <?php
    }

    /**
     * Standard Company Footer for Admin Screens
     */
    public static function renderFooter(): void {
        ?>
        <div style="margin-top:40px; padding-top:20px; border-top:1px solid #e5e7eb; color:#6b7280; font-size:12px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
            <div>
                <strong>Royal Pay v<?php echo esc_html(ROYALPAY_VERSION); ?></strong> &bull; Developed by <a href="https://www.royaltechbd.com" target="_blank" style="color:#4f46e5; text-decoration:none; font-weight:600;">Royal Technologies</a>
            </div>
            <div>
                📞 Helpline: <strong>01552333272</strong> &bull; 🏢 Address: D - 408, Housing Estate, Kushtia &bull; 🌐 <a href="https://www.royaltechbd.com" target="_blank" style="color:#4f46e5; text-decoration:none;">www.royaltechbd.com</a>
            </div>
        </div>
        <?php
    }
}
