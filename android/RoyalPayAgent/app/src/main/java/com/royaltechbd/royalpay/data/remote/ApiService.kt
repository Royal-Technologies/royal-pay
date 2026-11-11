package com.royaltechbd.royalpay.data.remote

import com.royaltechbd.royalpay.data.remote.dto.*
import retrofit2.Response
import retrofit2.http.Body
import retrofit2.http.Header
import retrofit2.http.POST

interface ApiService {

    @POST("devices/pair")
    suspend fun pairDevice(
        @Body request: PairRequest
    ): Response<PairResponse>

    @POST("devices/heartbeat")
    suspend fun sendHeartbeat(
        @Header("X-Device-Token") deviceToken: String,
        @Body request: HeartbeatRequest
    ): Response<BaseResponse>

    @POST("devices/sms/ingest")
    suspend fun ingestSms(
        @Header("X-Device-Token") deviceToken: String,
        @Body request: SmsIngestRequest
    ): Response<BaseResponse>
}
