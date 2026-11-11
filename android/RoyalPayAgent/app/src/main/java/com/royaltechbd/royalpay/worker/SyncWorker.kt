package com.royaltechbd.royalpay.worker

import android.content.Context
import android.util.Log
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import com.royaltechbd.royalpay.data.local.AppDatabase
import com.royaltechbd.royalpay.data.local.PreferencesManager
import com.royaltechbd.royalpay.data.remote.ApiClient
import com.royaltechbd.royalpay.data.remote.dto.SmsIngestRequest
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

class SyncWorker(
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

        val db = AppDatabase.getInstance(applicationContext)
        val unsynced = db.transactionDao().getUnsynced()

        if (unsynced.isEmpty()) {
            return Result.success()
        }

        val api = ApiClient.getService(apiUrl)
        val dateFormat = SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US)

        for (txn in unsynced) {
            try {
                val req = SmsIngestRequest(
                    sender = txn.provider,
                    body = txn.rawBody,
                    parsedProvider = txn.provider,
                    parsedTrxId = txn.transactionId,
                    parsedAmountMinor = txn.amountMinor,
                    parsedReference = txn.reference,
                    senderNumber = txn.senderNumber,
                    receiverNumber = txn.receiverNumber,
                    parseStatus = "valid",
                    receivedAt = dateFormat.format(Date(txn.createdAt))
                )

                val response = api.ingestSms(token, req)

                if (response.isSuccessful && response.body()?.success == true) {
                    db.transactionDao().markSynced(txn.id)
                    Log.i("RoyalPaySyncWorker", "Synced transaction ${txn.transactionId} successfully.")
                } else if (response.code() == 403 || response.code() == 401) {
                    // Token revoked
                    prefs.setPaired(false)
                    return Result.failure()
                } else {
                    Log.w("RoyalPaySyncWorker", "Server rejected sync: ${response.code()} ${response.message()}")
                }
            } catch (e: Exception) {
                Log.e("RoyalPaySyncWorker", "Network exception syncing ${txn.transactionId}", e)
                return Result.retry()
            }
        }

        return Result.success()
    }
}
