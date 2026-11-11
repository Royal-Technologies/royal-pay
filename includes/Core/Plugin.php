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
        if (strpos($hook, 'royalpay') === false) {
            return;
        }

        wp_enqueue_style(
            'royalpay-admin-css',
            ROYALPAY_PLUGIN_URL . 'assets/css/admin.css',
            [],
            ROYALPAY_VERSION
        );

        wp_enqueue_script(
            'royalpay-admin-js',
            ROYALPAY_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            ROYALPAY_VERSION,
            true
        );

        wp_localize_script('royalpay-admin-js', 'royalpay_vars', [
            'ajax_url'   => admin_url('admin-ajax.php'),
            'rest_url'   => rest_url('royal-pay/v1'),
            'nonce'      => wp_create_nonce('royalpay_admin_nonce'),
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
