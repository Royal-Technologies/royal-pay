# Changelog — Royal Pay

All notable changes to the **Royal Pay** plugin will be documented in this file.

---

## [1.0.0] — 2026-11-11
### Initial Production Release

#### Added
- **WooCommerce Payment Gateway (HPOS Compatible)**: Full checkout integration with bKash, Nagad, and Rocket support, receiver wallet display, instructions, and customer TrxID submission.
- **Royal BatchMate Compatibility (`RoyalBatchmateAdapter`)**: Automatic matching and approval for Event Applications and membership fee payments in the Royal BatchMate plugin.
- **RoyalAuth v2.0 Licensing Engine**: Resilient Local-First architecture with Ed25519 detached digital signature verification (`vE7PkX4ho5A4ZVWH5KwToDcv1cPIY6FJsNU4tDjbNIw=`) and 24-hour lightweight version ping.
- **Strict Anti-Fraud Telephony Filtering**: Rejects any non-whitelisted sender IDs (only accepts official `bKash`/`16247`, `Nagad`/`16167`, and `Rocket`/`16216` channels).
- **Zero Amount-Only Approvals**: Strict multi-factor matching engine enforcing exact TrxID, reference, and integer minor units amount validation with atomic database row locks (`SELECT ... FOR UPDATE`).
- **Android Agent Integration (Royal Pay Agent)**: 1-click QR code device pairing, Room DB offline queue, WorkManager persistent background synchronization, and battery/network telemetry.
- **1-Click APK Download**: Direct APK download button in WordPress Admin under **Royal Pay > Devices**.
- **Admin Dashboard & Ledger**: Comprehensive overview cards, transaction ledger, SMS logs, wallet manager, device manager, and RoyalAuth license activation.
- **Standard Royal Technologies Plugin Updater**: GitHub auto-update integration via `royal-pay.json` and GitHub releases.
- **Company Branding**: Official details for Royal Technologies (Helpline: 01552333272, Address: D - 408, Housing Estate, Kushtia, Web: https://www.royaltechbd.com).
