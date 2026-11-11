<?php

namespace RoyalPay\Core;

defined('ABSPATH') || exit;

use RoyalPay\License\LicenseManager;

/**
 * GitHub Automatic Plugin Updater for Royal Pay
 * 
 * Checks releases at https://github.com/Royal-Technologies/royal-pay
 * Allows 1-click updates directly from wp-admin/plugins.php for active licenses.
 */
class GitHubUpdater {

    const GITHUB_REPO = 'Royal-Technologies/royal-pay';
    const GITHUB_API_URL = 'https://api.github.com/repos/Royal-Technologies/royal-pay/releases/latest';

    private static ?GitHubUpdater $instance = null;

    public static function getInstance(): GitHubUpdater {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'checkForPluginUpdate']);
        add_filter('plugins_api', [$this, 'pluginPopupDetails'], 20, 3);
        add_action('upgrader_process_complete', [$this, 'afterPluginUpdate'], 10, 2);
    }

    /**
     * Check GitHub for new release version
     */
    public function checkForPluginUpdate($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $release = $this->getLatestGitHubRelease();
        if (!$release || empty($release['tag_name'])) {
            return $transient;
        }

        $newVersion = ltrim($release['tag_name'], 'v');
        $currentVersion = ROYALPAY_VERSION;

        if (version_compare($newVersion, $currentVersion, '>')) {
            $isLicensed = LicenseManager::getInstance()->isLicensed();
            $downloadUrl = $this->getDownloadUrl($release);

            $pluginData = [
                'id'            => 'royal-pay/royal-pay.php',
                'slug'          => 'royal-pay',
                'plugin'        => ROYALPAY_PLUGIN_BASENAME,
                'new_version'   => $newVersion,
                'url'           => 'https://www.royaltechbd.com',
                'package'       => $isLicensed ? $downloadUrl : '', // Lock package if not licensed
                'icons'         => [
                    'svg'     => ROYALPAY_PLUGIN_URL . 'assets/images/logo.svg',
                    'default' => ROYALPAY_PLUGIN_URL . 'assets/images/logo.svg',
                ],
                'banners'       => [
                    'high'    => ROYALPAY_PLUGIN_URL . 'assets/images/mfs-logo.svg',
                ],
                'upgrade_notice'=> $isLicensed ? '' : __('Please activate your Royal Pay license key to install automatic updates.', 'royalpay'),
            ];

            $transient->response[ROYALPAY_PLUGIN_BASENAME] = (object) $pluginData;
        }

        return $transient;
    }

    /**
     * Populate update popup modal with release changelog & metadata
     */
    public function pluginPopupDetails($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (!empty($args->slug) && $args->slug === 'royal-pay') {
            $release = $this->getLatestGitHubRelease();
            if (!$release) {
                return $result;
            }

            $version = ltrim($release['tag_name'] ?? '1.0.0', 'v');
            $downloadUrl = $this->getDownloadUrl($release);
            $isLicensed = LicenseManager::getInstance()->isLicensed();

            return (object) [
                'name'              => 'Royal Pay',
                'slug'              => 'royal-pay',
                'version'           => $version,
                'author'            => '<a href="https://www.royaltechbd.com">Royal Technologies</a>',
                'homepage'          => 'https://www.royaltechbd.com',
                'requires'          => '6.0',
                'tested'            => '6.7',
                'requires_php'      => '8.1',
                'last_updated'      => $release['published_at'] ?? current_time('mysql'),
                'download_link'     => $isLicensed ? $downloadUrl : '',
                'sections'          => [
                    'description' => __('Automated MFS Payment Gateway (bKash, Nagad, Rocket) for WooCommerce with Android SMS sync and instant order verification by Royal Technologies.', 'royalpay'),
                    'changelog'   => nl2br(esc_html($release['body'] ?? "Version {$version} update release.")),
                ],
                'banners'           => [
                    'high' => ROYALPAY_PLUGIN_URL . 'assets/images/mfs-logo.svg',
                ],
            ];
        }

        return $result;
    }

    /**
     * Fetch latest release from GitHub API with caching
     */
    private function getLatestGitHubRelease(): ?array {
        $transientKey = 'royalpay_github_latest_release';
        $cached = get_transient($transientKey);
        if ($cached !== false) {
            return $cached;
        }

        $response = wp_remote_get(self::GITHUB_API_URL, [
            'timeout'   => 10,
            'sslverify' => true,
            'headers'   => [
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress-RoyalPay-Updater/' . ROYALPAY_VERSION,
            ],
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (is_array($body) && !empty($body['tag_name'])) {
            // Cache for 6 hours
            set_transient($transientKey, $body, 6 * HOUR_IN_SECONDS);
            return $body;
        }

        return null;
    }

    /**
     * Extract release zip package URL
     */
    private function getDownloadUrl(array $release): string {
        // Look for release asset zip first (e.g. royal-pay.zip)
        if (!empty($release['assets']) && is_array($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if (isset($asset['browser_download_url']) && str_ends_with($asset['name'], '.zip')) {
                    return $asset['browser_download_url'];
                }
            }
        }

        // Fallback to zipball url
        return $release['zipball_url'] ?? '';
    }

    /**
     * Clear transient after update completes
     */
    public function afterPluginUpdate($upgrader, $options): void {
        if ($options['action'] === 'update' && $options['type'] === 'plugin') {
            delete_transient('royalpay_github_latest_release');
        }
    }
}
