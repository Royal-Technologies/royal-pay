package com.royaltechbd.royalpay.data.local

import android.content.Context
import android.content.SharedPreferences
import java.util.UUID

class PreferencesManager(context: Context) {

    private val prefs: SharedPreferences = context.getSharedPreferences("royalpay_prefs", Context.MODE_PRIVATE)

    companion object {
        private const val KEY_DEVICE_UUID = "device_uuid"
        private const val KEY_API_URL = "api_url"
        private const val KEY_DEVICE_TOKEN = "device_token"
        private const val KEY_SITE_NAME = "site_name"
        private const val KEY_IS_PAIRED = "is_paired"
        private const val KEY_LAST_HEARTBEAT = "last_heartbeat"
    }

    fun getDeviceUuid(): String {
        var uuid = prefs.getString(KEY_DEVICE_UUID, null)
        if (uuid == null) {
            uuid = UUID.randomUUID().toString()
            prefs.edit().putString(KEY_DEVICE_UUID, uuid).apply()
        }
        return uuid
    }

    fun isPaired(): Boolean = prefs.getBoolean(KEY_IS_PAIRED, false)

    fun setPaired(isPaired: Boolean) {
        prefs.edit().putBoolean(KEY_IS_PAIRED, isPaired).apply()
    }

    fun getApiUrl(): String = prefs.getString(KEY_API_URL, "") ?: ""

    fun setApiUrl(url: String) {
        prefs.edit().putString(KEY_API_URL, url).apply()
    }

    fun getDeviceToken(): String = prefs.getString(KEY_DEVICE_TOKEN, "") ?: ""

    fun setDeviceToken(token: String) {
        prefs.edit().putString(KEY_DEVICE_TOKEN, token).apply()
    }

    fun getSiteName(): String = prefs.getString(KEY_SITE_NAME, "") ?: ""

    fun setSiteName(name: String) {
        prefs.edit().putString(KEY_SITE_NAME, name).apply()
    }

    fun getLastHeartbeat(): Long = prefs.getLong(KEY_LAST_HEARTBEAT, 0L)

    fun setLastHeartbeat(time: Long) {
        prefs.edit().putLong(KEY_LAST_HEARTBEAT, time).apply()
    }

    fun clearAll() {
        prefs.edit().clear().apply()
    }
}
