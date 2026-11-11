package com.royaltechbd.royalpay.data.remote.dto

import com.google.gson.annotations.SerializedName

data class PairRequest(
    @SerializedName("pairing_token") val pairingToken: String,
    @SerializedName("device_uuid") val deviceUuid: String,
    @SerializedName("device_name") val deviceName: String,
    @SerializedName("model") val model: String,
    @SerializedName("android_version") val androidVersion: String,
    @SerializedName("app_version") val appVersion: String = "1.0.0"
)

data class PairResponse(
    @SerializedName("success") val success: Boolean,
    @SerializedName("message") val message: String?,
    @SerializedName("device_token") val deviceToken: String?,
    @SerializedName("device_id") val deviceId: Long?
)

data class SmsIngestRequest(
    @SerializedName("sender") val sender: String,
    @SerializedName("body") val body: String,
    @SerializedName("parsed_provider") val parsedProvider: String,
    @SerializedName("parsed_trxid") val parsedTrxId: String,
    @SerializedName("parsed_amount_minor") val parsedAmountMinor: Long,
    @SerializedName("parsed_reference") val parsedReference: String,
    @SerializedName("sender_number") val senderNumber: String = "",
    @SerializedName("receiver_number") val receiverNumber: String = "",
    @SerializedName("parse_status") val parseStatus: String = "valid",
    @SerializedName("received_at") val receivedAt: String
)

data class HeartbeatRequest(
    @SerializedName("battery_percentage") val batteryPercentage: Int,
    @SerializedName("network_type") val networkType: String,
    @SerializedName("sim_operator") val simOperator: String,
    @SerializedName("queue_length") val queueLength: Int
)

data class BaseResponse(
    @SerializedName("success") val success: Boolean,
    @SerializedName("message") val message: String?,
    @SerializedName("status") val status: String?
)
