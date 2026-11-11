# Royal Pay Agent (Android Application) Specification

**Application Name**: Royal Pay Agent  
**Package Name**: `com.royaltechbd.royalpay`  
**Platform**: Android (Kotlin, Min SDK 26 - Android 8.0 Oreo, Target SDK 34 - Android 14)  
**Distribution**: Private Enterprise APK / Direct Sideload  

---

## 1. Architectural Overview

```mermaid
flowchart TD
    subgraph AndroidOS["Android System Layer"]
        SMSReceiver["Telephony BroadcastReceiver\n(SMS_RECEIVED / SMS_DELIVERED)"]
        Keystore["Android Keystore System\n(Hardware-Backed AES-256)"]
        WorkMgr["WorkManager\n(Persistent Background Tasks)"]
    end

    subgraph AppCore["Royal Pay Agent Core"]
        ParserEngine["Provider SMS Parser Engine\n(bKash, Nagad, Rocket)"]
        RoomDB[("Room Local Database\n(Encrypted SQLite)")]
        SyncWorker["Transaction Sync Worker\n(Exponential Backoff)"]
        HeartbeatWorker["Device Heartbeat Worker\n(Battery, Network, SIM Status)"]
        ApiClient["Retrofit2 / OkHttp3 Client\n(TLS 1.3 + Pinning + Token Auth)"]
    end

    subgraph CentralSaaS["Royal Pay SaaS Platform"]
        SaaSEndpoint["POST /devices/transactions/batch\nPOST /devices/heartbeat"]
    end

    SMSReceiver -->|Raw PDU & Sender| ParserEngine
    ParserEngine -->|Parsed Normalized Txn| RoomDB
    WorkMgr --> SyncWorker
    WorkMgr --> HeartbeatWorker

    SyncWorker -->|Read Unsynced Txns| RoomDB
    SyncWorker -->|Batch Ingestion Request| ApiClient
    ApiClient -->|Signed HTTPS Request| SaaSEndpoint
    SaaSEndpoint -->>|Ack Synced IDs| ApiClient
    ApiClient -->|Update is_synced = 1| RoomDB

    Keystore -->|Device Token & Private Key| ApiClient
```

---

## 2. SMS Parsing Architecture

The app uses strict regular expressions matching official MFS sender IDs:

| Provider | Official Sender IDs | Sample Formats Captured |
|:---|:---|:---|
| **bKash** | `bKash`, `16247` | `"You have received Tk 1,500.00 from 017XXXXXXXX. Fee Tk 0.00. Balance Tk 15,200.00. TrxID 9K284L10 at 11/11/2026 14:30. Ref: RPA-202611-849201"` |
| **Nagad** | `Nagad`, `16167` | `"Money Received: Amount: Tk 1,500.00 Sender: 018XXXXXXXX TxnID: 71NB482K Date: 11/11/2026 14:31 Ref: RPA-202611-849201 Balance: Tk 12,000.00"` |
| **Rocket** | `16216`, `Rocket` | `"Received Tk 1,500.00 from 019XXXXXXXX to A/C 017XXXXXXXX9. TxnID: 98124712 Date: 11-NOV-26 14:32:10. Ref: RPA-202611-849201"` |

### Normalized SMS Transaction Model:
- `provider`: `bkash`, `nagad`, or `rocket`
- `transaction_id`: Unique alphanumeric transaction ID (e.g. `9K284L10`)
- `amount_minor`: Integer minor units (e.g. `150000`)
- `wallet_number`: Destination SIM number / wallet account
- `sender_number`: Masked/unmasked customer mobile number
- `reference`: Captured payment reference string (e.g. `RPA-202611-849201`)
- `raw_message_hash`: SHA-256 hash of original SMS body to prevent duplicate processing
- `confidence`: Confidence score (0.0 to 1.0)
- `parse_status`: `valid`, `partial`, or `review_required`

---

## 3. Resilience & Offline Guarantees

1. **Room Database Persistence**: Every captured SMS is stored locally before network transmission. If network connectivity is lost, transactions remain safely in the queue (`is_synced = 0`).
2. **Exponential Backoff**: WorkManager handles network retries with exponential backoff (`BackoffPolicy.EXPONENTIAL`).
3. **No Message Deletion**: Unsynchronized records are never purged until acknowledged by the SaaS platform.
4. **Heartbeat & Telemetry**: Every 15 minutes, a lightweight heartbeat is transmitted with battery percentage, network type (Wi-Fi/Cellular), SIM operator, and unsynced queue length.

---

## 4. Building the APK & Device Installation Troubleshooting

### Why did Android say "Can't install app" or "Parse Error"?
If you attempted to install `apk/royal-pay-agent.apk` directly from the initial repository download, Android rejected it because that initial file was a 438-byte placeholder stub without compiled DEX bytecode (`classes.dex`), binary resources (`resources.arsc`), or APK signatures (`v1/v2/v3`).

### Step-by-Step: How to Build the Real APK
1. The complete native Android Kotlin source code is located at:
   `wp-content/plugins/royal-pay/android/RoyalPayAgent/`
2. Open this directory in **Android Studio**.
3. Click **Build > Build Bundle(s) / APK(s) > Build APK(s)**.
4. Once compilation finishes, copy the generated `.apk` from `app/build/outputs/apk/release/` to `wp-content/plugins/royal-pay/apk/royal-pay-agent.apk`.
5. Download the APK to your phone.

### Android Device Sideloading Requirements
Modern Android versions (Android 8 through 14+) require two phone settings:
1. **Allow Unknown Apps**: In phone **Settings > Apps > Special App Access > Install Unknown Apps**, allow the browser (Chrome) or File Manager to install APKs.
2. **Disable Battery Optimization**: In phone **Settings > Battery > Battery Optimization**, select `Royal Pay Agent` and choose **"Don't Optimize / Unrestricted"** so Android does not kill the background SMS listener worker.

### No-Code Webhook Alternative (Instant Setup)
If you need payment SMS automation running immediately without building an APK, you can install any open-source or commercial Android SMS Forwarder (e.g. *SMS Forwarder* or *MacroDroid*) and forward incoming SMS to:
`POST https://your-site.com/wp-json/royal-pay/v1/devices/sms/ingest`
with header `X-Device-Token: <YOUR_DEVICE_TOKEN>`.
