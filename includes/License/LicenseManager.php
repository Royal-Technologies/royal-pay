<?php

namespace RoyalPay\License;

defined('ABSPATH') || exit;

/**
 * RoyalTechBD License Manager — Version 2.0
 * Resilient Local-First Architecture with Ed25519 Detached Digital Signatures.
 * 
 * Complies strictly with D:\laragon\www\royalauth\License_Integration_Guide.md
 */
class LicenseManager {

    const SOFTWARE_SLUG = 'royalpay';
    const PUBLIC_KEY    = 'vE7PkX4ho5A4ZVWH5KwToDcv1cPIY6FJsNU4tDjbNIw=';
    const VERIFY_URL    = 'https://auth.royaltechbd.com/api/verify';
    const VERSION_URL   = 'https://auth.royaltechbd.com/api/license/version';
    const PING_URL      = 'https://auth.royaltechbd.com/api/activities/ping';

    /**
     * Singleton instance
     */
    private static ?LicenseManager $instance = null;

    public static function getInstance(): LicenseManager {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('royalpay_daily_license_ping', [$this, 'dailyCronCheck']);
    }

    /**
     * Primary license check (Version 2.0 Resilient Local-First)
     */
    public function isLicensed(): bool {
        // Fast path: check status option
        if (get_option('royalpay_license_status', 'inactive') !== 'active') {
            return false;
        }

        $licenseKey = get_option('royalpay_license_key', '');
        if (empty($licenseKey)) {
            return false;
        }

        // Load local cache
        $rawCache = get_option('royalpay_license_cache', '');
        $localCache = !empty($rawCache) ? json_decode($rawCache, true) : null;

        // Local 3-point check (no server ping)
        $localValid = $localCache ? $this->verifySignatureLocally($localCache) : false;

        if ($localValid) {
            // Lightweight 24-hour version check
            $lastCheck = get_option('royalpay_license_version_check_at', '');
            $needsCheck = empty($lastCheck) || (time() - strtotime($lastCheck) > 86400);

            if ($needsCheck) {
                try {
                    $cachedVersion = (int) get_option('royalpay_license_version', 0);
                    $url = add_query_arg([
                        'k' => $licenseKey,
                        's' => self::SOFTWARE_SLUG,
                        'v' => $cachedVersion,
                    ], self::VERSION_URL);

                    $response = wp_remote_get($url, [
                        'timeout'   => 5,
                        'sslverify' => true,
                    ]);

                    update_option('royalpay_license_version_check_at', current_time('mysql'));

                    if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                        $vData = json_decode(wp_remote_retrieve_body($response), true);
                        if (isset($vData['version'])) {
                            update_option('royalpay_license_version', (int) $vData['version']);
                        }

                        if (!empty($vData['changed'])) {
                            // Version changed on server (e.g. suspended, expired) — trigger full sync
                            $localValid = false;
                        }
                    }
                } catch (\Throwable $e) {
                    // Network error — keep localValid as true, site continues running seamlessly
                }
            }

            if ($localValid) {
                return true;
            }
        }

        // Full sync: POST /api/verify
        return $this->performFullSync($licenseKey, $localCache);
    }

    /**
     * Verify Ed25519 signature and local constraints without contacting the server
     */
    public function verifySignatureLocally(?array $localCache): bool {
        if (!$localCache || !isset($localCache['signature'], $localCache['license_data'])) {
            return false;
        }

        // 1. Ed25519 Signature Verification
        try {
            $publicKey = base64_decode(self::PUBLIC_KEY);
            $signature = base64_decode($localCache['signature']);
            $message   = json_encode($localCache['license_data'], JSON_UNESCAPED_UNICODE);

            if (function_exists('sodium_crypto_sign_verify_detached')) {
                if (!sodium_crypto_sign_verify_detached($signature, $message, $publicKey)) {
                    return false;
                }
            } else {
                // Fallback if ext-sodium is missing: use OpenSSL or pass if valid local hash
                return false;
            }
        } catch (\Throwable $e) {
            return false;
        }

        $data = $localCache['license_data'];

        // 2. Domain Lock Check
        $currentDomain = $this->getCurrentDomain();
        $allowedLocalDomains = ['localhost', '127.0.0.1'];

        if (
            !in_array($currentDomain, $allowedLocalDomains, true) &&
            strpos($currentDomain, '.test') === false &&
            strpos($currentDomain, '.local') === false &&
            $currentDomain !== ($data['domain'] ?? '')
        ) {
            return false;
        }

        // 3. Expiry Check with Grace Period
        $now       = new \DateTime();
        $expiresAt = !empty($data['expires_at']) ? new \DateTime($data['expires_at']) : null;
        $type      = $data['type'] ?? 'lifetime';

        if ($type === 'lifetime') {
            return ($data['status'] ?? '') === 'active';
        }

        if ($expiresAt && $now > $expiresAt) {
            $graceDays = ($type === 'yearly') ? 30 : 3;
            $graceExpiry = clone $expiresAt;
            $graceExpiry->modify("+{$graceDays} days");

            return $now <= $graceExpiry;
        }

        return ($data['status'] ?? '') === 'active';
    }

    /**
     * Perform full sync with auth.royaltechbd.com
     */
    public function performFullSync(string $licenseKey, ?array $localCache = null): bool {
        try {
            $domain = $this->getCurrentDomain();
            $response = wp_remote_post(self::VERIFY_URL, [
                'timeout'   => 8,
                'sslverify' => true,
                'headers'   => ['Content-Type' => 'application/json'],
                'body'      => json_encode([
                    'software'    => self::SOFTWARE_SLUG,
                    'license_key' => $licenseKey,
                    'domain'      => $domain,
                ]),
            ]);

            // Server error (5xx) → Fall back to local cache, do NOT deactivate
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) >= 500) {
                if ($localCache && $this->verifySignatureLocally($localCache)) {
                    return true;
                }
                return $this->gracePeriodCheck();
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            $statusCode = wp_remote_retrieve_response_code($response);

            if (empty($body['success'])) {
                $reason = $body['message'] ?? '';
                $definitelyInvalid = str_contains($reason, 'suspended') ||
                                     str_contains($reason, 'expired')   ||
                                     str_contains($reason, 'Invalid')   ||
                                     str_contains($reason, 'blocked')   ||
                                     $statusCode === 403                ||
                                     $statusCode === 404;

                if ($definitelyInvalid) {
                    update_option('royalpay_license_status', 'inactive');
                    return false;
                }

                if ($localCache && $this->verifySignatureLocally($localCache)) {
                    return true;
                }
                return $this->gracePeriodCheck();
            }

            // Verify fresh response signature before trusting
            if (!$this->verifySignatureLocally($body)) {
                update_option('royalpay_license_status', 'inactive');
                return false;
            }

            // Cache the verified signed response
            update_option('royalpay_license_cache', json_encode($body));
            update_option('royalpay_license_last_check', current_time('mysql'));
            update_option('royalpay_license_version', $body['license_data']['version'] ?? 1);

            $expiresAt = $body['license_data']['expires_at'] ?? null;
            if ($expiresAt) {
                update_option('royalpay_license_expiry_enc', base64_encode($expiresAt));
            } else {
                delete_option('royalpay_license_expiry_enc');
            }

            return true;
        } catch (\Throwable $e) {
            if ($localCache && $this->verifySignatureLocally($localCache)) {
                return true;
            }
            return $this->gracePeriodCheck();
        }
    }

    /**
     * Activate a new license key entered by user
     */
    public function activateLicense(string $licenseKey): array {
        $licenseKey = sanitize_text_field(trim($licenseKey));
        if (empty($licenseKey)) {
            return ['success' => false, 'message' => __('Please enter a valid license key.', 'royalpay')];
        }

        $domain = $this->getCurrentDomain();
        $response = wp_remote_post(self::VERIFY_URL, [
            'timeout'   => 10,
            'sslverify' => true,
            'headers'   => ['Content-Type' => 'application/json'],
            'body'      => json_encode([
                'software'    => self::SOFTWARE_SLUG,
                'license_key' => $licenseKey,
                'domain'      => $domain,
            ]),
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => __('License server is unreachable: ', 'royalpay') . $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 500) {
            return ['success' => false, 'message' => __('License server is temporarily unavailable. Please try again.', 'royalpay')];
        }

        if (empty($data['success'])) {
            return ['success' => false, 'message' => $data['message'] ?? __('Invalid license key or domain unauthorized.', 'royalpay')];
        }

        if (!$this->verifySignatureLocally($data)) {
            return ['success' => false, 'message' => __('Invalid server response signature. Possible tampering detected.', 'royalpay')];
        }

        // Save activation state
        update_option('royalpay_license_key', $licenseKey);
        update_option('royalpay_license_status', 'active');
        update_option('royalpay_license_cache', json_encode($data));
        update_option('royalpay_license_last_check', current_time('mysql'));
        update_option('royalpay_license_version', $data['license_data']['version'] ?? 1);

        $expiresAt = $data['license_data']['expires_at'] ?? null;
        if ($expiresAt) {
            update_option('royalpay_license_expiry_enc', base64_encode($expiresAt));
        }

        return ['success' => true, 'message' => __('License activated successfully!', 'royalpay'), 'data' => $data['license_data']];
    }

    /**
     * Deactivate current license
     */
    public function deactivateLicense(): void {
        update_option('royalpay_license_status', 'inactive');
        delete_option('royalpay_license_cache');
        delete_option('royalpay_license_key');
        delete_option('royalpay_license_version');
        delete_option('royalpay_license_expiry_enc');
    }

    /**
     * Daily scheduled cron check
     */
    public function dailyCronCheck(): void {
        $licenseKey = get_option('royalpay_license_key', '');
        if (empty($licenseKey) || get_option('royalpay_license_status') !== 'active') {
            return;
        }

        $this->isLicensed();
    }

    private function gracePeriodCheck(): bool {
        $encExpiry = get_option('royalpay_license_expiry_enc', '');
        if (empty($encExpiry)) {
            return true; // Lifetime license
        }

        try {
            $expiryStr = base64_decode($encExpiry);
            $expiryDate = new \DateTime($expiryStr);
            $graceDate = clone $expiryDate;
            $graceDate->modify('+3 days');

            if (new \DateTime() > $graceDate) {
                update_option('royalpay_license_status', 'inactive');
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function getCurrentDomain(): string {
        $host = $_SERVER['HTTP_HOST'] ?? parse_url(home_url(), PHP_URL_HOST);
        $host = preg_replace('/^www\./i', '', $host);
        return strtolower(trim($host));
    }
}
