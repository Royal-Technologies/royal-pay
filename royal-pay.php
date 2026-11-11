<?php
/**
 * Plugin Name:       Royal Pay
 * Plugin URI:        https://www.royaltechbd.com/plugins/royal-pay
 * Description:       Automated MFS Payment Gateway (bKash, Nagad, Rocket) for WooCommerce with Android SMS sync and instant order verification by Royal Technologies.
 * Version:           1.0.0
 * Author:            Royal Technologies
 * Author URI:        https://www.royaltechbd.com
 * License:           Proprietary
 * Text Domain:       royalpay
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * WC requires at least: 8.0
 * WC tested up to:   9.5
 */

defined('ABSPATH') || exit;

// Plugin Constants
define('ROYALPAY_VERSION', '1.0.0');
define('ROYALPAY_PLUGIN_SLUG', 'royalpay');
define('ROYALPAY_PLUGIN_FILE', __FILE__);
define('ROYALPAY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ROYALPAY_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ROYALPAY_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Autoloader for RoyalPay classes
spl_autoload_register(function ($class) {
    $prefix = 'RoyalPay\\';
    $base_dir = ROYALPAY_PLUGIN_DIR . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

/**
 * Load plugin text domain for internationalization
 */
add_action('init', function () {
    load_plugin_textdomain('royalpay', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

/**
 * Declare WooCommerce High-Performance Order Storage (HPOS) compatibility.
 */
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
});

/**
 * Plugin Activation Hook
 */
register_activation_hook(__FILE__, function () {
    require_once ROYALPAY_PLUGIN_DIR . 'includes/Database/MigrationRunner.php';
    \RoyalPay\Database\MigrationRunner::runMigrations();

    // Schedule daily license check if not already scheduled
    if (!wp_next_scheduled('royalpay_daily_license_ping')) {
        wp_schedule_event(time(), 'daily', 'royalpay_daily_license_ping');
    }

    // Schedule queue worker cron
    if (!wp_next_scheduled('royalpay_queue_process_event')) {
        wp_schedule_event(time(), 'every_minute', 'royalpay_queue_process_event');
    }

    flush_rewrite_rules();
});

/**
 * Plugin Deactivation Hook
 */
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('royalpay_daily_license_ping');
    wp_clear_scheduled_hook('royalpay_queue_process_event');
    flush_rewrite_rules();
});

// Custom cron interval for queue processing (every minute)
add_filter('cron_schedules', function ($schedules) {
    if (!isset($schedules['every_minute'])) {
        $schedules['every_minute'] = [
            'interval' => 60,
            'display'  => __('Every Minute', 'royalpay'),
        ];
    }
    return $schedules;
});

/**
 * Initialize Plugin Core
 */
add_action('plugins_loaded', function () {
    \RoyalPay\Core\Plugin::getInstance()->init();

    // Standard Royal Technologies Plugin Updater
    if (is_admin()) {
        require_once ROYALPAY_PLUGIN_DIR . 'includes/updater.php';
        $update_url = 'https://raw.githubusercontent.com/Royal-Technologies/royal-pay/main/royal-pay.json';
        new Royal_Pay_Updater(ROYALPAY_PLUGIN_FILE, $update_url);
    }
});
