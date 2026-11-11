package com.royaltechbd.royalpay

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import android.widget.Toast
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import com.royaltechbd.royalpay.data.local.AppDatabase
import com.royaltechbd.royalpay.data.local.PreferencesManager
import com.royaltechbd.royalpay.databinding.ActivityMainBinding
import com.royaltechbd.royalpay.worker.SyncWorker
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

class MainActivity : AppCompatActivity() {

    private lateinit var binding: ActivityMainBinding
    private lateinit var prefs: PreferencesManager
    private lateinit var adapter: TransactionAdapter

    private val requiredPermissions by lazy {
        val list = mutableListOf(
            Manifest.permission.RECEIVE_SMS,
            Manifest.permission.READ_SMS,
            Manifest.permission.CAMERA
        )
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            list.add(Manifest.permission.POST_NOTIFICATIONS)
        }
        list.toTypedArray()
    }

    private val permissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) { permissions ->
        val allGranted = permissions.all { it.value }
        if (!allGranted) {
            Toast.makeText(this, "SMS & Camera permissions are necessary for payment verification.", Toast.LENGTH_LONG).show()
        }
    }

    private val pairingLauncher = registerForActivityResult(
        ActivityResultContracts.StartActivityForResult()
    ) { result ->
        if (result.resultCode == RESULT_OK) {
            updateUiState()
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)

        prefs = PreferencesManager(this)

        setupRecyclerView()
        checkAndRequestPermissions()
        setupListeners()
        observeDatabase()
        updateUiState()
    }

    override fun onResume() {
        super.onResume()
        updateUiState()
    }

    private fun checkAndRequestPermissions() {
        val missing = requiredPermissions.filter {
            ContextCompat.checkSelfPermission(this, it) != PackageManager.PERMISSION_GRANTED
        }
        if (missing.isNotEmpty()) {
            permissionLauncher.launch(missing.toTypedArray())
        }
    }

    private fun setupRecyclerView() {
        adapter = TransactionAdapter()
        binding.rvTransactions.layoutManager = LinearLayoutManager(this)
        binding.rvTransactions.adapter = adapter
    }

    private fun setupListeners() {
        binding.btnPair.setOnClickListener {
            val intent = Intent(this, PairingActivity::class.java)
            pairingLauncher.launch(intent)
        }

        binding.btnSyncNow.setOnClickListener {
            if (!prefs.isPaired()) {
                Toast.makeText(this, "Pair the device before syncing.", Toast.LENGTH_SHORT).show()
                return@setOnClickListener
            }

            val syncRequest = OneTimeWorkRequestBuilder<SyncWorker>().build()
            WorkManager.getInstance(this).enqueue(syncRequest)
            Toast.makeText(this, "Manual sync triggered.", Toast.LENGTH_SHORT).show()
        }
    }

    private fun observeDatabase() {
        val db = AppDatabase.getInstance(this)

        db.transactionDao().getAllLive().observe(this) { list ->
            adapter.submitList(list)
        }

        db.transactionDao().getUnsyncedCountLive().observe(this) { count ->
            binding.tvQueueCount.text = "Unsynced Transactions: $count"
        }
    }

    private fun updateUiState() {
        binding.tvDeviceUuid.text = "Device UUID: ${prefs.getDeviceUuid()}"

        if (prefs.isPaired()) {
            binding.tvConnectionStatus.text = "Status: Connected & Monitoring"
            binding.tvConnectionStatus.setTextColor(ContextCompat.getColor(this, R.color.accent))
            binding.tvSiteEndpoint.text = "Connected to: ${prefs.getSiteName()} (${prefs.getApiUrl()})"
            binding.btnPair.text = "🔄 Re-pair with QR"
        } else {
            binding.tvConnectionStatus.text = "Status: Not Paired"
            binding.tvConnectionStatus.setTextColor(ContextCompat.getColor(this, R.color.danger))
            binding.tvSiteEndpoint.text = "Server: Not configured"
            binding.btnPair.text = "📱 Pair with QR Code"
        }

        val lastHb = prefs.getLastHeartbeat()
        if (lastHb > 0) {
            val df = SimpleDateFormat("dd MMM, HH:mm", Locale.US)
            binding.tvLastHeartbeat.text = "Last Heartbeat: ${df.format(Date(lastHb))}"
        } else {
            binding.tvLastHeartbeat.text = "Last Heartbeat: Pending first interval"
        }
    }
}
