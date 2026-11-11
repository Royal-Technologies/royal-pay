<?php

namespace RoyalPay\Admin;

defined('ABSPATH') || exit;

use RoyalPay\License\LicenseManager;
use RoyalPay\Wallets\WalletManager;
use RoyalPay\Devices\DeviceManager;
use RoyalPay\Transactions\TransactionManager;
use RoyalPay\Core\Security;

/**
 * Admin Menus and Dashboard Controllers with Animated Header & SweetAlert2
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
        add_action('admin_init', [$this, 'checkLicenseRedirect']);
        add_action('wp_ajax_royalpay_activate_license', [$this, 'ajaxActivateLicense']);
    }

    /**
     * Redirect to license page if license is not active and user tries to access other Royal Pay pages
     */
    public function checkLicenseRedirect(): void {
        $is_licensed = LicenseManager::getInstance()->isLicensed();
        if ($is_licensed) {
            return;
        }

        $current_page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
        if (strpos($current_page, 'royalpay') === 0 && $current_page !== 'royalpay') {
            wp_safe_redirect(admin_url('admin.php?page=royalpay'));
            exit;
        }
    }

    public function registerMenus(): void {
        $is_licensed = LicenseManager::getInstance()->isLicensed();
        $cap = current_user_can('manage_woocommerce') ? 'manage_woocommerce' : 'manage_options';

        // 1. Main Menu (Parent)
        add_menu_page(
            __('Royal Pay', 'royalpay'),
            __('Royal Pay', 'royalpay'),
            $cap,
            'royalpay',
            $is_licensed ? [$this, 'renderDashboard'] : [$this, 'renderLicense'],
            'dashicons-money-alt',
            56
        );

        // If unlicensed: Show ONLY License page
        if (!$is_licensed) {
            add_submenu_page(
                'royalpay',
                __('License', 'royalpay'),
                __('License', 'royalpay'),
                $cap,
                'royalpay',
                [$this, 'renderLicense']
            );
            return;
        }

        // If licensed: Show all menus
        add_submenu_page(
            'royalpay',
            __('Dashboard', 'royalpay'),
            __('Dashboard', 'royalpay'),
            $cap,
            'royalpay',
            [$this, 'renderDashboard']
        );

        add_submenu_page(
            'royalpay',
            __('Wallets', 'royalpay'),
            __('Wallets', 'royalpay'),
            $cap,
            'royalpay-wallets',
            [$this, 'renderWallets']
        );

        add_submenu_page(
            'royalpay',
            __('Devices', 'royalpay'),
            __('Devices', 'royalpay'),
            $cap,
            'royalpay-devices',
            [$this, 'renderDevices']
        );

        add_submenu_page(
            'royalpay',
            __('Transactions', 'royalpay'),
            __('Transactions', 'royalpay'),
            $cap,
            'royalpay-transactions',
            [$this, 'renderTransactions']
        );

        add_submenu_page(
            'royalpay',
            __('SMS Logs', 'royalpay'),
            __('SMS Logs', 'royalpay'),
            $cap,
            'royalpay-sms-logs',
            [$this, 'renderSmsLogs']
        );

        add_submenu_page(
            'royalpay',
            __('Settings', 'royalpay'),
            __('Settings', 'royalpay'),
            $cap,
            'royalpay-settings',
            [$this, 'renderSettings']
        );

        add_submenu_page(
            'royalpay',
            __('License', 'royalpay'),
            __('License', 'royalpay'),
            $cap,
            'royalpay-license',
            [$this, 'renderLicense']
        );
    }

    /**
     * Reusable Animated Header (Royal Sentinel Pattern)
     */
    public static function renderHeader(string $icon, string $title, string $subtitle): void {
        ?>
        <div class="rp-page-header custom-gradient">
            <div class="rp-header-content">
                <h1 class="rp-page-title">
                    <div class="rp-process-icon"><?php echo $icon; ?></div>
                    <?php echo esc_html($title); ?>
                </h1>
                <p class="rp-page-subtitle"><?php echo esc_html($subtitle); ?></p>
            </div>
            <div style="position: absolute; top: 20px; right: 20px;">
                <span class="rp-version-badge">v<?php echo esc_html(ROYALPAY_VERSION); ?></span>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX License Activation with SweetAlert2
     */
    public function ajaxActivateLicense(): void {
        check_ajax_referer('royalpay_license_ajax_nonce', 'nonce');

        if (!current_user_can('manage_options') && !current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied.', 'royalpay')]);
        }

        $licenseKey = sanitize_text_field($_POST['license_key'] ?? '');
        $res = LicenseManager::getInstance()->activateLicense($licenseKey);

        if ($res['success']) {
            wp_send_json_success([
                'message'  => $res['message'],
                'redirect' => admin_url('admin.php?page=royalpay')
            ]);
        } else {
            wp_send_json_error([
                'message' => $res['message']
            ]);
        }
    }

    public function handleFormSubmissions(): void {
        if (!current_user_can('manage_options') && !current_user_can('manage_woocommerce')) {
            return;
        }

        // Handle License Deactivation
        if (isset($_POST['royalpay_deactivate_license'])) {
            check_admin_referer('royalpay_license_action', 'royalpay_license_nonce');
            LicenseManager::getInstance()->deactivateLicense();
            add_settings_error('royalpay_notices', 'license_deactivated', __('License deactivated successfully.', 'royalpay'), 'updated');
            wp_safe_redirect(admin_url('admin.php?page=royalpay'));
            exit;
        }

        // Handle Settings Save
        if (isset($_POST['royalpay_save_settings'])) {
            check_admin_referer('royalpay_settings_action', 'royalpay_settings_nonce');

            // 1. General & Brand Colors
            $primaryColor = sanitize_hex_color($_POST['royalpay_primary_color'] ?? '#4F46E5');
            $primaryDark  = sanitize_hex_color($_POST['royalpay_primary_dark'] ?? '#3730A3');
            $refPrefix    = sanitize_text_field($_POST['royalpay_ref_prefix'] ?? 'RP-');

            update_option('royalpay_primary_color', $primaryColor);
            update_option('royalpay_primary_dark', $primaryDark);
            update_option('royalpay_ref_prefix', $refPrefix);

            // 2. WooCommerce Integration
            $enableWc      = isset($_POST['royalpay_enable_woocommerce']) ? '1' : '0';
            $wcPaidStatus  = sanitize_text_field($_POST['royalpay_wc_paid_status'] ?? 'processing');
            $wcUnmatched   = sanitize_text_field($_POST['royalpay_wc_unmatched_status'] ?? 'on-hold');

            update_option('royalpay_enable_woocommerce', $enableWc);
            update_option('royalpay_wc_paid_status', $wcPaidStatus);
            update_option('royalpay_auto_status', $wcPaidStatus); // Backwards compatibility
            update_option('royalpay_wc_unmatched_status', $wcUnmatched);

            // 3. Royal BatchMate Integration
            $enableBm      = isset($_POST['royalpay_enable_batchmate']) ? '1' : '0';
            $bmPaidStatus  = sanitize_text_field($_POST['royalpay_batchmate_paid_status'] ?? 'approved');
            $bmAutoSerial  = isset($_POST['royalpay_batchmate_auto_serial']) ? '1' : '0';
            $bmSendEmail   = isset($_POST['royalpay_batchmate_send_email']) ? '1' : '0';

            update_option('royalpay_enable_batchmate', $enableBm);
            update_option('royalpay_batchmate_paid_status', $bmPaidStatus);
            update_option('royalpay_batchmate_auto_serial', $bmAutoSerial);
            update_option('royalpay_batchmate_send_email', $bmSendEmail);

            // 4. Royal Donation Integration
            $enableDonation     = isset($_POST['royalpay_enable_donation']) ? '1' : '0';
            $donationPaidStatus = sanitize_text_field($_POST['royalpay_donation_paid_status'] ?? 'completed');

            update_option('royalpay_enable_donation', $enableDonation);
            update_option('royalpay_donation_paid_status', $donationPaidStatus);

            // 5. External Webhook Integration
            $enableWebhook = isset($_POST['royalpay_enable_webhook']) ? '1' : '0';
            $webhookUrl    = esc_url_raw($_POST['royalpay_webhook_url'] ?? '');

            update_option('royalpay_enable_webhook', $enableWebhook);
            update_option('royalpay_webhook_url', $webhookUrl);

            add_settings_error('royalpay_notices', 'settings_saved', __('Settings saved successfully.', 'royalpay'), 'updated');
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

        $recentTxns = TransactionManager::getTransactions(['limit' => 20]);

        ?>
        <div class="wrap royalpay-admin-wrap">
            <?php self::renderHeader('📊', __('Payment Automation Dashboard', 'royalpay'), __('Real-time transaction intelligence and automated MFS verification.', 'royalpay')); ?>
            <?php settings_errors('royalpay_notices'); ?>

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
                    <a href="<?php echo esc_url(admin_url('admin.php?page=royalpay-transactions')); ?>" class="button button-primary"><?php esc_html_e('View All in DataTables', 'royalpay'); ?></a>
                </div>

                <table class="wp-list-table widefat fixed striped royalpay-datatable">
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
                        <?php if (!empty($recentTxns)): ?>
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
            <?php self::renderHeader('💼', __('MFS Wallets Configuration', 'royalpay'), __('Configure bKash, Nagad, and Rocket numbers and accounts.', 'royalpay')); ?>
            <?php settings_errors('royalpay_notices'); ?>

            <div style="display:grid; grid-template-columns: 2fr 1fr; gap:24px; margin-top:20px;">
                <div class="royalpay-card">
                    <h2><?php esc_html_e('Active Numbers & Accounts', 'royalpay'); ?></h2>
                    <table class="wp-list-table widefat fixed striped royalpay-datatable">
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
                            <?php if (!empty($wallets)): ?>
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
                                            <a href="<?php echo esc_url($delUrl); ?>" class="rp-delete-wallet-btn" style="color:#ef4444; font-weight:600; text-decoration:none;"><?php esc_html_e('Delete', 'royalpay'); ?></a>
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
                            <button type="submit" name="royalpay_save_wallet" class="button button-primary" style="width:100%; height:42px;">
                                <?php esc_html_e('Save Wallet', 'royalpay'); ?>
                            </button>
                        </p>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Devices View
     */
    public function renderDevices(): void {
        $devices = DeviceManager::getDevices();
        $pairing = DeviceManager::generatePairingPayload();
        $apkUrl = ROYALPAY_PLUGIN_URL . 'apk/royal-pay-agent.apk';
        ?>
        <div class="wrap royalpay-admin-wrap">
            <?php self::renderHeader('📱', __('Android Agent Devices', 'royalpay'), __('Manage connected smartphones running Royal Pay Agent APK.', 'royalpay')); ?>
            <?php settings_errors('royalpay_notices'); ?>

            <div style="display:flex; justify-content:flex-end; margin-bottom:16px;">
                <a href="<?php echo esc_url($apkUrl); ?>" class="button-download-apk" download>
                    📥 <?php esc_html_e('Download Royal Pay Agent (APK)', 'royalpay'); ?>
                </a>
            </div>

            <div style="display:grid; grid-template-columns: 2fr 1fr; gap:24px;">
                <div class="royalpay-card">
                    <h2><?php esc_html_e('Connected Android Agents', 'royalpay'); ?></h2>
                    <table class="wp-list-table widefat fixed striped royalpay-datatable">
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
                            <?php if (!empty($devices)): ?>
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
                                            <a href="<?php echo esc_url($revUrl); ?>" class="rp-revoke-device-btn" style="color:#ef4444; font-weight:600; text-decoration:none;"><?php esc_html_e('Revoke', 'royalpay'); ?></a>
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

                    <div style="background:#fff; padding:12px; display:inline-block; border-radius:12px; border:1px solid #e5e7eb; box-shadow:0 2px 8px rgba(0,0,0,0.08);">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?php echo urlencode($pairing['qr_payload']); ?>" alt="Pairing QR Code" style="width:200px; height:200px; display:block;" />
                    </div>

                    <div style="margin-top:14px; text-align:left; background:#f9fafb; padding:12px; border-radius:8px; font-size:12px; word-break:break-all;">
                        <strong><?php esc_html_e('API URL:', 'royalpay'); ?></strong><br>
                        <code><?php echo esc_html($pairing['api_url']); ?></code><br><br>
                        <strong><?php esc_html_e('Pairing Token:', 'royalpay'); ?></strong><br>
                        <code><?php echo esc_html($pairing['pairing_token']); ?></code>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Transactions Ledger View
     */
    public function renderTransactions(): void {
        $transactions = TransactionManager::getTransactions(['limit' => 200]);
        ?>
        <div class="wrap royalpay-admin-wrap">
            <?php self::renderHeader('📜', __('Transactions Ledger', 'royalpay'), __('Complete ledger of ingested transactions with DataTables search and filter.', 'royalpay')); ?>
            <?php settings_errors('royalpay_notices'); ?>

            <div class="royalpay-card" style="margin-top:20px;">
                <table class="wp-list-table widefat fixed striped royalpay-datatable">
                    <thead>
                        <tr>
                            <th style="width:50px;"><?php esc_html_e('ID', 'royalpay'); ?></th>
                            <th style="width:80px;"><?php esc_html_e('Provider', 'royalpay'); ?></th>
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
                        <?php if (!empty($transactions)): ?>
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
            </div>
        </div>
        <?php
    }

    /**
     * SMS Logs View
     */
    public function renderSmsLogs(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'rpa_sms_logs';
        $logs = $wpdb->get_results("SELECT * FROM `{$table}` ORDER BY `id` DESC LIMIT 200", ARRAY_A) ?: [];
        ?>
        <div class="wrap royalpay-admin-wrap">
            <?php self::renderHeader('💬', __('Incoming SMS Logs', 'royalpay'), __('Raw telecom messages captured by Android Agent with parser audit.', 'royalpay')); ?>
            <?php settings_errors('royalpay_notices'); ?>

            <div class="royalpay-card" style="margin-top:20px;">
                <table class="wp-list-table widefat fixed striped royalpay-datatable">
                    <thead>
                        <tr>
                            <th style="width:60px;"><?php esc_html_e('ID', 'royalpay'); ?></th>
                            <th style="width:100px;"><?php esc_html_e('Sender', 'royalpay'); ?></th>
                            <th><?php esc_html_e('SMS Body', 'royalpay'); ?></th>
                            <th style="width:130px;"><?php esc_html_e('Parsed TrxID', 'royalpay'); ?></th>
                            <th style="width:110px;"><?php esc_html_e('Parsed Amount', 'royalpay'); ?></th>
                            <th style="width:90px;"><?php esc_html_e('Status', 'royalpay'); ?></th>
                            <th style="width:140px;"><?php esc_html_e('Received At', 'royalpay'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($logs)): ?>
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
        </div>
        <?php
    }

    /**
     * Settings View with Instant Tabs: General, Integrations & Plugins, Theme & Colors, About
     */
    public function renderSettings(): void {
        $primaryColor       = get_option('royalpay_primary_color', '#4F46E5');
        $primaryDark        = get_option('royalpay_primary_dark', '#3730A3');
        $refPrefix          = get_option('royalpay_ref_prefix', 'RP-');

        // WooCommerce Options
        $enableWc           = get_option('royalpay_enable_woocommerce', '1');
        $wcPaidStatus       = get_option('royalpay_wc_paid_status', get_option('royalpay_auto_status', 'processing'));
        $wcUnmatchedStatus  = get_option('royalpay_wc_unmatched_status', 'on-hold');
        $isWcActive         = class_exists('WooCommerce');

        // Royal BatchMate Options
        $enableBatchmate    = get_option('royalpay_enable_batchmate', '1');
        $bmPaidStatus       = get_option('royalpay_batchmate_paid_status', 'approved');
        $bmAutoSerial       = get_option('royalpay_batchmate_auto_serial', '1');
        $bmSendEmail        = get_option('royalpay_batchmate_send_email', '1');
        $isBatchmateActive  = \RoyalPay\Compatibility\RoyalBatchmateAdapter::isBatchmateActive();

        // Royal Donation Options
        $enableDonation     = get_option('royalpay_enable_donation', '1');
        $donationPaidStatus = get_option('royalpay_donation_paid_status', 'completed');
        $isDonationActive   = defined('ROYAL_DONATION_VERSION') || file_exists(WP_PLUGIN_DIR . '/royal-donation/royal-donation.php');

        // External Webhook Options
        $enableWebhook      = get_option('royalpay_enable_webhook', '0');
        $webhookUrl         = get_option('royalpay_webhook_url', '');

        $isLicensed         = LicenseManager::getInstance()->isLicensed();
        $rawCache           = get_option('royalpay_license_cache', '');
        $licenseData        = !empty($rawCache) ? json_decode($rawCache, true) : null;
        ?>
        <div class="wrap royalpay-admin-wrap">
            <?php self::renderHeader('⚙️', __('Royal Pay Settings & Configuration', 'royalpay'), __('Manage supported plugins, paid order statuses, theme colors, and gateways.', 'royalpay')); ?>
            <?php settings_errors('royalpay_notices'); ?>

            <div class="rp-settings-container" style="margin-top:24px;">
                <form method="POST">
                    <?php wp_nonce_field('royalpay_settings_action', 'royalpay_settings_nonce'); ?>

                    <!-- Segmented Modern Pill Tabs -->
                    <div class="rp-modern-tabs-wrap">
                        <button type="button" class="rp-tab-btn active" data-tab="tab-general">
                            <span>⚙️</span> <?php esc_html_e('General Settings', 'royalpay'); ?>
                        </button>
                        <button type="button" class="rp-tab-btn" data-tab="tab-integrations">
                            <span>🔌</span> <?php esc_html_e('Supported Plugins & Paid Status', 'royalpay'); ?>
                        </button>
                        <button type="button" class="rp-tab-btn" data-tab="tab-colors">
                            <span>🎨</span> <?php esc_html_e('Theme & Brand Colors', 'royalpay'); ?>
                        </button>
                        <button type="button" class="rp-tab-btn" data-tab="tab-about">
                            <span>🏢</span> <?php esc_html_e('About Royal Technologies', 'royalpay'); ?>
                        </button>
                    </div>

                    <!-- ==============================================
                         TAB 1: General Settings
                         ============================================== -->
                    <div id="tab-general" class="rp-tab-pane active">
                        <div class="rp-settings-section">
                            <div class="rp-section-header">
                                <div class="rp-section-icon">💼</div>
                                <div class="rp-section-title-wrap">
                                    <h3><?php esc_html_e('Payment Processing & Order Reference', 'royalpay'); ?></h3>
                                    <p><?php esc_html_e('Configure checkout invoice prefix and core financial parameters.', 'royalpay'); ?></p>
                                </div>
                            </div>

                            <div class="rp-form-grid">
                                <div class="rp-form-group">
                                    <label class="rp-form-label" for="royalpay_ref_prefix">
                                        <?php esc_html_e('Order Reference Prefix', 'royalpay'); ?>
                                    </label>
                                    <input type="text" name="royalpay_ref_prefix" id="royalpay_ref_prefix" value="<?php echo esc_attr($refPrefix); ?>" class="rp-input" placeholder="e.g. RP-" required />
                                    <p class="rp-form-desc">
                                        <?php esc_html_e('Prefix used when customers enter order reference during payment (e.g. RP-10492).', 'royalpay'); ?>
                                    </p>
                                </div>

                                <div class="rp-form-group">
                                    <label class="rp-form-label">
                                        <?php esc_html_e('Operating Currency', 'royalpay'); ?>
                                    </label>
                                    <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:9px; padding:10px 14px; font-weight:600; color:#0f172a; height:42px; display:flex; align-items:center; gap:8px;">
                                        <span style="color:#059669; font-size:16px;">৳</span> BDT (Bangladeshi Taka)
                                    </div>
                                    <p class="rp-form-desc">
                                        <?php esc_html_e('Calculated in exact minor units (1 BDT = 100 minor units) to prevent floating-point rounding errors.', 'royalpay'); ?>
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="rp-settings-section">
                            <div class="rp-section-header">
                                <div class="rp-section-icon">🛡️</div>
                                <div class="rp-section-title-wrap">
                                    <h3><?php esc_html_e('Anti-Fraud & Ingestion Policy', 'royalpay'); ?></h3>
                                    <p><?php esc_html_e('Hardware and telecom-grade security filters built into Royal Pay.', 'royalpay'); ?></p>
                                </div>
                            </div>

                            <div style="display:flex; flex-direction:column; gap:14px;">
                                <div style="display:flex; align-items:center; justify-content:space-between; background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:16px 20px;">
                                    <div>
                                        <strong style="color:#0f172a; font-size:14px; display:block;">
                                            <?php esc_html_e('Strict Telecom Sender Whitelist', 'royalpay'); ?>
                                        </strong>
                                        <span style="color:#64748b; font-size:13px;">
                                            <?php esc_html_e('Only accepts verified SMS originating from official shortcodes: 16247, 16167, 16216, bKash, Nagad, Rocket.', 'royalpay'); ?>
                                        </span>
                                    </div>
                                    <span class="rp-badge-active" style="padding:4px 12px; font-size:12px;">Active & Enforced</span>
                                </div>

                                <div style="display:flex; align-items:center; justify-content:space-between; background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:16px 20px;">
                                    <div>
                                        <strong style="color:#0f172a; font-size:14px; display:block;">
                                            <?php esc_html_e('Zero Amount-Only Approvals', 'royalpay'); ?>
                                        </strong>
                                        <span style="color:#64748b; font-size:13px;">
                                            <?php esc_html_e('Every transaction requires an exact Transaction ID (TrxID) or valid reference match before unlocking orders.', 'royalpay'); ?>
                                        </span>
                                    </div>
                                    <span class="rp-badge-active" style="padding:4px 12px; font-size:12px;">Active & Enforced</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ==============================================
                         TAB 2: Supported Plugins & Paid Status
                         ============================================== -->
                    <div id="tab-integrations" class="rp-tab-pane">
                        <div style="margin-bottom:24px;">
                            <h3 style="margin:0; font-size:17px; font-weight:700; color:#0f172a;">
                                <?php esc_html_e('Supported Plugin Integrations', 'royalpay'); ?>
                            </h3>
                            <p style="margin:4px 0 0; color:#64748b; font-size:13.5px;">
                                <?php esc_html_e('Select which plugins Royal Pay should automatically verify payments for, and configure the Paid Order Status for each.', 'royalpay'); ?>
                            </p>
                        </div>

                        <!-- Card 1: WooCommerce -->
                        <div class="rp-integration-card">
                            <div class="rp-integration-header">
                                <div class="rp-integration-info">
                                    <div class="rp-integration-icon" style="background:#f5f3ff; border-color:#ddd6fe; color:#7c3aed;">
                                        🛒
                                    </div>
                                    <div>
                                        <h3 style="margin:0; font-size:16px; color:#0f172a; display:flex; align-items:center; gap:8px;">
                                            WooCommerce Store
                                            <?php if ($isWcActive): ?>
                                                <span class="rp-badge-active">🟢 <?php esc_html_e('Active & Connected', 'royalpay'); ?></span>
                                            <?php else: ?>
                                                <span class="rp-badge-inactive">⚪ <?php esc_html_e('Not Installed / Inactive', 'royalpay'); ?></span>
                                            <?php endif; ?>
                                        </h3>
                                        <p style="margin:2px 0 0; color:#64748b; font-size:13px;">
                                            <?php esc_html_e('Automated checkout gateway & instant order verification for WooCommerce e-commerce stores.', 'royalpay'); ?>
                                        </p>
                                    </div>
                                </div>
                                <div>
                                    <label class="rp-switch" title="<?php esc_attr_e('Enable or Disable WooCommerce Integration', 'royalpay'); ?>">
                                        <input type="checkbox" name="royalpay_enable_woocommerce" value="1" <?php checked($enableWc, '1'); ?> />
                                        <span class="rp-slider"></span>
                                    </label>
                                </div>
                            </div>

                            <div class="rp-form-grid" style="margin-top:16px;">
                                <div class="rp-form-group">
                                    <label class="rp-form-label" for="royalpay_wc_paid_status">
                                        <?php esc_html_e('Paid Order Status (পেমেন্ট নিশ্চিত হলে স্ট্যাটাস)', 'royalpay'); ?> <span style="color:#ef4444;">*</span>
                                    </label>
                                    <select name="royalpay_wc_paid_status" id="royalpay_wc_paid_status" class="rp-select">
                                        <?php
                                        $cleanWcStatus = str_replace('wc-', '', $wcPaidStatus);
                                        if (function_exists('wc_get_order_statuses')) {
                                            $wcStatuses = wc_get_order_statuses();
                                            foreach ($wcStatuses as $statusKey => $statusLabel) {
                                                $val = str_replace('wc-', '', $statusKey);
                                                echo '<option value="' . esc_attr($val) . '" ' . selected($cleanWcStatus, $val, false) . '>' . esc_html($statusLabel) . '</option>';
                                            }
                                        } else {
                                            echo '<option value="processing" ' . selected($cleanWcStatus, 'processing', false) . '>' . esc_html__('Processing (Default for physical goods)', 'royalpay') . '</option>';
                                            echo '<option value="completed" ' . selected($cleanWcStatus, 'completed', false) . '>' . esc_html__('Completed (For instant digital downloads / events)', 'royalpay') . '</option>';
                                            echo '<option value="on-hold" ' . selected($cleanWcStatus, 'on-hold', false) . '>' . esc_html__('On-hold (Manual review required)', 'royalpay') . '</option>';
                                        }
                                        ?>
                                    </select>
                                    <p class="rp-form-desc">
                                        <?php esc_html_e('Status to assign to WooCommerce orders immediately after customer TrxID and amount are matched.', 'royalpay'); ?>
                                    </p>
                                </div>

                                <div class="rp-form-group">
                                    <label class="rp-form-label" for="royalpay_wc_unmatched_status">
                                        <?php esc_html_e('Amount Mismatch / Review Status', 'royalpay'); ?>
                                    </label>
                                    <select name="royalpay_wc_unmatched_status" id="royalpay_wc_unmatched_status" class="rp-select">
                                        <option value="on-hold" <?php selected($wcUnmatchedStatus, 'on-hold'); ?>><?php esc_html_e('On-hold (Hold order for merchant review)', 'royalpay'); ?></option>
                                        <option value="pending" <?php selected($wcUnmatchedStatus, 'pending'); ?>><?php esc_html_e('Pending Payment', 'royalpay'); ?></option>
                                        <option value="failed" <?php selected($wcUnmatchedStatus, 'failed'); ?>><?php esc_html_e('Failed', 'royalpay'); ?></option>
                                    </select>
                                    <p class="rp-form-desc">
                                        <?php esc_html_e('Assigned when customer enters a valid TrxID but paid amount is lower than the order total.', 'royalpay'); ?>
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Card 2: Royal BatchMate -->
                        <div class="rp-integration-card">
                            <div class="rp-integration-header">
                                <div class="rp-integration-info">
                                    <div class="rp-integration-icon" style="background:#eff6ff; border-color:#bfdbfe; color:#2563eb;">
                                        🎓
                                    </div>
                                    <div>
                                        <h3 style="margin:0; font-size:16px; color:#0f172a; display:flex; align-items:center; gap:8px;">
                                            Royal BatchMate (Alumni / Events / Memberships)
                                            <?php if ($isBatchmateActive): ?>
                                                <span class="rp-badge-active">🟢 <?php esc_html_e('Active & Connected', 'royalpay'); ?></span>
                                            <?php else: ?>
                                                <span class="rp-badge-inactive">⚪ <?php esc_html_e('Not Installed / Inactive', 'royalpay'); ?></span>
                                            <?php endif; ?>
                                        </h3>
                                        <p style="margin:2px 0 0; color:#64748b; font-size:13px;">
                                            <?php esc_html_e('Automatic event ticket and registration fee verification with instant approval and serial number issuance.', 'royalpay'); ?>
                                        </p>
                                    </div>
                                </div>
                                <div>
                                    <label class="rp-switch" title="<?php esc_attr_e('Enable or Disable Royal BatchMate Integration', 'royalpay'); ?>">
                                        <input type="checkbox" name="royalpay_enable_batchmate" value="1" <?php checked($enableBatchmate, '1'); ?> />
                                        <span class="rp-slider"></span>
                                    </label>
                                </div>
                            </div>

                            <div class="rp-form-grid" style="margin-top:16px;">
                                <div class="rp-form-group">
                                    <label class="rp-form-label" for="royalpay_batchmate_paid_status">
                                        <?php esc_html_e('Paid Application Status', 'royalpay'); ?> <span style="color:#ef4444;">*</span>
                                    </label>
                                    <select name="royalpay_batchmate_paid_status" id="royalpay_batchmate_paid_status" class="rp-select">
                                        <option value="approved" <?php selected($bmPaidStatus, 'approved'); ?>><?php esc_html_e('Approved (Default - Automatically confirms applicant)', 'royalpay'); ?></option>
                                        <option value="confirmed" <?php selected($bmPaidStatus, 'confirmed'); ?>><?php esc_html_e('Confirmed', 'royalpay'); ?></option>
                                        <option value="verified" <?php selected($bmPaidStatus, 'verified'); ?>><?php esc_html_e('Verified', 'royalpay'); ?></option>
                                    </select>
                                    <p class="rp-form-desc">
                                        <?php esc_html_e('Status to assign to event applications upon successful MFS verification.', 'royalpay'); ?>
                                    </p>
                                </div>

                                <div class="rp-form-group" style="justify-content:center;">
                                    <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer; font-weight:600; font-size:13.5px; color:#1e293b; margin-bottom:8px;">
                                        <input type="checkbox" name="royalpay_batchmate_auto_serial" value="1" <?php checked($bmAutoSerial, '1'); ?> style="margin-top:2px;" />
                                        <span>
                                            <?php esc_html_e('Auto Issue Serial Number', 'royalpay'); ?>
                                            <span style="display:block; font-weight:400; font-size:12px; color:#64748b; margin-top:2px;">
                                                <?php esc_html_e('Calculates next sequential serial number and inserts row into event_members table.', 'royalpay'); ?>
                                            </span>
                                        </span>
                                    </label>

                                    <label style="display:flex; align-items:flex-start; gap:10px; cursor:pointer; font-weight:600; font-size:13.5px; color:#1e293b;">
                                        <input type="checkbox" name="royalpay_batchmate_send_email" value="1" <?php checked($bmSendEmail, '1'); ?> style="margin-top:2px;" />
                                        <span>
                                            <?php esc_html_e('Send Confirmation Email', 'royalpay'); ?>
                                            <span style="display:block; font-weight:400; font-size:12px; color:#64748b; margin-top:2px;">
                                                <?php esc_html_e('Automatically dispatch official confirmation email with ticket details to applicant.', 'royalpay'); ?>
                                            </span>
                                        </span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Card 3: Royal Donation -->
                        <div class="rp-integration-card">
                            <div class="rp-integration-header">
                                <div class="rp-integration-info">
                                    <div class="rp-integration-icon" style="background:#fff1f2; border-color:#fecdd3; color:#e11d48;">
                                        ❤️
                                    </div>
                                    <div>
                                        <h3 style="margin:0; font-size:16px; color:#0f172a; display:flex; align-items:center; gap:8px;">
                                            Royal Donation (Charity & Fund Collection)
                                            <?php if ($isDonationActive): ?>
                                                <span class="rp-badge-active">🟢 <?php esc_html_e('Installed', 'royalpay'); ?></span>
                                            <?php else: ?>
                                                <span class="rp-badge-inactive">⚪ <?php esc_html_e('Available Integration', 'royalpay'); ?></span>
                                            <?php endif; ?>
                                        </h3>
                                        <p style="margin:2px 0 0; color:#64748b; font-size:13px;">
                                            <?php esc_html_e('Automatic verification and donor receipt confirmation for charitable contributions and funds.', 'royalpay'); ?>
                                        </p>
                                    </div>
                                </div>
                                <div>
                                    <label class="rp-switch" title="<?php esc_attr_e('Enable or Disable Royal Donation Integration', 'royalpay'); ?>">
                                        <input type="checkbox" name="royalpay_enable_donation" value="1" <?php checked($enableDonation, '1'); ?> />
                                        <span class="rp-slider"></span>
                                    </label>
                                </div>
                            </div>

                            <div class="rp-form-grid" style="margin-top:16px;">
                                <div class="rp-form-group">
                                    <label class="rp-form-label" for="royalpay_donation_paid_status">
                                        <?php esc_html_e('Paid Donation Status', 'royalpay'); ?>
                                    </label>
                                    <select name="royalpay_donation_paid_status" id="royalpay_donation_paid_status" class="rp-select" style="max-width:320px;">
                                        <option value="completed" <?php selected($donationPaidStatus, 'completed'); ?>><?php esc_html_e('Completed (Default)', 'royalpay'); ?></option>
                                        <option value="verified" <?php selected($donationPaidStatus, 'verified'); ?>><?php esc_html_e('Verified', 'royalpay'); ?></option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Card 4: Webhook Notification -->
                        <div class="rp-integration-card">
                            <div class="rp-integration-header">
                                <div class="rp-integration-info">
                                    <div class="rp-integration-icon" style="background:#ecfdf5; border-color:#a7f3d0; color:#059669;">
                                        🌐
                                    </div>
                                    <div>
                                        <h3 style="margin:0; font-size:16px; color:#0f172a; display:flex; align-items:center; gap:8px;">
                                            External Webhook / REST Notification
                                        </h3>
                                        <p style="margin:2px 0 0; color:#64748b; font-size:13px;">
                                            <?php esc_html_e('Dispatch instant JSON payloads to your external CRM, ERP, or web app when transactions are verified.', 'royalpay'); ?>
                                        </p>
                                    </div>
                                </div>
                                <div>
                                    <label class="rp-switch" title="<?php esc_attr_e('Enable or Disable Webhook', 'royalpay'); ?>">
                                        <input type="checkbox" name="royalpay_enable_webhook" value="1" <?php checked($enableWebhook, '1'); ?> />
                                        <span class="rp-slider"></span>
                                    </label>
                                </div>
                            </div>

                            <div class="rp-form-group" style="margin-top:16px;">
                                <label class="rp-form-label" for="royalpay_webhook_url">
                                    <?php esc_html_e('Webhook Endpoint URL', 'royalpay'); ?>
                                </label>
                                <input type="url" name="royalpay_webhook_url" id="royalpay_webhook_url" value="<?php echo esc_attr($webhookUrl); ?>" placeholder="https://your-crm.com/api/royalpay-webhook" class="rp-input" />
                                <p class="rp-form-desc">
                                    <?php esc_html_e('Webhook receives POST request containing Transaction ID, Provider, Amount, Order ID, and Timestamp upon match.', 'royalpay'); ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- ==============================================
                         TAB 3: Theme & Brand Colors
                         ============================================== -->
                    <div id="tab-colors" class="rp-tab-pane">
                        <div class="rp-settings-section">
                            <div class="rp-section-header">
                                <div class="rp-section-icon">🎨</div>
                                <div class="rp-section-title-wrap">
                                    <h3><?php esc_html_e('Interactive Brand Theme Palettes', 'royalpay'); ?></h3>
                                    <p><?php esc_html_e('Select any designer palette below to instantly style the animated header, action buttons, and badges.', 'royalpay'); ?></p>
                                </div>
                            </div>

                            <div class="rp-palette-grid">
                                <div class="rp-palette-card <?php echo ($primaryColor === '#4F46E5') ? 'active' : ''; ?>" data-primary="#4F46E5" data-dark="#3730A3">
                                    <div class="rp-palette-preview" style="background:linear-gradient(135deg, #4F46E5 0%, #3730A3 100%);"></div>
                                    <div class="rp-palette-details">
                                        <span class="rp-palette-name">Royal Indigo</span>
                                        <span class="rp-palette-check">✔</span>
                                    </div>
                                </div>

                                <div class="rp-palette-card <?php echo ($primaryColor === '#667EEA') ? 'active' : ''; ?>" data-primary="#667EEA" data-dark="#764BA2">
                                    <div class="rp-palette-preview" style="background:linear-gradient(135deg, #667EEA 0%, #764BA2 100%);"></div>
                                    <div class="rp-palette-details">
                                        <span class="rp-palette-name">Sentinel Purple</span>
                                        <span class="rp-palette-check">✔</span>
                                    </div>
                                </div>

                                <div class="rp-palette-card <?php echo ($primaryColor === '#10B981') ? 'active' : ''; ?>" data-primary="#10B981" data-dark="#059669">
                                    <div class="rp-palette-preview" style="background:linear-gradient(135deg, #10B981 0%, #059669 100%);"></div>
                                    <div class="rp-palette-details">
                                        <span class="rp-palette-name">Emerald Pay</span>
                                        <span class="rp-palette-check">✔</span>
                                    </div>
                                </div>

                                <div class="rp-palette-card <?php echo ($primaryColor === '#E11D48') ? 'active' : ''; ?>" data-primary="#E11D48" data-dark="#9F1239">
                                    <div class="rp-palette-preview" style="background:linear-gradient(135deg, #E11D48 0%, #9F1239 100%);"></div>
                                    <div class="rp-palette-details">
                                        <span class="rp-palette-name">Crimson Rose</span>
                                        <span class="rp-palette-check">✔</span>
                                    </div>
                                </div>

                                <div class="rp-palette-card <?php echo ($primaryColor === '#2C3E50') ? 'active' : ''; ?>" data-primary="#2C3E50" data-dark="#1A252F">
                                    <div class="rp-palette-preview" style="background:linear-gradient(135deg, #2C3E50 0%, #1A252F 100%);"></div>
                                    <div class="rp-palette-details">
                                        <span class="rp-palette-name">Cyber Slate</span>
                                        <span class="rp-palette-check">✔</span>
                                    </div>
                                </div>

                                <div class="rp-palette-card <?php echo ($primaryColor === '#D97706') ? 'active' : ''; ?>" data-primary="#D97706" data-dark="#B45309">
                                    <div class="rp-palette-preview" style="background:linear-gradient(135deg, #D97706 0%, #B45309 100%);"></div>
                                    <div class="rp-palette-details">
                                        <span class="rp-palette-name">Imperial Amber</span>
                                        <span class="rp-palette-check">✔</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="rp-settings-section">
                            <div class="rp-section-header">
                                <div class="rp-section-icon">🖌️</div>
                                <div class="rp-section-title-wrap">
                                    <h3><?php esc_html_e('Custom Hex Color Codes', 'royalpay'); ?></h3>
                                    <p><?php esc_html_e('Specify custom branding colors for the gradient headers and elements.', 'royalpay'); ?></p>
                                </div>
                            </div>

                            <div class="rp-form-grid">
                                <div class="rp-form-group">
                                    <label class="rp-form-label" for="royalpay_primary_color">
                                        <?php esc_html_e('Primary Brand Color', 'royalpay'); ?>
                                    </label>
                                    <input type="text" name="royalpay_primary_color" id="royalpay_primary_color" value="<?php echo esc_attr($primaryColor); ?>" class="rp-color-picker" />
                                </div>

                                <div class="rp-form-group">
                                    <label class="rp-form-label" for="royalpay_primary_dark">
                                        <?php esc_html_e('Gradient Dark Accent Color', 'royalpay'); ?>
                                    </label>
                                    <input type="text" name="royalpay_primary_dark" id="royalpay_primary_dark" value="<?php echo esc_attr($primaryDark); ?>" class="rp-color-picker" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ==============================================
                         TAB 4: About Company & System Info
                         ============================================== -->
                    <div id="tab-about" class="rp-tab-pane">
                        <div class="rp-about-hero">
                            <div class="rp-about-hero-content">
                                <div class="rp-about-avatar">👑</div>
                                <div>
                                    <h2>Royal Technologies</h2>
                                    <p>Architects & Publishers of Royal Pay Automation Suite</p>
                                </div>
                            </div>
                            <div style="display:flex; align-items:center; gap:10px;">
                                <span class="rp-version-badge" style="background:rgba(255,255,255,0.15); border-color:rgba(255,255,255,0.25);">
                                    v<?php echo esc_html(ROYALPAY_VERSION); ?>
                                </span>
                                <span class="rp-version-badge" style="background:#10b981; border-color:#059669; color:#ffffff;">
                                    <?php echo $isLicensed ? '🛡️ RoyalAuth Active' : '⚠️ Unlicensed'; ?>
                                </span>
                            </div>
                        </div>

                        <div class="rp-about-grid">
                            <div class="rp-about-card">
                                <div class="rp-about-card-icon">📞</div>
                                <h4><?php esc_html_e('Support Helpline', 'royalpay'); ?></h4>
                                <p style="font-size:16px; font-weight:700; color:#0f172a; margin-bottom:8px;">
                                    <a href="tel:01552333272" style="color:#0f172a; text-decoration:none;">01552333272</a>
                                </p>
                                <p style="font-size:12.5px; color:#64748b;">
                                    <?php esc_html_e('Direct phone & WhatsApp assistance for merchant setup & technical verification.', 'royalpay'); ?>
                                </p>
                            </div>

                            <div class="rp-about-card">
                                <div class="rp-about-card-icon">🏢</div>
                                <h4><?php esc_html_e('Registered Office', 'royalpay'); ?></h4>
                                <p style="font-weight:600; color:#0f172a; margin-bottom:8px;">
                                    D - 408, Housing Estate, Kushtia
                                </p>
                                <p style="font-size:12.5px; color:#64748b;">
                                    <?php esc_html_e('Official engineering and customer support facility in Bangladesh.', 'royalpay'); ?>
                                </p>
                            </div>

                            <div class="rp-about-card">
                                <div class="rp-about-card-icon">🌐</div>
                                <h4><?php esc_html_e('Official Portal', 'royalpay'); ?></h4>
                                <p style="font-weight:600; margin-bottom:8px;">
                                    <a href="https://www.royaltechbd.com" target="_blank" style="color:var(--rp-primary); text-decoration:none;">
                                        www.royaltechbd.com ↗
                                    </a>
                                </p>
                                <p style="font-size:12.5px; color:#64748b;">
                                    <?php esc_html_e('Product documentation, knowledge base, release notes, and downloads.', 'royalpay'); ?>
                                </p>
                            </div>

                            <div class="rp-about-card">
                                <div class="rp-about-card-icon">🛡️</div>
                                <h4><?php esc_html_e('RoyalAuth v2.0 Security', 'royalpay'); ?></h4>
                                <p style="font-weight:600; color:#059669; margin-bottom:8px;">
                                    <?php echo $isLicensed ? esc_html__('Ed25519 Cryptographic Verification Active', 'royalpay') : esc_html__('License Inactive', 'royalpay'); ?>
                                </p>
                                <p style="font-size:12.5px; color:#64748b;">
                                    <?php esc_html_e('Local-first tamper-proof verification resilient against server downtime.', 'royalpay'); ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Modern Bottom Save Bar -->
                    <div class="rp-save-bar">
                        <div style="color:#64748b; font-size:13px;">
                            <?php esc_html_e('Changes take effect immediately across all checkout gateways and automation hooks.', 'royalpay'); ?>
                        </div>
                        <button type="submit" name="royalpay_save_settings" class="rp-btn-save">
                            <span>💾</span> <?php esc_html_e('Save Changes', 'royalpay'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * RoyalAuth v2.0 License View with SweetAlert2 & Disabled Button during Activation
     */
    public function renderLicense(): void {
        $isLicensed  = LicenseManager::getInstance()->isLicensed();
        $licenseKey  = get_option('royalpay_license_key', '');
        $rawCache    = get_option('royalpay_license_cache', '');
        $data        = !empty($rawCache) ? json_decode($rawCache, true) : null;
        $licenseInfo = $data['license_data'] ?? [];
        ?>
        <div class="wrap royalpay-admin-wrap">
            <?php self::renderHeader('🔑', __('Royal Pay License Activation', 'royalpay'), __('RoyalTechBD Resilient Local-First Architecture with Ed25519 signature verification.', 'royalpay')); ?>
            <?php settings_errors('royalpay_notices'); ?>

            <div style="max-width:680px; margin:20px auto;">
                <div class="royalpay-card">
                    <h2 style="margin-top:0;"><?php esc_html_e('RoyalAuth v2.0 License Status', 'royalpay'); ?></h2>
                    <p style="color:#6b7280; font-size:13px; margin-bottom:20px;">
                        <?php esc_html_e('Verify your license key issued by Royal Technologies to enable automated payment verification.', 'royalpay'); ?>
                    </p>

                    <div style="display:flex; align-items:center; gap:14px; padding:16px; border-radius:10px; margin-bottom:24px; background:<?php echo $isLicensed ? '#ecfdf5; border:1px solid #a7f3d0;' : '#fef2f2; border:1px solid #fecaca;'; ?>">
                        <div style="font-size:28px;"><?php echo $isLicensed ? '🛡️' : '⚠️'; ?></div>
                        <div>
                            <strong style="color:<?php echo $isLicensed ? '#065f46' : '#991b1b'; ?>; font-size:16px;">
                                <?php echo $isLicensed ? esc_html__('License Active & Verified', 'royalpay') : esc_html__('License Inactive / Not Activated', 'royalpay'); ?>
                            </strong>
                            <div style="font-size:13px; color:#4b5563; margin-top:2px;">
                                <?php if ($isLicensed && !empty($licenseInfo)): ?>
                                    Type: <strong><?php echo esc_html(ucfirst($licenseInfo['type'] ?? 'Lifetime')); ?></strong> | 
                                    Domain: <strong><?php echo esc_html($licenseInfo['domain'] ?? ''); ?></strong> | 
                                    Version: <strong>v<?php echo esc_html($licenseInfo['version'] ?? '1'); ?></strong>
                                <?php else: ?>
                                    <?php esc_html_e('Enter your license key provided by Royal Technologies to activate.', 'royalpay'); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <?php if (!$isLicensed): ?>
                        <!-- Activation Form with SweetAlert2 & Read-only Button -->
                        <form id="rp-license-form" method="POST">
                            <p>
                                <label style="display:block; font-weight:600; margin-bottom:8px; font-size:14px;">
                                    <?php esc_html_e('RoyalTechBD License Key:', 'royalpay'); ?>
                                </label>
                                <input type="text" name="license_key" id="royalpay_license_key" value="<?php echo esc_attr($licenseKey); ?>" placeholder="e.g. QDR2CRRK-ZFROBQOJ-MCYI9AZZ" style="width:100%; height:44px; font-family:monospace; font-size:15px; border-radius:8px; border:1px solid #d1d5db; padding:0 12px;" required />
                            </p>

                            <p style="margin-top:20px;">
                                <button type="submit" id="rp-activate-btn" class="button button-primary" style="height:44px; padding:0 28px; font-size:15px; border-radius:8px;">
                                    <?php esc_html_e('Activate License', 'royalpay'); ?>
                                </button>
                            </p>
                        </form>
                    <?php else: ?>
                        <!-- Deactivate / Re-sync Form -->
                        <form id="rp-deactivate-form" method="POST">
                            <?php wp_nonce_field('royalpay_license_action', 'royalpay_license_nonce'); ?>
                            <input type="hidden" name="royalpay_deactivate_license" value="1" />

                            <p>
                                <label style="display:block; font-weight:600; margin-bottom:8px;">
                                    <?php esc_html_e('Active License Key:', 'royalpay'); ?>
                                </label>
                                <input type="text" value="<?php echo esc_attr($licenseKey); ?>" style="width:100%; height:44px; font-family:monospace; font-size:14px; background:#f9fafb;" readonly />
                            </p>

                            <div style="display:flex; gap:12px; margin-top:20px;">
                                <button type="button" id="rp-deactivate-license-btn" class="button button-link-delete" style="color:#ef4444; font-weight:600;">
                                    <?php esc_html_e('Deactivate License', 'royalpay'); ?>
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
}
