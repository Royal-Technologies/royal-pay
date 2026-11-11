<?php

namespace RoyalPay\API;

defined('ABSPATH') || exit;

use RoyalPay\Core\Security;
use RoyalPay\Devices\DeviceManager;
use RoyalPay\Transactions\TransactionManager;
use RoyalPay\Matching\MatchingEngine;
use RoyalPay\Wallets\WalletManager;
use RoyalPay\License\LicenseManager;

/**
 * REST API Server for Android Agent and Integrations
 * Namespace: royal-pay/v1
 */
class RestServer {

    const NAMESPACE = 'royal-pay/v1';

    public static function registerRoutes(): void {
        // 1. Device Pairing (Public via short-lived pairing token)
        register_rest_route(self::NAMESPACE, '/devices/pair', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handleDevicePair'],
            'permission_callback' => '__return_true',
        ]);

        // 2. Device Heartbeat
        register_rest_route(self::NAMESPACE, '/devices/heartbeat', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handleDeviceHeartbeat'],
            'permission_callback' => [__CLASS__, 'authenticateDeviceRequest'],
        ]);

        // 3. Ingest Single SMS & Auto-Match
        register_rest_route(self::NAMESPACE, '/devices/sms/ingest', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handleSmsIngest'],
            'permission_callback' => [__CLASS__, 'authenticateDeviceRequest'],
        ]);

        // 4. Ingest Batch Offline Transactions
        register_rest_route(self::NAMESPACE, '/devices/transactions/batch', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handleTransactionBatch'],
            'permission_callback' => [__CLASS__, 'authenticateDeviceRequest'],
        ]);

        // 5. Device Active Wallets Config
        register_rest_route(self::NAMESPACE, '/devices/config', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'handleDeviceConfig'],
            'permission_callback' => [__CLASS__, 'authenticateDeviceRequest'],
        ]);

        // 6. Public Health Check
        register_rest_route(self::NAMESPACE, '/health', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'handleHealthCheck'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Authenticate requests originating from Royal Pay Agent (Android)
     * Validates X-Device-Token header against token_hash in rpa_devices
     */
    public static function authenticateDeviceRequest(\WP_REST_Request $request): bool|\WP_Error {
        $token = $request->get_header('x-device-token');
        if (empty($token)) {
            $authHeader = $request->get_header('authorization');
            if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
                $token = substr($authHeader, 7);
            }
        }

        if (empty($token)) {
            return new \WP_Error('unauthorized', 'Missing device authentication token', ['status' => 401]);
        }

        $tokenHash = Security::hashToken($token);
        $device = DeviceManager::getDeviceByTokenHash($tokenHash);

        if (!$device) {
            return new \WP_Error('forbidden', 'Invalid or revoked device token', ['status' => 403]);
        }

        // Attach device context to request attributes
        $request->set_param('_device_context', $device);
        return true;
    }

    /**
     * POST /devices/pair
     */
    public static function handleDevicePair(\WP_REST_Request $request): \WP_REST_Response {
        $params = $request->get_json_params() ?: $request->get_params();
        $result = DeviceManager::pairDevice($params);

        $status = $result['success'] ? 200 : 400;
        return new \WP_REST_Response($result, $status);
    }

    /**
     * POST /devices/heartbeat
     */
    public static function handleDeviceHeartbeat(\WP_REST_Request $request): \WP_REST_Response {
        $device = $request->get_param('_device_context');
        $params = $request->get_json_params() ?: $request->get_params();

        DeviceManager::recordHeartbeat((int) $device['id'], $params);

        return new \WP_REST_Response([
            'success'   => true,
            'timestamp' => current_time('mysql'),
            'status'    => 'active',
        ], 200);
    }

    /**
     * POST /devices/sms/ingest
     */
    public static function handleSmsIngest(\WP_REST_Request $request): \WP_REST_Response {
        $device = $request->get_param('_device_context');
        $params = $request->get_json_params() ?: $request->get_params();

        $params['device_id'] = (int) $device['id'];

        // 1. Log SMS
        $smsResult = TransactionManager::logSms($params);
        if (!$smsResult['success'] && ($smsResult['code'] ?? '') === 'DUPLICATE_SMS') {
            return new \WP_REST_Response([
                'success' => true,
                'message' => 'Duplicate SMS ignored.',
                'status'  => 'duplicate',
            ], 200);
        }

        // 2. If valid parsed transaction fields exist, ingest transaction
        $matchResult = null;
        if (!empty($params['parsed_trxid']) && !empty($params['parsed_provider'])) {
            $txnData = [
                'provider'            => $params['parsed_provider'],
                'transaction_id'      => $params['parsed_trxid'],
                'amount_minor'        => $params['parsed_amount_minor'] ?? Security::toMinorUnits($params['parsed_amount'] ?? 0),
                'sender_number'       => $params['sender_number'] ?? '',
                'receiver_number'     => $params['receiver_number'] ?? '',
                'reference'           => $params['parsed_reference'] ?? '',
                'verification_source' => 'android_sms',
                'raw_data'            => $params,
            ];

            $txnResult = TransactionManager::ingestTransaction($txnData);
            if ($txnResult['success']) {
                // 3. Immediately trigger matching engine
                $matchResult = MatchingEngine::processTransactionMatch((int) $txnResult['id']);
            }
        }

        return new \WP_REST_Response([
            'success'      => true,
            'sms_logged'   => true,
            'match_result' => $matchResult,
        ], 200);
    }

    /**
     * POST /devices/transactions/batch
     */
    public static function handleTransactionBatch(\WP_REST_Request $request): \WP_REST_Response {
        $device = $request->get_param('_device_context');
        $body = $request->get_json_params();
        $transactions = $body['transactions'] ?? [];

        $syncedIds = [];
        $matchResults = [];

        foreach ($transactions as $txn) {
            $txn['verification_source'] = 'android_sms';
            $res = TransactionManager::ingestTransaction($txn);

            if ($res['success']) {
                $syncedIds[] = $txn['local_id'] ?? $txn['transaction_id'];
                $matchRes = MatchingEngine::processTransactionMatch((int) $res['id']);
                $matchResults[] = $matchRes;
            } elseif (($res['code'] ?? '') === 'DUPLICATE_TRANSACTION') {
                $syncedIds[] = $txn['local_id'] ?? $txn['transaction_id'];
            }
        }

        return new \WP_REST_Response([
            'success'       => true,
            'synced_count'  => count($syncedIds),
            'synced_ids'    => $syncedIds,
            'match_results' => $matchResults,
        ], 200);
    }

    /**
     * GET /devices/config
     */
    public static function handleDeviceConfig(\WP_REST_Request $request): \WP_REST_Response {
        $wallets = WalletManager::getWallets(null, 'active');
        $cleanWallets = array_map(function ($w) {
            return [
                'provider'       => $w['provider'],
                'account_number' => $w['account_number'],
                'account_type'   => $w['account_type'],
                'account_label'  => $w['account_label'],
            ];
        }, $wallets);

        return new \WP_REST_Response([
            'success'   => true,
            'site_name' => get_bloginfo('name'),
            'wallets'   => $cleanWallets,
        ], 200);
    }

    /**
     * GET /health
     */
    public static function handleHealthCheck(\WP_REST_Request $request): \WP_REST_Response {
        return new \WP_REST_Response([
            'status'     => 'healthy',
            'plugin'     => 'Royal Pay',
            'version'    => ROYALPAY_VERSION,
            'licensed'   => LicenseManager::getInstance()->isLicensed(),
            'timestamp'  => current_time('mysql'),
        ], 200);
    }
}
