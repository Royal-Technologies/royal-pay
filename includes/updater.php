<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use RoyalPay\License\LicenseManager;

/**
 * Standard Royal Technologies Plugin Updater for Royal Pay
 */
class Royal_Pay_Updater {

    private $plugin_slug;
    private $current_version;
    private $update_json_url;
    private $plugin_file;

    public function __construct( $plugin_file, $update_json_url ) {
        $this->plugin_file     = $plugin_file;
        $this->plugin_slug     = plugin_basename( $this->plugin_file );
        $this->update_json_url = $update_json_url;

        if ( defined( 'ROYALPAY_VERSION' ) ) {
            $this->current_version = ROYALPAY_VERSION;
        } else {
            if ( ! function_exists( 'get_plugin_data' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $plugin_data           = get_plugin_data( $this->plugin_file );
            $this->current_version = $plugin_data['Version'] ?? '1.0.0';
        }

        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
        add_filter( 'site_transient_update_plugins', [ $this, 'inject_update_info' ] );
        add_filter( 'plugins_api', [ $this, 'plugin_api_details' ], 10, 3 );
    }

    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $response = wp_remote_get( $this->update_json_url, [ 'timeout' => 10, 'sslverify' => false ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return $transient;
        }

        $update_data = json_decode( wp_remote_retrieve_body( $response ) );

        if ( is_object( $update_data ) ) {
            $assets_url   = plugin_dir_url( $this->plugin_file ) . 'assets/images/';
            $plugin_icons = isset( $update_data->icons ) ? (array) $update_data->icons : [
                'svg' => $assets_url . 'logo.svg',
                '1x'  => $assets_url . 'logo.svg',
                '2x'  => $assets_url . 'logo.svg',
            ];

            // Verify active RoyalAuth license before delivering the download package
            $is_licensed = class_exists( LicenseManager::class ) ? LicenseManager::getInstance()->isLicensed() : true;
            $package_url = ( $is_licensed && ! empty( $update_data->download_url ) ) ? $update_data->download_url : '';

            $item = (object) [
                'id'            => $this->plugin_slug,
                'slug'          => basename( $this->plugin_slug, '.php' ),
                'plugin'        => $this->plugin_slug,
                'new_version'   => $update_data->version,
                'package'       => $package_url,
                'url'           => $update_data->homepage ?? 'https://www.royaltechbd.com',
                'icons'         => $plugin_icons,
                'banners'       => isset( $update_data->banners ) ? (array) $update_data->banners : [
                    'high' => $assets_url . 'mfs-logo.svg',
                ],
                'banners_rtl'   => [],
                'tested'        => $update_data->tested ?? '6.7',
                'requires_php'  => $update_data->requires_php ?? '8.1',
                'compatibility' => new stdClass(),
            ];

            if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
                $transient->response = [];
            }
            if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
                $transient->no_update = [];
            }

            if ( version_compare( $this->current_version, $update_data->version, '<' ) ) {
                $transient->response[ $this->plugin_slug ] = $item;
                unset( $transient->no_update[ $this->plugin_slug ] );
            } else {
                $transient->no_update[ $this->plugin_slug ] = $item;
                unset( $transient->response[ $this->plugin_slug ] );
            }
        }

        return $transient;
    }

    public function inject_update_info( $transient ) {
        if ( ! is_object( $transient ) ) {
            return $transient;
        }

        if ( ! isset( $transient->response[ $this->plugin_slug ] ) && ! isset( $transient->no_update[ $this->plugin_slug ] ) ) {
            $assets_url = plugin_dir_url( $this->plugin_file ) . 'assets/images/';
            $item       = (object) [
                'id'            => $this->plugin_slug,
                'slug'          => basename( $this->plugin_slug, '.php' ),
                'plugin'        => $this->plugin_slug,
                'new_version'   => $this->current_version,
                'package'       => '',
                'url'           => 'https://www.royaltechbd.com',
                'icons'         => [
                    'svg' => $assets_url . 'logo.svg',
                    '1x'  => $assets_url . 'logo.svg',
                    '2x'  => $assets_url . 'logo.svg',
                ],
                'banners'       => [
                    'high' => $assets_url . 'mfs-logo.svg',
                ],
                'banners_rtl'   => [],
                'tested'        => '6.7',
                'requires_php'  => '8.1',
                'compatibility' => new stdClass(),
            ];

            if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
                $transient->no_update = [];
            }
            $transient->no_update[ $this->plugin_slug ] = $item;
        }

        return $transient;
    }

    public function plugin_api_details( $result, $action, $args ) {
        if ( isset( $args->slug ) && $args->slug === basename( $this->plugin_slug, '.php' ) ) {
            $response = wp_remote_get( $this->update_json_url, [ 'timeout' => 10, 'sslverify' => false ] );

            if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
                return $result;
            }

            $update_data = json_decode( wp_remote_retrieve_body( $response ) );

            if ( is_object( $update_data ) ) {
                if ( ! function_exists( 'get_plugin_data' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }
                $plugin_data = get_plugin_data( $this->plugin_file );

                $is_licensed = class_exists( LicenseManager::class ) ? LicenseManager::getInstance()->isLicensed() : true;
                $download_url = ( $is_licensed && ! empty( $update_data->download_url ) ) ? $update_data->download_url : '';

                $result = (object) [
                    'name'          => $plugin_data['Name'] ?? 'Royal Pay',
                    'slug'          => basename( $this->plugin_slug, '.php' ),
                    'version'       => $update_data->version,
                    'author'        => $plugin_data['Author'] ?? 'Royal Technologies',
                    'homepage'      => $update_data->homepage ?? 'https://www.royaltechbd.com',
                    'download_link' => $download_url,
                    'sections'      => (array) ( $update_data->sections ?? [] ),
                    'banners'       => (array) ( $update_data->banners ?? [] ),
                ];
            }
        }
        return $result;
    }
}
