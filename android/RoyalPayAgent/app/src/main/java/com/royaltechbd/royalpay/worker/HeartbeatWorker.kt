package com.royaltechbd.royalpay.worker

import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.os.BatteryManager
import android.telephony.TelephonyManager
import android.util.Log
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import com.royaltechbd.royalpay.data.local.AppDatabase
import com.royaltechbd.royalpay.data.local.PreferencesManager
import com.royaltechbd.royalpay.data.remote.ApiClient
import com.royaltechbd.royalpay.data.remote.dto.HeartbeatRequest

class HeartbeatWorker(
    context: Context,
    workerParams: WorkerParameters
) : CoroutineWorker(context, workerParams) {

    override suspend fun doWork(): Result {
        val prefs = PreferencesManager(applicationContext)
        if (!prefs.isPaired()) {
            return Result.success()
        }

        val apiUrl = prefs.getApiUrl()
        val token = prefs.getDeviceToken()

        if (apiUrl.isEmpty() || token.isEmpty()) {
            return Result.failure()
        }

        try {
            val batteryPct = getBatteryPercentage(applicationContext)
            val networkType = getNetworkType(applicationContext)
            val simOperator = getSimOperator(applicationContext)

            val db = AppDatabase.getInstance(applicationContext)
            val queueLength = db.transactionDao().getUnsyncedCount()

            val req = HeartbeatRequest(
                batteryPercentage = batteryPct,
                networkType = networkType,
                simOperator = simOperator,
                queueLength = queueLength
            )

            val api = ApiClient.getService(apiUrl)
            val response = api.sendHeartbeat(token, req)

            if (response.isSuccessful) {
                prefs.setLastHeartbeat(System.currentTimeMillis())
                Log.i("RoyalPayHeartbeat", "Heartbeat transmitted: Battery $batteryPct%, Queue $queueLength")
                return Result.success()
            } else if (response.code() == 401 || response.code() == 403) {
                prefs.setPaired(false)
                return Result.failure()
            }
        } catch (e: Exception) {
            Log.e("RoyalPayHeartbeat", "Heartbeat error", e)
        }

        return Result.retry()
    }

    private fun getBatteryPercentage(context: Context): Int {
        val iFilter = IntentFilter(Intent.ACTION_BATTERY_CHANGED)
        val batteryStatus = context.registerReceiver(null, iFilter)
        val level = batteryStatus?.getIntExtra(BatteryManager.EXTRA_LEVEL, -1) ?: -1
        val scale = batteryStatus?.getIntExtra(BatteryManager.EXTRA_SCALE, -1) ?: -1
        return if (level >= 0 && scale > 0) {
            (level * 100 / scale)
        } else {
            100
        }
    }

    private fun getNetworkType(context: Context): String {
        val cm = context.getSystemService(Context.CONNECTIVITY_SERVICE) as? ConnectivityManager ?: return "Unknown"
        val activeNetwork = cm.activeNetwork ?: return "None"
        val caps = cm.getNetworkCapabilities(activeNetwork) ?: return "Unknown"
        return when {
            caps.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) -> "Wi-Fi"
            caps.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR) -> "Cellular"
            caps.hasTransport(NetworkCapabilities.TRANSPORT_ETHERNET) -> "Ethernet"
            else -> "Other"
        }
    }

    private fun getSimOperator(context: Context): String {
        val tm = context.getSystemService(Context.TELEPHONY_SERVICE) as? TelephonyManager ?: return "Cellular"
        val op = tm.networkOperatorName
        return if (!op.isNullOrBlank()) op else "Cellular"
    }
}
