package com.royaltechbd.royalpay

import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.TextView
import androidx.recyclerview.widget.RecyclerView
import com.royaltechbd.royalpay.data.local.TransactionEntity
import java.text.DecimalFormat

class TransactionAdapter(
    private var transactions: List<TransactionEntity> = emptyList()
) : RecyclerView.Adapter<TransactionAdapter.ViewHolder>() {

    class ViewHolder(view: View) : RecyclerView.ViewHolder(view) {
        val tvProvider: TextView = view.findViewById(R.id.tvTxnProvider)
        val tvAmount: TextView = view.findViewById(R.id.tvTxnAmount)
        val tvTxnId: TextView = view.findViewById(R.id.tvTxnId)
        val tvMeta: TextView = view.findViewById(R.id.tvTxnMeta)
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): ViewHolder {
        val view = LayoutInflater.from(parent.context).inflate(R.layout.item_transaction, parent, false)
        return ViewHolder(view)
    }

    override fun onBindViewHolder(holder: ViewHolder, position: Int) {
        val item = transactions[position]
        holder.tvProvider.text = item.provider.uppercase()

        val amountFormat = DecimalFormat("#,##0.00")
        val formattedAmt = amountFormat.format(item.amountMinor / 100.0)
        holder.tvAmount.text = "৳ $formattedAmt"

        holder.tvTxnId.text = "TrxID: ${item.transactionId}"
        val syncStatus = if (item.isSynced) "Synced" else "Pending Sync"
        val refPart = if (item.reference.isNotEmpty()) "Ref: ${item.reference} • " else ""
        holder.tvMeta.text = "$refPart$syncStatus"
    }

    override fun getItemCount(): Int = transactions.size

    fun submitList(list: List<TransactionEntity>) {
        transactions = list
        notifyDataSetChanged()
    }
}
