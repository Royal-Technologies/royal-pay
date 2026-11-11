package com.royaltechbd.royalpay

import android.os.Build
import android.os.Bundle
import android.view.View
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import com.journeyapps.barcodescanner.BarcodeCallback
import com.journeyapps.barcodescanner.BarcodeResult
import com.journeyapps.barcodescanner.DecoratedBarcodeView
import com.royaltechbd.royalpay.data.local.PreferencesManager
import com.royaltechbd.royalpay.data.remote.ApiClient
import com.royaltechbd.royalpay.data.remote.dto.PairRequest
import com.royaltechbd.royalpay.databinding.ActivityPairingBinding
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONObject

class PairingActivity : AppCompatActivity() {

    private lateinit var binding: ActivityPairingBinding
    private lateinit var barcodeScanner: DecoratedBarcodeView
    private var isProcessing = false

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityPairingBinding.inflate(layoutInflater)
        setContentView(binding.root)

        barcodeScanner = binding.barcodeScanner
        barcodeScanner.decodeContinuous(object : BarcodeCallback {
            override fun barcodeResult(result: BarcodeResult?) {
                if (result != null && !isProcessing) {
                    isProcessing = true
                    handleScannedQr(result.text)
                }
            }
        })
    }

    override fun onResume() {
        super.onResume()
        barcodeScanner.resume()
    }

    override fun onPause() {
        super.onPause()
        barcodeScanner.pause()
    }

    private fun handleScannedQr(qrContent: String) {
        binding.pbPairing.visibility = View.VISIBLE

        CoroutineScope(Dispatchers.IO).launch {
            try {
                val json = JSONObject(qrContent)
                val apiUrl = json.getString("api_url")
                val pairingToken = json.getString("pairing_token")
                val siteName = json.optString("site_name", "Royal Pay SaaS")

                val prefs = PreferencesManager(this@PairingActivity)
                val deviceUuid = prefs.getDeviceUuid()
                val model = "${Build.MANUFACTURER} ${Build.MODEL}"
                val androidVer = Build.VERSION.RELEASE

                val api = ApiClient.getService(apiUrl)
                val pairReq = PairRequest(
                    pairingToken = pairingToken,
                    deviceUuid = deviceUuid,
                    deviceName = model,
                    model = model,
                    androidVersion = androidVer,
                    appVersion = "1.0.0"
                )

                val response = api.pairDevice(pairReq)

                withContext(Dispatchers.Main) {
                    binding.pbPairing.visibility = View.GONE
                    if (response.isSuccessful && response.body()?.success == true) {
                        val token = response.body()?.deviceToken ?: ""
                        prefs.setApiUrl(apiUrl)
                        prefs.setDeviceToken(token)
                        prefs.setSiteName(siteName)
                        prefs.setPaired(true)

                        Toast.makeText(this@PairingActivity, "Paired successfully with $siteName!", Toast.LENGTH_LONG).show()
                        setResult(RESULT_OK)
                        finish()
                    } else {
                        val errorMsg = response.body()?.message ?: "Pairing failed. Please regenerate QR code."
                        Toast.makeText(this@PairingActivity, errorMsg, Toast.LENGTH_LONG).show()
                        isProcessing = false
                    }
                }
            } catch (e: Exception) {
                withContext(Dispatchers.Main) {
                    binding.pbPairing.visibility = View.GONE
                    Toast.makeText(this@PairingActivity, "Invalid QR Code or network error: ${e.message}", Toast.LENGTH_SHORT).show()
                    isProcessing = false
                }
            }
        }
    }
}
