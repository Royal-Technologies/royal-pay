package com.royaltechbd.royalpay.data.parser

data class ParsedTransaction(
    val provider: String,
    val transactionId: String,
    val amountMinor: Long,
    val senderNumber: String,
    val receiverNumber: String,
    val reference: String,
    val rawBody: String,
    val isValid: Boolean,
    val confidence: Double = 1.0
)
