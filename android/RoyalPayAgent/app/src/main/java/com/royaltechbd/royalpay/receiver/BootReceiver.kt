package com.royaltechbd.royalpay.receiver

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import com.royaltechbd.royalpay.RoyalPayApp

class BootReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action == Intent.ACTION_BOOT_COMPLETED) {
            (context.applicationContext as? RoyalPayApp)?.schedulePeriodicWorkers()
        }
    }
}
