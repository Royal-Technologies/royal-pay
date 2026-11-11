<?php

namespace RoyalPay\Gateway;

defined('ABSPATH') || exit;

use RoyalPay\Wallets\WalletManager;
use RoyalPay\Core\Security;
use RoyalPay\Matching\MatchingEngine;
use RoyalPay\Transactions\TransactionManager;

/**
 * WooCommerce Payment Gateway for Royal Pay MFS (bKash, Nagad, Rocket)
 */
class WooCommerceGateway extends \WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'royalpay';
        $this->icon               = apply_filters('royalpay_gateway_icon', ROYALPAY_PLUGIN_URL . 'assets/images/mfs-logo.png');
        $this->has_fields         = true;
        $this->method_title       = __('bKash / Nagad / Rocket (Royal Pay)', 'royalpay');
        $this->method_description = __('Automated payment gateway for bKash, Nagad, and Rocket with instant order verification.', 'royalpay');

        $this->supports = ['products', 'default_credit_card_form'];

        // Load settings
        $this->init_form_fields();
        $this->init_settings();

        $this->title        = $this->get_option('title', 'bKash / Nagad / Rocket');
        $this->description  = $this->get_option('description', 'Pay via bKash, Nagad, or Rocket and enter your Transaction ID (TrxID).');
        $this->instructions = $this->get_option('instructions', '');

        // Actions
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_thankyou_' . $this->id, [$this, 'thankyou_page']);
    }

    /**
     * Gateway Admin Settings
     */
    public function init_form_fields(): void {
        $this->form_fields = [
            'enabled' => [
                'title'   => __('Enable/Disable', 'royalpay'),
                'type'    => 'checkbox',
                'label'   => __('Enable Royal Pay Gateway', 'royalpay'),
                'default' => 'yes',
            ],
            'title' => [
                'title'       => __('Title', 'royalpay'),
                'type'        => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'royalpay'),
                'default'     => __('bKash / Nagad / Rocket (Automated)', 'royalpay'),
                'desc_tip'    => true,
            ],
            'description' => [
                'title'       => __('Description', 'royalpay'),
                'type'        => 'textarea',
                'description' => __('Payment method description that the customer will see on checkout.', 'royalpay'),
                'default'     => __('Pay using your bKash, Nagad, or Rocket account. Complete your payment and provide the Transaction ID below.', 'royalpay'),
            ],
            'instructions' => [
                'title'       => __('Instructions', 'royalpay'),
                'type'        => 'textarea',
                'description' => __('Instructions that will be added to the thank you page and emails.', 'royalpay'),
                'default'     => __('Thank you for your payment. Your order will be processed automatically as soon as your transaction is verified.', 'royalpay'),
            ],
        ];
    }

    /**
     * Render payment fields at checkout
     */
    public function payment_fields(): void {
        if ($this->description) {
            echo wpautop(wp_kses_post($this->description));
        }

        $wallets = WalletManager::getWallets(null, 'active');
        if (empty($wallets)) {
            echo '<div class="woocommerce-error">' . esc_html__('No active MFS wallets configured. Please contact store support.', 'royalpay') . '</div>';
            return;
        }

        // Group wallets by provider
        $grouped = [];
        foreach ($wallets as $w) {
            $grouped[$w['provider']][] = $w;
        }

        ?>
        <div id="royalpay-checkout-wrapper" style="background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:16px; margin-top:12px;">
            <p style="font-weight:600; margin-bottom:8px;"><?php esc_html_e('1. Select Payment Method:', 'royalpay'); ?></p>
            <div style="display:flex; gap:12px; margin-bottom:14px; flex-wrap:wrap;">
                <?php foreach (['bkash' => 'bKash', 'nagad' => 'Nagad', 'rocket' => 'Rocket'] as $slug => $name): ?>
                    <?php if (!empty($grouped[$slug])): ?>
                        <label style="border:1px solid #d1d5db; border-radius:6px; padding:8px 14px; background:#fff; cursor:pointer; display:flex; align-items:center; gap:6px;">
                            <input type="radio" name="royalpay_provider" value="<?php echo esc_attr($slug); ?>" <?php checked($slug, 'bkash'); ?> required />
                            <strong><?php echo esc_html($name); ?></strong>
                        </label>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <div id="royalpay-wallet-instructions" style="background:#fff; border:1px solid #e5e7eb; border-radius:6px; padding:12px; margin-bottom:14px;">
                <?php foreach ($grouped as $slug => $wList): ?>
                    <div class="royalpay-provider-box" data-provider="<?php echo esc_attr($slug); ?>" style="<?php echo $slug === 'bkash' ? '' : 'display:none;'; ?>">
                        <p style="margin:0 0 6px 0; font-size:13px; color:#4b5563;">
                            <?php esc_html_e('Send money to our official number:', 'royalpay'); ?>
                        </p>
                        <?php foreach ($wList as $w): ?>
                            <div style="font-size:15px; font-weight:700; color:#111827; margin-bottom:4px;">
                                📱 <?php echo esc_html($w['account_number']); ?>
                                <span style="font-size:12px; font-weight:normal; background:#e0e7ff; color:#3730a3; padding:2px 6px; border-radius:4px; margin-left:6px;">
                                    <?php echo esc_html(ucfirst($w['account_type'])); ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                        <p style="font-size:12px; color:#6b7280; margin:6px 0 0 0;">
                            💡 <?php esc_html_e('Use your Order ID as the payment reference if prompted.', 'royalpay'); ?>
                        </p>
                    </div>
                <?php endforeach; ?>
            </div>

            <p style="font-weight:600; margin-bottom:8px;"><?php esc_html_e('2. Enter Transaction Information:', 'royalpay'); ?></p>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                <div>
                    <label style="display:block; font-size:12px; color:#374151; margin-bottom:4px;">
                        <?php esc_html_e('Sender Mobile Number', 'royalpay'); ?> <span style="color:#ef4444;">*</span>
                    </label>
                    <input type="text" name="royalpay_sender_number" placeholder="017XXXXXXXX" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;" required />
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:#374151; margin-bottom:4px;">
                        <?php esc_html_e('Transaction ID (TrxID)', 'royalpay'); ?> <span style="color:#ef4444;">*</span>
                    </label>
                    <input type="text" name="royalpay_trxid" placeholder="e.g. 9K284L10" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px; text-transform:uppercase;" required />
                </div>
            </div>
        </div>

        <script>
        document.querySelectorAll('input[name="royalpay_provider"]').forEach(function(radio) {
            radio.addEventListener('change', function() {
                var selected = this.value;
                document.querySelectorAll('.royalpay-provider-box').forEach(function(box) {
                    box.style.display = (box.dataset.provider === selected) ? 'block' : 'none';
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Validate checkout payment fields
     */
    public function validate_fields(): bool {
        $provider = sanitize_text_field($_POST['royalpay_provider'] ?? '');
        $sender = preg_replace('/[^0-9]/', '', sanitize_text_field($_POST['royalpay_sender_number'] ?? ''));
        $trxid = strtoupper(trim(sanitize_text_field($_POST['royalpay_trxid'] ?? '')));

        if (empty($provider)) {
            wc_add_notice(__('Please select your MFS provider (bKash, Nagad, or Rocket).', 'royalpay'), 'error');
            return false;
        }

        if (empty($sender) || strlen($sender) < 11) {
            wc_add_notice(__('Please enter a valid 11-digit sender mobile number.', 'royalpay'), 'error');
            return false;
        }

        if (empty($trxid) || strlen($trxid) < 5) {
            wc_add_notice(__('Please enter a valid Transaction ID (TrxID).', 'royalpay'), 'error');
            return false;
        }

        return true;
    }

    /**
     * Process order checkout
     */
    public function process_payment($order_id): array {
        $order = wc_get_order($order_id);

        $provider = sanitize_text_field($_POST['royalpay_provider'] ?? '');
        $sender   = preg_replace('/[^0-9]/', '', sanitize_text_field($_POST['royalpay_sender_number'] ?? ''));
        $trxid    = strtoupper(trim(sanitize_text_field($_POST['royalpay_trxid'] ?? '')));
        $reference = 'RP-' . $order_id;

        // Save order metadata
        $order->update_meta_data('_royalpay_provider', $provider);
        $order->update_meta_data('_royalpay_sender_number', $sender);
        $order->update_meta_data('_royalpay_customer_trxid', $trxid);
        $order->update_meta_data('_royalpay_reference', $reference);
        $order->save();

        // Mark as on-hold pending verification
        $order->update_status('on-hold', sprintf(
            __('Royal Pay: Awaiting transaction verification. Provider: %s | Sender: %s | TrxID: %s', 'royalpay'),
            strtoupper($provider),
            $sender,
            $trxid
        ));

        // Check if matching transaction already arrived via Android SMS!
        $existingTxn = TransactionManager::getTransactionByTrxId($provider, $trxid);
        if ($existingTxn && $existingTxn['status'] === 'unmatched') {
            MatchingEngine::processTransactionMatch((int) $existingTxn['id']);
        }

        // Empty cart
        WC()->cart->empty_cart();

        return [
            'result'   => 'success',
            'redirect' => $this->get_return_url($order),
        ];
    }

    /**
     * Output for the order received / thank you page
     */
    public function thankyou_page($order_id): void {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $trxid = $order->get_meta('_royalpay_customer_trxid');
        $provider = $order->get_meta('_royalpay_provider');
        $isPaid = $order->is_paid();

        echo '<div style="margin:20px 0; padding:16px; border-radius:8px; background:' . ($isPaid ? '#ecfdf5; border:1px solid #a7f3d0;' : '#fffbeb; border:1px solid #fde68a;') . '">';
        if ($isPaid) {
            echo '<h4 style="color:#065f46; margin:0 0 6px 0;">🎉 ' . esc_html__('Payment Verified & Completed!', 'royalpay') . '</h4>';
            echo '<p style="color:#047857; margin:0;">' . sprintf(esc_html__('Your %s payment with TrxID %s has been automatically verified.', 'royalpay'), strtoupper($provider), '<strong>' . esc_html($trxid) . '</strong>') . '</p>';
        } else {
            echo '<h4 style="color:#92400e; margin:0 0 6px 0;">⏳ ' . esc_html__('Awaiting SMS Verification', 'royalpay') . '</h4>';
            echo '<p style="color:#b45309; margin:0;">' . sprintf(esc_html__('We received your TrxID (%s). Our automated agent is verifying the transaction with %s. Your order status will update momentarily.', 'royalpay'), '<strong>' . esc_html($trxid) . '</strong>', strtoupper($provider)) . '</p>';
        }
        echo '</div>';
    }
}
