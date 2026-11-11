# Royal Pay Merchant & Connector Integration Guide

## 1. WooCommerce Integration (Royal Pay Connector)

The **Royal Pay Connector** (`royal-pay-connector`) plugin seamlessly bridges WooCommerce checkout with the centralized SaaS platform.

### Key Capabilities:
- **WooCommerce High-Performance Order Storage (HPOS)**: Fully compatible with HPOS (`custom_order_tables`) and legacy post-based storage.
- **Official Order APIs**: Uses `wc_get_order()`, `$order->payment_complete()`, and `$order->add_order_note()`. Never executes raw SQL against WooCommerce tables.
- **Idempotency**: Retains processed `event_id` in order metadata (`_royal_pay_event_id`) to ensure `payment_complete()` is invoked exactly once per order.
- **Scheduled Reconciliation Fallback**: Periodically queries the central SaaS API for orders stuck in `pending` or `processing` state in case inbound webhooks were interrupted by merchant network downtime.

---

## 2. Configuration Settings in WordPress Admin

Navigate to **WooCommerce > Settings > Payments > Royal Pay**:
1. **Enable Royal Pay**: Check to activate at checkout.
2. **SaaS Base URL**: `https://pay.royaltechbd.com/wp-json/royal-pay/v1`
3. **Public API Key**: Provided in Tenant Dashboard (e.g. `rpa_live_...`).
4. **API Secret**: Shared secret for signing outgoing HMAC requests.
5. **Webhook Secret**: Secret used to verify incoming `payment.paid` webhooks.
6. **Payment Provider Logos**: Select whether to show bKash, Nagad, and Rocket logos at checkout.

---

## 3. Custom Order Adapter Interface (Non-WooCommerce PHP)

For custom PHP stores, Laravel e-commerce systems, or ERP systems, implement the `OrderAdapterInterface`:

```php
namespace RoyalPay\Connector\Orders;

interface OrderAdapterInterface {
    public function getOrder(string $orderId): ?array;
    public function getExpectedAmountMinor(string $orderId): int;
    public function getOrderStatus(string $orderId): string;
    public function markAsPaid(string $orderId, string $transactionId, string $provider): bool;
    public function addOrderNote(string $orderId, string $note): bool;
}
```

A reference implementation is included in `wordpress-connector/royal-pay-connector/includes/Orders/CustomOrderAdapter.php` and `sdk/examples/custom-order-integration.php`.
