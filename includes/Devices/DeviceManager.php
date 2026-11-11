<?php

namespace RoyalPay\Devices;

defined('ABSPATH') || exit;

use RoyalPay\Core\Security;
use RoyalPay\Logging\AuditLogger;

/**
 * Android Device Manager for Royal Pay Agent
 */
class DeviceManager {

    /**
     * Get all registered devices
     */
    public static function getDevices(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'rpa_devices';
        return $wpdb->get_results("SELECT * FROM `{$table}` ORDER BY `id` DESC", ARRAY_A) ?: [];
    }

    /**
     * Get a single device by ID
     */
    public static function getDeviceById(int $id): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'rpa_devices';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE `id` = %d", $id), ARRAY_A);
        return $row ?: null;
    }

    /**
     * Get device by token hash
     */
    public static function getDeviceByTokenHash(string $tokenHash): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'rpa_devices';
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$table}` WHERE `token_hash` = %s AND `status` = 'active'", $tokenHash), ARRAY_A);
        return $row ?: null;
    }

    /**
     * Generate a new device pairing registration code / QR payload
     */
    public static function generatePairingPayload(): array {
        $pairingToken = Security::generateSecureToken(16);
        $tempSecret = Security::generateSecureToken(32);

        // Store transient valid for 15 minutes
        set_transient('royalpay_pairing_' . $pairingToken, [
            'secret'     => $tempSecret,
            'created_at' => time(),
        ], 15 * MINUTE_IN_SECONDS);

        $apiUrl = rest_url('royal-pay/v1');

        $qrData = [
            'api_url'       => $apiUrl,
            'pairing_token' => $pairingToken,
            'secret'        => $tempSecret,
            'site_name'     => get_bloginfo('name'),
            'timestamp'     => time(),
        ];

        return [
            'qr_payload'    => json_encode($qrData),
            'pairing_token' => $pairingToken,
            'secret'        => $tempSecret,
            'api_url'       => $apiUrl,
            'expires_in'    => 900,
        ];
    }

    /**
     * Complete device pairing from Android App request
     */
    public static function pairDevice(array $payload): array {
        global $wpdb;
        $table = $wpdb->prefix . 'rpa_devices';

        $pairingToken = sanitize_text_field($payload['pairing_token'] ?? '');
        $deviceUuid   = sanitize_text_field($payload['device_uuid'] ?? '');
        $deviceName   = sanitize_text_field($payload['device_name'] ?? 'Android Device');
        $model        = sanitize_text_field($payload['model'] ?? '');
        $androidVer   = sanitize_text_field($payload['android_version'] ?? '');
        $appVer       = sanitize_text_field($payload['app_version'] ?? '1.0.0');

        $cached = get_transient('royalpay_pairing_' . $pairingToken);
        if (!$cached) {
            return ['success' => false, 'message' => __('Pairing token is invalid or expired. Please scan a fresh QR code.', 'royalpay')];
        }

        // Invalidate transient once used
        delete_transient('royalpay_pairing_' . $pairingToken);

        $permanentSecret = Security::generateSecureToken(32);
        $tokenHash = Security::hashToken($permanentSecret);
        $now = current_time('mysql');

        // Check if device already registered with this UUID
        $existing = $wpdb->get_row($wpdb->prepare("SELECT id FROM `{$table}` WHERE `device_uuid` = %s", $deviceUuid), ARRAY_A);

        if ($existing) {
            $wpdb->update($table, [
                'device_name'       => $deviceName,
                'model'             => $model,
                'android_version'   => $androidVer,
                'app_version'       => $appVer,
                'token_hash'        => $tokenHash,
                'token_secret'      => $permanentSecret,
                'status'            => 'active',
                'last_heartbeat_at' => $now,
                'updated_at'        => $now,
            ], ['id' => $existing['id']]);

            $deviceId = $existing['id'];
        } else {
            $wpdb->insert($table, [
                'device_uuid'       => $deviceUuid ?: Security::generateUuid(),
                'device_name'       => $deviceName,
                'model'             => $model,
                'android_version'   => $androidVer,
                'app_version'       => $appVer,
                'token_hash'        => $tokenHash,
                'token_secret'      => $permanentSecret,
                'status'            => 'active',
                'last_heartbeat_at' => $now,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);

            $deviceId = $wpdb->insert_id;
        }

        AuditLogger::log('device_paired', 'device', $deviceId, [
            'device_name' => $deviceName,
            'model'       => $model,
            'uuid'        => $deviceUuid,
        ]);

        return [
            'success'       => true,
            'message'       => __('Device paired successfully!', 'royalpay'),
            'device_id'     => $deviceId,
            'device_secret' => $permanentSecret,
            'site_name'     => get_bloginfo('name'),
        ];
    }

    /**
     * Record device heartbeat and telemetry
     */
    public static function recordHeartbeat(int $deviceId, array $telemetry): void {
        global $wpdb;
        $table = $wpdb->prefix . 'rpa_devices';

        $wpdb->update(
            $table,
            [
                'battery_percentage' => isset($telemetry['battery']) ? (int) $telemetry['battery'] : null,
                'is_charging'        => !empty($telemetry['is_charging']) ? 1 : 0,
                'network_type'       => sanitize_text_field($telemetry['network_type'] ?? ''),
                'sim_operator'       => sanitize_text_field($telemetry['sim_operator'] ?? ''),
                'last_heartbeat_at'  => current_time('mysql'),
                'updated_at'         => current_time('mysql'),
            ],
            ['id' => $deviceId]
        );
    }

    /**
     * Revoke or suspend a device
     */
    public static function revokeDevice(int $id): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'rpa_devices';
        $res = $wpdb->update($table, ['status' => 'revoked', 'updated_at' => current_time('mysql')], ['id' => $id]);
        if ($res) {
            AuditLogger::log('device_revoked', 'device', $id);
            return true;
        }
        return false;
    }
}
