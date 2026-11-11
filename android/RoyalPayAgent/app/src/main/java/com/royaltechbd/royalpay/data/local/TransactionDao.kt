package com.royaltechbd.royalpay.data.local

import androidx.lifecycle.LiveData
import androidx.room.*

@Dao
interface TransactionDao {

    @Query("SELECT * FROM transactions ORDER BY id DESC")
    fun getAllLive(): LiveData<List<TransactionEntity>>

    @Query("SELECT * FROM transactions WHERE isSynced = 0 ORDER BY id ASC")
    suspend fun getUnsynced(): List<TransactionEntity>

    @Query("SELECT COUNT(*) FROM transactions WHERE isSynced = 0")
    fun getUnsyncedCountLive(): LiveData<Int>

    @Query("SELECT COUNT(*) FROM transactions WHERE isSynced = 0")
    suspend fun getUnsyncedCount(): Int

    @Query("SELECT * FROM transactions WHERE messageHash = :hash LIMIT 1")
    suspend fun findByHash(hash: String): TransactionEntity?

    @Insert(onConflict = OnConflictStrategy.IGNORE)
    suspend fun insert(transaction: TransactionEntity): Long

    @Query("UPDATE transactions SET isSynced = 1 WHERE id = :id")
    suspend fun markSynced(id: Long)
}
