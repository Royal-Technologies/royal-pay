package com.royaltechbd.royalpay.receiver

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.provider.Telephony
import android.util.Log
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import com.royaltechbd.royalpay.data.local.AppDatabase
import com.royaltechbd.royalpay.data.local.PreferencesManager
import com.royaltechbd.royalpay.data.local.TransactionEntity
import com.royaltechbd.royalpay.data.parser.SmsParser
import com.royaltechbd.royalpay.worker.SyncWorker
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import java.security.MessageDigest

class SmsReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != Telephony.Sms.Intents.SMS_RECEIVED_ACTION) {
            return
        }

        val messages = Telephony.Sms.Intents.getMessagesFromIntent(intent)
        if (messages.isNullOrEmpty()) return

        val prefs = PreferencesManager(context)
        if (!prefs.isPaired()) {
            Log.w("RoyalPaySmsReceiver", "Device not paired. Ignoring SMS.")
            return
        }

        val fullBody = StringBuilder()
        var sender = ""

        for (sms in messages) {
            if (sender.isEmpty()) {
                sender = sms.displayOriginatingAddress ?: sms.originatingAddress ?: ""
            }
            fullBody.append(sms.displayMessageBody ?: sms.messageBody ?: "")
        }

        val body = fullBody.toString()

        // Filter: Whitelisted MFS channels only
        if (!SmsParser.isWhitelistedSender(sender)) {
            Log.d("RoyalPaySmsReceiver", "Non-MFS Sender ID ignored: $sender")
            return
        }

        val parsed = SmsParser.parse(sender, body) ?: return
        if (!parsed.isValid) {
            Log.w("RoyalPaySmsReceiver", "Incomplete transaction fields in SMS.")
            return
        }

        val rawHash = "$sender|$body"
        val messageHash = sha256(rawHash)

        val pendingResult = goAsync()

        CoroutineScope(Dispatchers.IO).launch {
            try {
                val db = AppDatabase.getInstance(context)
                val existing = db.transactionDao().findByHash(messageHash)

                if (existing == null) {
                    val entity = TransactionEntity(
                        provider = parsed.provider,
                        transactionId = parsed.transactionId,
                        amountMinor = parsed.amountMinor,
                        senderNumber = parsed.senderNumber,
                        receiverNumber = parsed.receiverNumber,
                        reference = parsed.reference,
                        rawBody = body,
                        messageHash = messageHash,
                        isSynced = false
                    )

                    db.transactionDao().insert(entity)
                    Log.i("RoyalPaySmsReceiver", "Saved transaction ${parsed.transactionId} (${parsed.provider}) to offline Room DB.")

                    // Trigger immediate background sync
                    val syncRequest = OneTimeWorkRequestBuilder<SyncWorker>().build()
                    WorkManager.getInstance(context).enqueue(syncRequest)
                }
            } catch (e: Exception) {
                Log.e("RoyalPaySmsReceiver", "Error saving SMS transaction", e)
            } finally {
                pendingResult.finish()
            }
        }
    }

    private fun sha256(input: String): String {
        val md = MessageDigest.getInstance("SHA-256")
        val bytes = md.digest(input.toByteArray())
        return bytes.joinToString("") { "%02x".format(it) }
    }
}
