# Royal Pay Automation SaaS

Production-grade, multi-tenant SaaS payment automation platform for Bangladesh Mobile Financial Services (bKash, Nagad, Rocket).

![License](https://img.shields.io/badge/license-Proprietary-blue.svg)
![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4.svg)
![WordPress](https://img.shields.io/badge/WordPress-6.4%2B-21759B.svg)
![WooCommerce](https://img.shields.io/badge/WooCommerce-HPOS%20Compatible-96588A.svg)
![Android](https://img.shields.io/badge/Android-Kotlin%20%7C%20Room%20%7C%20WorkManager-3DDC84.svg)

---

## 1. Executive Overview

**Royal Pay Automation** is a centralized, multi-tenant SaaS payment verification and reconciliation system. It decouples payment verification logic from client stores and order systems, serving multiple independent merchant websites, custom order management platforms, and WooCommerce shops.

The platform continuously intercepts, verifies, and reconciles BDT mobile financial service payments across three primary providers:
1. **bKash** (Tokenized API, Provider Webhooks, Android SMS listener, Statement import)
2. **Nagad** (PGW API, Callback verification, Android SMS listener, Statement import)
3. **Rocket** (NexusPay / Merchant API, Android SMS listener, Statement import)

### Core Architectural Rule
> **Zero Amount-Only Approvals**: The platform enforces strict multi-factor matching (Provider Transaction ID, Unique Reference, Wallet Identity, Amount Minor Units, Currency, Time Window, and Status). An amount match alone will **never** trigger payment approval.

---

## 2. Platform Architecture

```
                       ┌────────────────────────────────────────┐
                       │           Merchant Ecosystem           │
                       │  - WooCommerce Stores (Plugin)         │
                       │  - Custom PHP / Laravel / Node Stores   │
                       └───────────────────┬────────────────────┘
                                           │ HTTPS HMAC-SHA256
                                           ▼
┌──────────────────────────────────────────────────────────────────────────────┐
│                        Royal Pay Central SaaS Engine                         │
│                           pay.royaltechbd.com                                │
│                                                                              │
│  ┌───────────────────────┐  ┌──────────────────────┐  ┌──────────────────┐   │
│  │  Multi-Tenant Router  │  │  Rate Limiting / WAF  │  │ HMAC & JWT Auth  │   │
│  └───────────┬───────────┘  └──────────┬───────────┘  └─────────┬────────┘   │
│              └─────────────────────────┼────────────────────────┘            │
│                                        ▼                                     │
│  ┌────────────────────────────────────────────────────────────────────────┐  │
│  │                         Core Payment Engine                            │  │
│  │   - Payment Request Lifecycle & State Machine                          │  │
│  │   - Multi-Factor Idempotent Matching Engine                            │  │
│  │   - Atomic Balance & Status Mutex (InnoDB Row Locks)                   │  │
│  │   - Immutable Payment Audit Event Ledger                               │  │
│  └─────────────────────────────────────┬──────────────────────────────────┘  │
│                                        ▼                                     │
│  ┌────────────────────────────────────────────────────────────────────────┐  │
│  │                     Verification Adapter Pipeline                      │  │
│  │  ┌──────────────────┐  ┌───────────────────────┐  ┌──────────────────┐ │  │
│  │  │ Official API GW  │  │  Android SMS Ingest   │  │ Statement Parser │ │  │
│  │  │ (bKash/Nagad/DBBL)│ │  (WorkManager Queue)  │  │ (CSV/XLSX Reconc)│ │  │
│  │  └──────────────────┘  └───────────────────────┘  └──────────────────┘ │  │
│  └─────────────────────────────────────┬──────────────────────────────────┘  │
│                                        ▼                                     │
│  ┌────────────────────────────────────────────────────────────────────────┐  │
│  │                    Asynchronous Webhook Dispatcher                     │  │
│  │             (Signed Payloads, Exponential Backoff Retries)             │  │
│  └────────────────────────────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────────────────────┘
```

---

## 3. Directory Layout

- `backend/royal-pay-saas/`: The Central SaaS WordPress Plugin running on `pay.royaltechbd.com`.
- `wordpress-connector/royal-pay-connector/`: The client-side WordPress / WooCommerce plugin distributed to merchants.
- `android/RoyalPayAgent/`: Native Android Kotlin application running background SMS ingestion, Room DB offline queue, and WorkManager sync.
- `database/migrations/`: Versioned SQL migrations for all 28+ InnoDB tables.
- `sdk/php/`: Standalone PHP Client SDK for external custom e-commerce and order platforms.
- `docs/`: Comprehensive technical, architectural, API, and deployment documentation.
- `deployment/cyberpanel/`: OpenLiteSpeed, MariaDB, and CyberPanel deployment automation scripts.

---

## 4. Key Security & Resilience Guarantees

1. **Integer Minor Units**: All monetary amounts are handled strictly as integer minor units (`BDT 1500.00` = `150000`). Floating-point arithmetic is prohibited.
2. **Strict Multi-Tenancy**: Every table record is bound to a validated `tenant_id`. Queries enforce tenant scoping at the repository level.
3. **Cryptographic Integrity**: Outbound webhooks and inbound API requests use constant-time `hash_equals()` HMAC-SHA256 signature verification with millisecond timestamp nonces.
4. **Hardware Security**: Device tokens are isolated and backed by Android Keystore hardware-backed keys.
5. **Idempotency**: All payment transactions and webhook ingestions use unique composite database keys with atomic mutexes to guarantee zero duplicate charges.

---

## 5. Getting Started & Quick Links

- [System Architecture](docs/architecture.md)
- [Database Schema & Migrations](docs/database-schema.md)
- [REST API Reference](docs/api-reference.md)
- [Security Threat Model](docs/security.md)
- [Android Agent Guide](docs/android-app.md)
- [Payment Provider Integration](docs/provider-integration.md)
- [Webhook Developer Guide](docs/webhook-guide.md)
- [CyberPanel Deployment Guide](docs/deployment.md)

---
© 2026 RoyalTechBD. All rights reserved.
