package com.royaltechbd.royalpay.data.local

import androidx.room.Entity
import androidx.room.PrimaryKey

@Entity(tableName = "transactions")
data class TransactionEntity(
    @PrimaryKey(autoGenerate = true)
    val id: Long = 0,
    val provider: String,
    val transactionId: String,
    val amountMinor: Long,
    val senderNumber: String,
    val receiverNumber: String,
    val reference: String,
    val rawBody: String,
    val messageHash: String,
    val isSynced: Boolean = false,
    val createdAt: Long = System.currentTimeMillis()
)
