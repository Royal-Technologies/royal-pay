# Royal Pay Operational Troubleshooting & Diagnostics Guide

## 1. Common Diagnostics & Health Checks

### A. Health & Status Check Endpoint
System administrators can verify the central SaaS API status at:
`GET https://pay.royaltechbd.com/wp-json/royal-pay/v1/health`
Checks performed:
- Database connectivity and InnoDB table accessibility
- Background job queue backlog status
- Cryptographic key vault integrity
- System cron execution heartbeat

---

## 2. Troubleshooting Matrix

| Problem | Root Cause | Solution |
|:---|:---|:---|
| **`401 Unauthorized: Invalid HMAC Signature`** | Clock drift > 300s or mismatched secret key | Verify merchant server NTP sync; confirm merchant is using the active API Secret from Tenant Dashboard. |
| **`409 Conflict: DUPLICATE_TRANSACTION`** | Provider TrxID has already been settled for another order | Inspect `rpa_transactions` and `rpa_payment_matches`. Check if customer attempted to reuse a payment SMS. |
| **Payment Status Stuck in `processing`** | Webhook dropped or SMS was flagged for review | Check `rpa_webhook_deliveries` log; if SMS verification was used, inspect `rpa_reconciliation/unmatched` for manual approval. |
| **Android Agent Not Syncing SMS** | WorkManager throttled by OS battery optimization; missing SMS permission | Disable Android battery optimization for `Royal Pay Agent`; verify SMS permission is granted in Settings > Apps. |
| **Webhooks Failing Delivery to Merchant** | Merchant site returning 500 or timing out (> 5s) | Check merchant server logs. Click "Retry Webhook" in SaaS Dashboard once merchant site is online. |
| **WooCommerce Order Not Marked Paid** | Inbound webhook blocked by security plugin (Wordfence, Cloudflare) | Whitelist SaaS server IP in merchant firewall; verify webhook signature in WooCommerce Royal Pay settings. |
