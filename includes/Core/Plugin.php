<?php

namespace RoyalPay\Core;

defined('ABSPATH') || exit;

use RoyalPay\License\LicenseManager;
use RoyalPay\API\RestServer;
use RoyalPay\Gateway\WooCommerceGateway;
use RoyalPay\Admin\AdminMenu;

/**
 * Main Plugin Orchestrator
 */
class Plugin {

    private static ?Plugin $instance = null;

    public static function getInstance(): Plugin {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Singleton
    }

    public function init(): void {
        // Initialize License Manager
        LicenseManager::getInstance();

        // Register WooCommerce Payment Gateway
        add_filter('woocommerce_payment_gateways', [$this, 'registerGateway']);

        // Register REST API routes
        add_action('rest_api_init', [RestServer::class, 'registerRoutes']);

        // Register Admin Menus & Assets
        if (is_admin()) {
            AdminMenu::getInstance()->init();
            add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        }

        // Add action links in plugins list table
        add_filter('plugin_action_links_' . ROYALPAY_PLUGIN_BASENAME, [$this, 'addPluginActionLinks']);
    }

    public function registerGateway(array $gateways): array {
        // Check if licensed; if not, do not show gateway on checkout
        if (LicenseManager::getInstance()->isLicensed()) {
            $gateways[] = WooCommerceGateway::class;
        }
        return $gateways;
    }

    public function enqueueAdminAssets(string $hook): void {
        $currentPage = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
        if (strpos($hook, 'royalpay') === false && strpos($currentPage, 'royalpay') === false) {
            return;
        }

        // 1. SweetAlert2 Local Assets
        wp_enqueue_style(
            'royalpay-swal-css',
            ROYALPAY_PLUGIN_URL . 'assets/lib/sweetalert2/sweetalert2.min.css',
            [],
            ROYALPAY_VERSION
        );

        wp_enqueue_script(
            'royalpay-swal-js',
            ROYALPAY_PLUGIN_URL . 'assets/lib/sweetalert2/sweetalert2.all.min.js',
            ['jquery'],
            ROYALPAY_VERSION,
            true
        );

        // 2. DataTables Local Assets
        wp_enqueue_style(
            'royalpay-datatables-css',
            ROYALPAY_PLUGIN_URL . 'assets/lib/datatables/datatables.min.css',
            [],
            ROYALPAY_VERSION
        );

        wp_enqueue_script(
            'royalpay-datatables-js',
            ROYALPAY_PLUGIN_URL . 'assets/lib/datatables/datatables.min.js',
            ['jquery'],
            ROYALPAY_VERSION,
            true
        );

        // 3. WordPress Color Picker
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');

        // 4. Royal Pay Main Admin CSS
        wp_enqueue_style(
            'royalpay-admin-css',
            ROYALPAY_PLUGIN_URL . 'assets/css/admin.css',
            ['royalpay-swal-css', 'royalpay-datatables-css'],
            ROYALPAY_VERSION
        );

        // Dynamic Color Scheme from Settings
        $primaryColor = get_option('royalpay_primary_color', '#4F46E5');
        $primaryDark  = get_option('royalpay_primary_dark', '#3730A3');
        $customCss = "
            :root {
                --rp-primary: {$primaryColor};
                --rp-primary-dark: {$primaryDark};
            }
            .rp-page-header.custom-gradient {
                background: linear-gradient(135deg, {$primaryColor} 0%, {$primaryDark} 100%) !important;
            }
        ";
        wp_add_inline_style('royalpay-admin-css', $customCss);

        // 5. Royal Pay Main Admin JS
        wp_enqueue_script(
            'royalpay-admin-js',
            ROYALPAY_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery', 'royalpay-swal-js', 'royalpay-datatables-js', 'wp-color-picker'],
            ROYALPAY_VERSION,
            true
        );

        wp_localize_script('royalpay-admin-js', 'royalpay_vars', [
            'ajax_url'   => admin_url('admin-ajax.php'),
            'rest_url'   => rest_url('royal-pay/v1'),
            'nonce'      => wp_create_nonce('royalpay_admin_nonce'),
            'license_nonce' => wp_create_nonce('royalpay_license_ajax_nonce'),
            'dashboard_url' => admin_url('admin.php?page=royalpay'),
        ]);
    }

    public function addPluginActionLinks(array $links): array {
        $settingsLink = '<a href="' . esc_url(admin_url('admin.php?page=royalpay-settings')) . '">' . __('Settings', 'royalpay') . '</a>';
        $licenseLink = '<a href="' . esc_url(admin_url('admin.php?page=royalpay-license')) . '">' . __('License', 'royalpay') . '</a>';
        array_unshift($links, $licenseLink);
        array_unshift($links, $settingsLink);
        return $links;
    }
}
