# Royal Pay REST API v1 Reference & Contract

Base URL:
`https://pay.royaltechbd.com/wp-json/royal-pay/v1`

All requests and responses use `application/json; charset=UTF-8`.
All monetary amounts are represented in integer minor units (1 BDT = 100).

---

## 1. Authentication Headers

```http
X-RoyalPay-Key: rpa_live_5f9a7d2b8e1c4a0f
X-RoyalPay-Timestamp: 1794473038
X-RoyalPay-Nonce: 4e2c8a1b-9f0e-4d3a-8b1c-7e5f6a9b0c2d
X-RoyalPay-Signature: 8f4a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a
Idempotency-Key: 9b2d8e4a-5c1f-4a3b-8d0e-2f7a6b1c9e0d (Optional for POST)
```

---

## 2. API Endpoint Matrix

| Method | Endpoint | Scopes / Auth | Description |
|:---|:---|:---|:---|
| **POST** | `/tenants/register` | Super Admin / Public | Register a new tenant merchant account |
| **GET** | `/tenants/me` | `tenant:read` | Retrieve authenticated tenant details, quotas, and status |
| **POST** | `/sites/register` | `sites:write` | Register a merchant website (WooCommerce/custom) |
| **GET** | `/sites` | `sites:read` | List registered merchant websites |
| **POST** | `/sites/{id}/disconnect` | `sites:write` | Disconnect or revoke a merchant site |
| **GET** | `/wallets` | `wallets:read` | List tenant configured MFS wallets |
| **POST** | `/wallets` | `wallets:write` | Register a new MFS wallet (bKash/Nagad/Rocket) |
| **PUT** | `/wallets/{id}` | `wallets:write` | Update wallet limits or parameters |
| **POST** | `/wallets/{id}/verify` | `wallets:write` | Test credentials or verify active status |
| **POST** | `/wallets/{id}/disable` | `wallets:write` | Disable a wallet |
| **POST** | `/devices/register` | `devices:write` | Register a new Android Agent device |
| **GET** | `/devices/config` | Device Token | Retrieve active device config and assigned wallets |
| **POST** | `/devices/heartbeat` | Device Token | Send device heartbeat, battery, network status |
| **POST** | `/devices/sms/batch` | Device Token | Synchronize batch parsed SMS records |
| **POST** | `/devices/transactions/batch` | Device Token | Ingest offline transactions from device Room DB |
| **POST** | `/devices/{id}/revoke` | `devices:write` | Revoke an Android agent device |
| **POST** | `/payments` | `payments:write` | Create a new payment request |
| **GET** | `/payments/{id}` | `payments:read` | Retrieve payment request by ID |
| **GET** | `/payments/reference/{reference}` | `payments:read` | Retrieve payment request by reference |
| **POST** | `/payments/{id}/verify` | `payments:write` | Trigger real-time provider verification check |
| **POST** | `/payments/{id}/cancel` | `payments:write` | Cancel a pending payment request |
| **GET** | `/transactions` | `transactions:read` | Query ingested transactions with filters |
| **GET** | `/transactions/{id}` | `transactions:read` | Retrieve specific transaction details |
| **POST** | `/providers/{provider}/connect` | `providers:write` | Configure provider credentials |
| **POST** | `/providers/{provider}/webhook` | Public (Signed) | Provider callback / webhook receiver |
| **GET** | `/providers/{provider}/status` | `providers:read` | Get provider integration status (Active/Pending) |
| **POST** | `/statements/import` | `statements:write` | Upload CSV/XLSX statement for reconciliation |
| **GET** | `/statements/{id}` | `statements:read` | Get statement parsing & reconciliation summary |
| **POST** | `/statements/{id}/reconcile`| `statements:write` | Run reconciliation matching on statement rows |
| **GET** | `/reconciliation/unmatched` | `reconciliation:read` | List unmatched payment requests and transactions |
| **POST** | `/reconciliation/{id}/review` | `reconciliation:write`| Finance Manager manual approval/rejection |
| **GET** | `/plans` | Public | List available subscription plans and limits |
| **GET** | `/subscription` | `subscription:read` | Get active tenant subscription and usage counters |
| **GET** | `/webhooks` | `webhooks:read` | List registered webhook endpoints |
| **POST** | `/webhooks` | `webhooks:write` | Register a new outbound webhook endpoint |
| **POST** | `/webhooks/{id}/rotate-secret`| `webhooks:write` | Rotate HMAC webhook signing secret |

---

## 3. Standard Request & Response Schemas

### Example: Create Payment Request (`POST /payments`)
**Request Body:**
```json
{
  "site_id": 12,
  "external_order_id": "WC-10492",
  "amount_minor": 150000,
  "currency": "BDT",
  "provider": "bkash",
  "customer_phone": "01711000000",
  "customer_email": "customer@example.com",
  "redirect_url": "https://merchant-store.com/checkout/order-received/10492",
  "webhook_url": "https://merchant-store.com/wp-json/royal-pay-connector/v1/webhook",
  "expires_in_seconds": 1800
}
```

**Response (HTTP 201 Created):**
```json
{
  "success": true,
  "data": {
    "payment_uuid": "pay_9f4e2c8a1b0d4e3a8b1c7e5f6a9b0c2d",
    "payment_reference": "RPA-202611-849201",
    "status": "pending",
    "amount_minor": 150000,
    "amount_formatted": "1500.00 BDT",
    "currency": "BDT",
    "provider": "bkash",
    "assigned_wallet": {
      "account_number": "01700000000",
      "account_type": "merchant"
    },
    "payment_url": "https://pay.royaltechbd.com/checkout/pay_9f4e2c8a1b0d4e3a8b1c7e5f6a9b0c2d",
    "expires_at": "2026-11-11T09:15:00Z",
    "created_at": "2026-11-11T08:45:00Z"
  }
}
```

### Standard Error Response Format
```json
{
  "success": false,
  "error": {
    "code": "DUPLICATE_TRANSACTION",
    "message": "Transaction ID 9K284L10 has already been settled for payment RPA-202610-109283.",
    "status": 409,
    "details": {
      "provider": "bkash",
      "transaction_id": "9K284L10"
    }
  }
}
```
