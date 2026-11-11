# Royal Pay Payment Provider Integration Architecture

## 1. Provider Adapter Specification

Royal Pay uses an adapter design pattern to unify divergent Mobile Financial Service (MFS) provider protocols under a single strict domain contract:

```php
namespace RoyalPay\Providers;

interface ProviderInterface {
    public function getProviderSlug(): string;
    public function initiatePayment(array $params): ProviderResponse;
    public function verifyTransaction(string $transactionId, array $context = []): ProviderVerificationResult;
    public function getTransactionStatus(string $transactionId): ProviderStatusResult;
    public function processWebhook(array $headers, string $rawBody): ProviderWebhookResult;
    public function getRefundStatus(string $refundId): ProviderRefundResult;
    public function validateCredentials(array $credentials): bool;
    public function isLiveReady(): bool;
}
```

---

## 2. Supported Payment Providers & Verification Modalities

### A. bKash (bKash Limited)
- **Official API Protocol**: bKash Tokenized Checkout API v1.2.0-beta.
- **Authentication**: `app_key` + `app_secret` + `username` + `password` exchanging for a Bearer `id_token` with refresh token capability.
- **Workflow**:
  1. `grantToken`: Generate Bearer token using AES-256 encrypted merchant credentials.
  2. `createPayment`: Generate bKash payment URL and `paymentID`.
  3. `executePayment`: Finalize charge upon customer PIN/OTP entry.
  4. `queryPayment`: Check status by `paymentID` or `trxID`.
  5. `searchTransaction`: Retrieve transaction details by provider `trxID`.
- **Status When Credentials Missing**: Adapter remains in **`pending_credentials`** state. Production traffic is blocked and only documented sandbox endpoints are accessible when enabled.

### B. Nagad (Third Wave Technologies Limited / Postal Department)
- **Official API Protocol**: Nagad PGW API with RSA Public/Private Key asymmetric encryption.
- **Authentication**: Merchant ID, Public Key, Private Key, and merchant PGW callback verification.
- **Workflow**:
  1. `initialize`: Generate sensitive data block encrypted with Nagad's RSA Public Key and signed with Merchant Private Key.
  2. Nagad redirects to payment portal; customer completes payment.
  3. `verifyPayment`: Server-to-server callback verifies payment reference and order ID with Nagad verification endpoint.
- **Status When Credentials Missing**: Marked as **`pending_credentials`**. Fallback to Android SMS and Statement reconciliation mode under Manual Review.

### C. Rocket (Dutch-Bangla Bank PLC)
- **Official API Protocol**: DBBL NexusPay / Merchant API (SOAP/REST).
- **Integration Realities in Bangladesh**: Official DBBL direct merchant API approval typically requires physical bank agreement and static IP whitelisting.
- **Operational Strategy**:
  - Provider interface implemented with official DBBL specifications.
  - While merchant banking approval is pending, Rocket operates under **`pending_official_api`** status.
  - Transactions for Rocket are processed via **Method B (Android SMS Ingestion)** and **Method C (Statement Import)**, strictly routed to **Manual Review** by default.

---

## 3. Strict Integrity Rule: Zero Fabrication Policy

1. **No Fake Responses**: The platform will never mock or simulate successful payment confirmations in production mode.
2. **Credential Integrity**: Provider credentials are encrypted at rest with AES-256-GCM.
3. **Environment Separation**: Sandbox configurations point to official sandbox endpoints:
   - bKash Sandbox: `https://tokenized.sandbox.bka.sh/v1.2.0-beta`
   - Nagad Sandbox: `http://sandbox.mynagad.com:10080/remote-payment-gateway-1.0/api/dfs`
4. **Approval Safeguard**: Only official API verifications signed cryptographically by the provider can automatically mark a payment request as `paid`. SMS and spreadsheet imports are marked `review_required` by default.
