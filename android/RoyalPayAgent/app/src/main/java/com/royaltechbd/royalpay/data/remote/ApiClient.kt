package com.royaltechbd.royalpay.data.remote

import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import java.util.concurrent.TimeUnit

object ApiClient {

    private var retrofit: Retrofit? = null
    private var currentBaseUrl: String? = null

    fun getService(baseUrl: String): ApiService {
        val sanitizedUrl = if (baseUrl.endsWith("/")) baseUrl else "$baseUrl/"

        if (retrofit == null || currentBaseUrl != sanitizedUrl) {
            val logging = HttpLoggingInterceptor().apply {
                level = HttpLoggingInterceptor.Level.BODY
            }

            val client = OkHttpClient.Builder()
                .connectTimeout(15, TimeUnit.SECONDS)
                .readTimeout(15, TimeUnit.SECONDS)
                .writeTimeout(15, TimeUnit.SECONDS)
                .addInterceptor(logging)
                .build()

            retrofit = Retrofit.Builder()
                .baseUrl(sanitizedUrl)
                .client(client)
                .addConverterFactory(GsonConverterFactory.create())
                .build()

            currentBaseUrl = sanitizedUrl
        }

        return retrofit!!.create(ApiService::class.java)
    }
}
