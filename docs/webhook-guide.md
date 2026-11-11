# Royal Pay Outbound Webhook Developer Guide

## 1. Webhook Overview

Royal Pay notifies merchant systems (such as the WooCommerce Royal Pay Connector or custom enterprise backends) via signed HTTP `POST` webhooks whenever a payment lifecycle event occurs.

---

## 2. Supported Events

| Event Identifier | Trigger Condition |
|:---|:---|
| `payment.created` | New payment request initiated by merchant website. |
| `payment.processing` | Customer initiated payment attempt or preliminary txn captured. |
| `payment.verified` | Official API confirmed transaction is successful and legitimate. |
| `payment.paid` | Multi-factor matching satisfied; payment request officially completed. |
| `payment.failed` | Transaction verification failed or expired without payment. |
| `payment.reversed` | Chargeback or provider reversal detected on settled payment. |
| `payment.review_required` | SMS or statement match discrepancy flagged for manual review. |

---

## 3. Webhook Payload Schema

```json
{
  "event_id": "evt_7f8a9b0c1d2e3f4a5b6c7d8e",
  "event": "payment.paid",
  "created_at": "2026-11-11T14:35:10Z",
  "tenant_id": 1,
  "data": {
    "payment_id": 1042,
    "payment_uuid": "pay_9f4e2c8a1b0d4e3a8b1c7e5f6a9b0c2d",
    "external_order_id": "WC-10492",
    "payment_reference": "RPA-202611-849201",
    "transaction_id": "9K284L10",
    "provider": "bkash",
    "amount_minor": 150000,
    "amount_formatted": "1500.00 BDT",
    "currency": "BDT",
    "verification_source": "official_api",
    "receiver_wallet": "01700000000",
    "paid_at": "2026-11-11T14:35:05Z"
  }
}
```

---

## 4. Webhook Security & Verification

Every webhook transmission contains the following HTTP headers:
- `X-RoyalPay-Event`: The event type (e.g. `payment.paid`).
- `X-RoyalPay-Event-Id`: Unique event UUID to prevent duplicate processing.
- `X-RoyalPay-Timestamp`: UNIX timestamp of dispatch.
- `X-RoyalPay-Signature`: `hash_hmac('sha256', $timestamp . '.' . $raw_body, $webhook_secret)`.

### Merchant Verification (PHP Sample)
```php
$raw_payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_ROYALPAY_SIGNATURE'] ?? '';
$timestamp = $_SERVER['HTTP_X_ROYALPAY_TIMESTAMP'] ?? '';
$secret = 'whsec_merchant_secret_key...';

// 1. Prevent Replay Attacks (Max 5 minutes clock drift)
if (abs(time() - intval($timestamp)) > 300) {
    http_response_code(400);
    exit('Timestamp out of range.');
}

// 2. Verify HMAC Signature
$expected_signature = hash_hmac('sha256', $timestamp . '.' . $raw_payload, $secret);
if (!hash_equals($expected_signature, $signature)) {
    http_response_code(401);
    exit('Invalid webhook signature.');
}

// 3. Process Event Idempotently
$event = json_decode($raw_payload, true);
// Check if $event['event_id'] already processed...
```

---

## 5. Retry Mechanism & Dead-Letter Handling

If a merchant endpoint returns a non-2xx status code or times out (5-second timeout):
- **Attempt 1**: Immediate
- **Attempt 2**: After 1 minute
- **Attempt 3**: After 5 minutes
- **Attempt 4**: After 15 minutes
- **Attempt 5**: After 1 hour
- **Attempt 6**: After 6 hours

After 6 failed attempts, the event is marked `failed` in `rpa_webhook_events`. The SaaS dashboard provides a "Retry Webhook" button for immediate manual dispatch.
