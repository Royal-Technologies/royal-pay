package com.royaltechbd.royalpay.data.parser

import java.util.regex.Pattern

object SmsParser {

    private val BKASH_SENDER = setOf("bkash", "16247")
    private val NAGAD_SENDER = setOf("nagad", "16167")
    private val ROCKET_SENDER = setOf("rocket", "16216")

    fun isWhitelistedSender(sender: String): Boolean {
        val s = sender.lowercase().trim()
        return BKASH_SENDER.contains(s) || NAGAD_SENDER.contains(s) || ROCKET_SENDER.contains(s)
    }

    fun parse(sender: String, body: String): ParsedTransaction? {
        val cleanSender = sender.lowercase().trim()

        return when {
            BKASH_SENDER.contains(cleanSender) || body.contains("bkash", ignoreCase = true) -> {
                parseBkash(body)
            }
            NAGAD_SENDER.contains(cleanSender) || body.contains("nagad", ignoreCase = true) -> {
                parseNagad(body)
            }
            ROCKET_SENDER.contains(cleanSender) || body.contains("rocket", ignoreCase = true) -> {
                parseRocket(body)
            }
            else -> null
        }
    }

    /**
     * bKash Parser
     * Format: You have received Tk 1,500.00 from 017XXXXXXXX. Fee Tk 0.00. Balance Tk 15,200.00. TrxID 9K284L10 at 11/11/2026 14:30. Ref: RPA-202611-849201
     */
    private fun parseBkash(body: String): ParsedTransaction {
        var trxId = ""
        var amountMinor = 0L
        var senderNumber = ""
        var ref = ""

        // TrxID regex
        val trxMatcher = Pattern.compile("TrxID\\s+([A-Z0-9]+)", Pattern.CASE_INSENSITIVE).matcher(body)
        if (trxMatcher.find()) {
            trxId = trxMatcher.group(1)?.trim() ?: ""
        }

        // Amount regex: "received Tk 1,500.00" or "Tk 1500.00"
        val amtMatcher = Pattern.compile("(?:received\\s+Tk\\.?|amount\\s+Tk\\.?)\\s*([0-9,]+(?:\\.[0-9]{1,2})?)", Pattern.CASE_INSENSITIVE).matcher(body)
        if (amtMatcher.find()) {
            val amtStr = amtMatcher.group(1)?.replace(",", "") ?: "0"
            amountMinor = parseToMinorUnits(amtStr)
        }

        // Sender regex: "from 01XXXXXXXXX"
        val senderMatcher = Pattern.compile("from\\s+([0-9+]+)", Pattern.CASE_INSENSITIVE).matcher(body)
        if (senderMatcher.find()) {
            senderNumber = senderMatcher.group(1)?.trim() ?: ""
        }

        // Reference regex: "Ref: RPA-..." or "Ref RPA-..."
        val refMatcher = Pattern.compile("Ref(?:erence)?:?\\s*([A-Za-z0-9_-]+)", Pattern.CASE_INSENSITIVE).matcher(body)
        if (refMatcher.find()) {
            ref = refMatcher.group(1)?.trim() ?: ""
        }

        val isValid = trxId.isNotEmpty() && amountMinor > 0

        return ParsedTransaction(
            provider = "bkash",
            transactionId = trxId,
            amountMinor = amountMinor,
            senderNumber = senderNumber,
            receiverNumber = "",
            reference = ref,
            rawBody = body,
            isValid = isValid
        )
    }

    /**
     * Nagad Parser
     * Format: Money Received: Amount: Tk 1,500.00 Sender: 018XXXXXXXX TxnID: 71NB482K Date: 11/11/2026 14:31 Ref: RPA-202611-849201 Balance: Tk 12,000.00
     */
    private fun parseNagad(body: String): ParsedTransaction {
        var trxId = ""
        var amountMinor = 0L
        var senderNumber = ""
        var ref = ""

        val trxMatcher = Pattern.compile("(?:TxnID|TrxID|Txn Id):?\\s*([A-Z0-9]+)", Pattern.CASE_INSENSITIVE).matcher(body)
        if (trxMatcher.find()) {
            trxId = trxMatcher.group(1)?.trim() ?: ""
        }

        val amtMatcher = Pattern.compile("(?:Amount:?\\s*Tk\\.?|Received:?\\s*Tk\\.?)\\s*([0-9,]+(?:\\.[0-9]{1,2})?)", Pattern.CASE_INSENSITIVE).matcher(body)
        if (amtMatcher.find()) {
            val amtStr = amtMatcher.group(1)?.replace(",", "") ?: "0"
            amountMinor = parseToMinorUnits(amtStr)
        }

        val senderMatcher = Pattern.compile("Sender:?\\s*([0-9+]+)", Pattern.CASE_INSENSITIVE).matcher(body)
        if (senderMatcher.find()) {
            senderNumber = senderMatcher.group(1)?.trim() ?: ""
        }

        val refMatcher = Pattern.compile("Ref(?:erence)?:?\\s*([A-Za-z0-9_-]+)", Pattern.CASE_INSENSITIVE).matcher(body)
        if (refMatcher.find()) {
            ref = refMatcher.group(1)?.trim() ?: ""
        }

        val isValid = trxId.isNotEmpty() && amountMinor > 0

        return ParsedTransaction(
            provider = "nagad",
            transactionId = trxId,
            amountMinor = amountMinor,
            senderNumber = senderNumber,
            receiverNumber = "",
            reference = ref,
            rawBody = body,
            isValid = isValid
        )
    }

    /**
     * Rocket Parser
     * Format: Received Tk 1,500.00 from 019XXXXXXXX to A/C 017XXXXXXXX9. TxnID: 98124712 Date: 11-NOV-26 14:32:10. Ref: RPA-202611-849201
     */
    private fun parseRocket(body: String): ParsedTransaction {
        var trxId = ""
        var amountMinor = 0L
        var senderNumber = ""
        var receiverNumber = ""
        var ref = ""

        val trxMatcher = Pattern.compile("(?:TxnID|TrxID|Txn Id):?\\s*([A-Z0-9]+)", Pattern.CASE_INSENSITIVE).matcher(body)
        if (trxMatcher.find()) {
            trxId = trxMatcher.group(1)?.trim() ?: ""
        }

        val amtMatcher = Pattern.compile("(?:Received\\s+Tk\\.?|Tk\\.?)\\s*([0-9,]+(?:\\.[0-9]{1,2})?)", Pattern.CASE_INSENSITIVE).matcher(body)
        if (amtMatcher.find()) {
            val amtStr = amtMatcher.group(1)?.replace(",", "") ?: "0"
            amountMinor = parseToMinorUnits(amtStr)
        }

        val senderMatcher = Pattern.compile("from\\s+([0-9+]+)", Pattern.CASE_INSENSITIVE).matcher(body)
        if (senderMatcher.find()) {
            senderNumber = senderMatcher.group(1)?.trim() ?: ""
        }

        val receiverMatcher = Pattern.compile("to\\s+A/C\\s+([0-9+]+)", Pattern.CASE_INSENSITIVE).matcher(body)
        if (receiverMatcher.find()) {
            receiverNumber = receiverMatcher.group(1)?.trim() ?: ""
        }

        val refMatcher = Pattern.compile("Ref(?:erence)?:?\\s*([A-Za-z0-9_-]+)", Pattern.CASE_INSENSITIVE).matcher(body)
        if (refMatcher.find()) {
            ref = refMatcher.group(1)?.trim() ?: ""
        }

        val isValid = trxId.isNotEmpty() && amountMinor > 0

        return ParsedTransaction(
            provider = "rocket",
            transactionId = trxId,
            amountMinor = amountMinor,
            senderNumber = senderNumber,
            receiverNumber = receiverNumber,
            reference = ref,
            rawBody = body,
            isValid = isValid
        )
    }

    private fun parseToMinorUnits(amountStr: String): Long {
        return try {
            val d = amountStr.toDouble()
            Math.round(d * 100.0)
        } catch (e: Exception) {
            0L
        }
    }
}
