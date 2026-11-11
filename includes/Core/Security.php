<?php

namespace RoyalPay\Core;

defined('ABSPATH') || exit;

/**
 * Royal Pay Cryptographic and Security Helper
 */
class Security {

    /**
     * Generate cryptographically secure random token
     */
    public static function generateSecureToken(int $bytes = 32): string {
        return bin2hex(random_bytes($bytes));
    }

    /**
     * Generate a UUID v4
     */
    public static function generateUuid(): string {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Hash a token using SHA-256 for database storage
     */
    public static function hashToken(string $token): string {
        return hash('sha256', $token);
    }

    /**
     * Constant-time string comparison to prevent timing attacks
     */
    public static function hashEquals(string $knownString, string $userString): bool {
        return hash_equals($knownString, $userString);
    }

    /**
     * Application-level encryption using AES-256-GCM
     */
    public static function encrypt(string $plaintext): string {
        if (empty($plaintext)) {
            return '';
        }

        $key = self::getMasterKey();
        $iv = random_bytes(12);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Application-level decryption using AES-256-GCM
     */
    public static function decrypt(string $payload): ?string {
        if (empty($payload)) {
            return null;
        }

        $decoded = base64_decode($payload, true);
        if ($decoded === false || strlen($decoded) < 28) {
            return null;
        }

        $iv = substr($decoded, 0, 12);
        $tag = substr($decoded, 12, 16);
        $ciphertext = substr($decoded, 28);
        $key = self::getMasterKey();

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return $plaintext !== false ? $plaintext : null;
    }

    /**
     * Retrieve or generate master encryption key
     */
    private static function getMasterKey(): string {
        if (defined('ROYAL_PAY_ENCRYPTION_KEY') && !empty(ROYAL_PAY_ENCRYPTION_KEY)) {
            $key = ROYAL_PAY_ENCRYPTION_KEY;
            if (str_starts_with($key, 'base64:')) {
                return base64_decode(substr($key, 7));
            }
            return substr(hash('sha256', $key, true), 0, 32);
        }

        // Fallback: derive key from WordPress AUTH_KEY and SECURE_AUTH_KEY
        $salt = defined('AUTH_KEY') ? AUTH_KEY : 'royalpay_salt_fallback';
        $secure = defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : 'royalpay_secure_salt';
        return hash('sha256', $salt . $secure, true);
    }

    /**
     * Sanitize and format BDT monetary amount into minor units (integer paisa)
     * e.g. 1500 or 1500.50 -> 150000 or 150050
     */
    public static function toMinorUnits(float|int|string $amount): int {
        $amount = (float) $amount;
        return (int) round($amount * 100);
    }

    /**
     * Format minor units back to standard BDT currency string
     * e.g. 150000 -> "1,500.00"
     */
    public static function fromMinorUnits(int $minorUnits): string {
        return number_format($minorUnits / 100, 2);
    }
}
