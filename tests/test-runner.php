<?php

// Test Bootstrap
define('ABSPATH', dirname(__DIR__) . '/');
define('ROYALPAY_VERSION', '1.0.0');
define('ROYALPAY_PLUGIN_DIR', dirname(__DIR__) . '/');

// Mock WordPress functions if running standalone in CLI
if (!function_exists('add_action')) {
    function add_action($tag, $callback, $priority = 10, $accepted_args = 1) {}
}
if (!function_exists('add_filter')) {
    function add_filter($tag, $callback, $priority = 10, $accepted_args = 1) {}
}
if (!function_exists('register_activation_hook')) {
    function register_activation_hook($file, $callback) {}
}
if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook($file, $callback) {}
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) { return trim(strip_tags((string)$str)); }
}
if (!function_exists('current_time')) {
    function current_time($type) { return date('Y-m-d H:i:s'); }
}
if (!function_exists('get_option')) {
    function get_option($key, $default = false) { return $default; }
}
if (!function_exists('update_option')) {
    function update_option($key, $value) { return true; }
}
if (!function_exists('delete_option')) {
    function delete_option($key) { return true; }
}

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'RoyalPay\\';
    $base_dir = __DIR__ . '/../includes/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    $file = $base_dir . str_replace('\\', '/', substr($class, $len)) . '.php';
    if (file_exists($file)) require_once $file;
});

echo "========================================\n";
echo "   ROYAL PAY AUTOMATION TEST SUITE      \n";
echo "========================================\n\n";

$testsPassed = 0;
$testsFailed = 0;

function assertTest(string $name, bool $condition) {
    global $testsPassed, $testsFailed;
    if ($condition) {
        echo "[PASS] " . $name . "\n";
        $testsPassed++;
    } else {
        echo "[FAIL] " . $name . "\n";
        $testsFailed++;
    }
}

// 1. Test Monetary Minor Units Calculation
$minor1 = \RoyalPay\Core\Security::toMinorUnits(1500.00);
$minor2 = \RoyalPay\Core\Security::toMinorUnits("2450.75");
assertTest("Monetary Conversion (1500 BDT -> 150000 minor units)", $minor1 === 150000);
assertTest("Monetary Conversion (2450.75 BDT -> 245075 minor units)", $minor2 === 245075);
assertTest("Monetary Formatting (150000 -> '1,500.00')", \RoyalPay\Core\Security::fromMinorUnits(150000) === "1,500.00");

// 2. Test AES-256-GCM Encryption & Decryption
$secretText = "my_bkash_api_secret_key_12345";
$encrypted = \RoyalPay\Core\Security::encrypt($secretText);
$decrypted = \RoyalPay\Core\Security::decrypt($encrypted);
assertTest("AES-256-GCM Encryption produces non-empty ciphertext", !empty($encrypted) && $encrypted !== $secretText);
assertTest("AES-256-GCM Decryption recovers original plaintext", $decrypted === $secretText);

// 3. Test Strict MFS Sender Whitelist Filtering
assertTest("Whitelist accepts 'bKash'", \RoyalPay\Transactions\TransactionManager::isValidSender('bKash'));
assertTest("Whitelist accepts '16247'", \RoyalPay\Transactions\TransactionManager::isValidSender('16247'));
assertTest("Whitelist accepts 'Nagad'", \RoyalPay\Transactions\TransactionManager::isValidSender('Nagad'));
assertTest("Whitelist accepts '16167'", \RoyalPay\Transactions\TransactionManager::isValidSender('16167'));
assertTest("Whitelist accepts 'Rocket'", \RoyalPay\Transactions\TransactionManager::isValidSender('Rocket'));
assertTest("Whitelist accepts '16216'", \RoyalPay\Transactions\TransactionManager::isValidSender('16216'));

// Reject Personal Mobile Numbers (Fake SMS Defense)
assertTest("Whitelist strictly REJECTS personal SIM '+8801711000000'", !\RoyalPay\Transactions\TransactionManager::isValidSender('+8801711000000'));
assertTest("Whitelist strictly REJECTS personal SIM '01819000000'", !\RoyalPay\Transactions\TransactionManager::isValidSender('01819000000'));
assertTest("Whitelist strictly REJECTS unknown text sender 'INFO'", !\RoyalPay\Transactions\TransactionManager::isValidSender('INFO'));

// 4. Test Royal BatchMate Compatibility Detection
$isBmActive = \RoyalPay\Compatibility\RoyalBatchmateAdapter::isBatchmateActive();
assertTest("RoyalBatchmateAdapter isBatchmateActive() runs without fatal error", is_bool($isBmActive));

echo "\n----------------------------------------\n";
echo "Tests Passed: $testsPassed | Tests Failed: $testsFailed\n";
echo "----------------------------------------\n";

if ($testsFailed > 0) {
    exit(1);
}
