package com.royaltechbd.royalpay

import android.app.Application
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import com.royaltechbd.royalpay.worker.HeartbeatWorker
import java.util.concurrent.TimeUnit

class RoyalPayApp : Application() {

    override fun onCreate() {
        super.onCreate()
        schedulePeriodicWorkers()
    }

    fun schedulePeriodicWorkers() {
        val heartbeatRequest = PeriodicWorkRequestBuilder<HeartbeatWorker>(15, TimeUnit.MINUTES)
            .build()

        WorkManager.getInstance(this).enqueueUniquePeriodicWork(
            "royalpay_heartbeat",
            ExistingPeriodicWorkPolicy.KEEP,
            heartbeatRequest
        )
    }
}
