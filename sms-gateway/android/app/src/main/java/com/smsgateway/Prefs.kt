package com.smsgateway

import android.content.Context
import android.content.SharedPreferences
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import org.json.JSONArray
import org.json.JSONObject
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/** Server URL, API key and gateway state, all in EncryptedSharedPreferences. */
object Prefs {
    @Volatile
    private var cached: SharedPreferences? = null

    fun sp(ctx: Context): SharedPreferences =
        cached ?: synchronized(this) {
            cached ?: create(ctx.applicationContext).also { cached = it }
        }

    private fun create(app: Context): SharedPreferences {
        val master = MasterKey.Builder(app).setKeyScheme(MasterKey.KeyScheme.AES256_GCM).build()
        return EncryptedSharedPreferences.create(
            app, "gateway_secure", master,
            EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
            EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM
        )
    }

    fun serverUrl(c: Context): String = sp(c).getString("server", "") ?: ""
    fun apiKey(c: Context): String = sp(c).getString("key", "") ?: ""
    fun deviceId(c: Context): Long = sp(c).getLong("device_id", -1L)
    fun enabled(c: Context): Boolean = sp(c).getBoolean("enabled", false)
    fun lastPoll(c: Context): Long = sp(c).getLong("last_poll", 0L)
    fun lastError(c: Context): String = sp(c).getString("last_error", "") ?: ""

    fun configured(c: Context): Boolean = serverUrl(c).isNotEmpty() && apiKey(c).isNotEmpty() && deviceId(c) > 0

    fun saveConnection(c: Context, server: String, key: String, deviceId: Long) {
        sp(c).edit().putString("server", server).putString("key", key).putLong("device_id", deviceId).apply()
    }

    fun setEnabled(c: Context, v: Boolean) = sp(c).edit().putBoolean("enabled", v).apply()

    fun setPollResult(c: Context, error: String?) {
        val e = sp(c).edit()
        if (error == null) e.putLong("last_poll", System.currentTimeMillis()).putString("last_error", "")
        else e.putString("last_error", error)
        e.apply()
    }

    fun disconnect(c: Context) {
        sp(c).edit().clear().apply()
    }
}

/** Short history of messages shown on the status screen (newest first, max 30). */
object MessageLog {
    private const val KEY = "log"
    private const val MAX = 30

    private fun load(ctx: Context): MutableList<JSONObject> {
        val arr = JSONArray(Prefs.sp(ctx).getString(KEY, "[]") ?: "[]")
        return MutableList(arr.length()) { arr.getJSONObject(it) }
    }

    private fun save(ctx: Context, list: List<JSONObject>) {
        Prefs.sp(ctx).edit().putString(KEY, JSONArray(list.take(MAX)).toString()).apply()
    }

    @Synchronized
    fun add(ctx: Context, id: Long, to: String, status: String) {
        val list = load(ctx)
        list.removeAll { it.optLong("id") == id }
        list.add(0, JSONObject().put("id", id).put("to", to).put("status", status).put("t", System.currentTimeMillis()))
        save(ctx, list)
    }

    @Synchronized
    fun update(ctx: Context, id: Long, status: String) {
        val list = load(ctx)
        list.firstOrNull { it.optLong("id") == id }?.let {
            it.put("status", status).put("t", System.currentTimeMillis())
            save(ctx, list)
        }
    }

    @Synchronized
    fun lines(ctx: Context): String {
        val fmt = SimpleDateFormat("HH:mm:ss", Locale.getDefault())
        val list = load(ctx)
        if (list.isEmpty()) return "No messages yet."
        return list.joinToString("\n") {
            "#${it.optLong("id")}  ${it.optString("to")}  ${it.optString("status")}  ${fmt.format(Date(it.optLong("t")))}"
        }
    }
}
