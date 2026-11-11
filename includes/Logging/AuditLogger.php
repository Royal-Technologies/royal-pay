<?php

namespace RoyalPay\Logging;

defined('ABSPATH') || exit;

/**
 * Immutable Audit and Security Logger
 */
class AuditLogger {

    public static function log(string $action, string $entityType, ?int $entityId = null, array $details = []): void {
        global $wpdb;

        $table = $wpdb->prefix . 'rpa_audit_logs';
        $userId = get_current_user_id() ?: null;
        $ip = sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');
        $userAgent = sanitize_text_field(substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255));

        // Redact any sensitive keys
        $sanitizedDetails = self::redactSensitiveData($details);

        $wpdb->insert(
            $table,
            [
                'user_id'     => $userId,
                'action'      => sanitize_text_field($action),
                'entity_type' => sanitize_text_field($entityType),
                'entity_id'   => $entityId,
                'ip_address'  => $ip,
                'user_agent'  => $userAgent,
                'details'     => json_encode($sanitizedDetails, JSON_UNESCAPED_UNICODE),
                'created_at'  => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s']
        );
    }

    private static function redactSensitiveData(array $data): array {
        $sensitiveKeys = ['password', 'secret', 'token', 'pin', 'key', 'credentials', 'token_secret'];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::redactSensitiveData($value);
            } elseif (in_array(strtolower($key), $sensitiveKeys, true)) {
                $data[$key] = '***REDACTED***';
            }
        }
        return $data;
    }
}
