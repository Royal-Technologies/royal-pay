# Royal Pay Agent — Native Android Application

**Royal Pay Agent** is the native Android automation companion for the Royal Pay WordPress SaaS payment platform. It listens for incoming SMS from official Bangladesh MFS providers (bKash `16247`/`bKash`, Nagad `16167`/`Nagad`, Rocket `16216`/`Rocket`), parses the transaction ID, amount, and reference, stores them in an encrypted Room SQLite database, and synchronizes them to the WordPress REST API endpoint with exponential backoff via WorkManager.

---

## 1. Quick Build in Android Studio

1. Open **Android Studio** (Hedgehog, Iguana, Jellyfish, or newer).
2. Select **File > Open...** and browse to this directory:
   `wp-content/plugins/royal-pay/android/RoyalPayAgent`
3. Allow Gradle to sync dependencies automatically.
4. Select **Build > Build Bundle(s) / APK(s) > Build APK(s)**.
5. Once built, Android Studio will display a notification:
   `APK(s) generated successfully: locate`.
6. Copy the output `app-release.apk` or `app-debug.apk` to:
   `wp-content/plugins/royal-pay/apk/royal-pay-agent.apk`
   (Replacing the placeholder file).

---

## 2. Quick Command Line Build (Requires JDK 17+ and Android SDK)

```bash
cd android/RoyalPayAgent

# On Linux / macOS:
./gradlew assembleRelease

# On Windows (PowerShell):
.\gradlew.bat assembleRelease
```

The compiled APK will be located at:
`app/build/outputs/apk/release/app-release.apk`

---

## 3. Installing on Android Devices (Sideloading)

When installing private APKs on Android:
1. Transfer the `.apk` to the phone via USB, Google Drive, WhatsApp, or download directly from WordPress Admin: **Royal Pay > Devices > Download Royal Pay Agent (APK)**.
2. In Android Settings:
   - Go to **Settings > Apps > Special app access > Install unknown apps**.
   - Enable permission for the browser (Chrome) or File Manager you are using to open the APK.
3. Tap the `.apk` file and tap **Install**.
4. Open **Royal Pay Agent** on your phone.
5. Grant **SMS**, **Phone**, and **Camera** permissions when prompted.
6. Under phone **Battery Optimization Settings**, set Royal Pay Agent to **Unrestricted** (Don't optimize) so that Android does not put the background SMS listener to sleep.
7. Tap **Pair with QR Code** and scan the QR code displayed in your WordPress Admin under **Royal Pay > Devices**.
