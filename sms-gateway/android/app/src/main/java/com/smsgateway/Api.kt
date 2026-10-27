package com.smsgateway

import android.content.Context
import okhttp3.Interceptor
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import okhttp3.Response
import org.json.JSONObject
import java.io.IOException
import java.net.InetAddress
import java.util.concurrent.TimeUnit
import kotlin.math.min

class ApiException(val code: Int, message: String) : Exception(message)

data class PendingMsg(val id: Long, val recipient: String, val body: String)

object NetRules {
    private val ipv4 = Regex("""^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$""")

    /** True for loopback, private LAN, link-local, ".local" and single-label hostnames. */
    fun isPrivateHost(host: String): Boolean {
        val h = host.lowercase()
        if (h == "localhost" || h.endsWith(".local") || h.endsWith(".lan")) return true
        val m = ipv4.matchEntire(h)
        if (m != null) {
            val o = m.groupValues.drop(1).map { it.toInt() }
            if (o.any { it > 255 }) return false
            return o[0] == 10 || o[0] == 127 ||
                (o[0] == 172 && o[1] in 16..31) ||
                (o[0] == 192 && o[1] == 168) ||
                (o[0] == 169 && o[1] == 254)
        }
        if (h.contains(':')) {   // IPv6 literal (host is passed without brackets); literals never trigger DNS
            return try {
                val a = InetAddress.getByName(h)
                val first = a.address[0].toInt() and 0xFF
                a.isLoopbackAddress || a.isLinkLocalAddress || (first and 0xFE) == 0xFC
            } catch (e: Exception) {
                false
            }
        }
        return !h.contains('.')   // single-label LAN name such as "mypc"
    }
}

object Api {
    /** Cleartext HTTP is only allowed to local-network hosts; the OS-level config cannot express that. */
    private val privateHttpOnly = Interceptor { chain ->
        val url = chain.request().url
        if (url.scheme == "http" && !NetRules.isPrivateHost(url.host)) {
            throw IOException("Plain HTTP is only allowed to local network addresses. Use https:// or a LAN address.")
        }
        chain.proceed(chain.request())
    }

    private val client = OkHttpClient.Builder()
        .connectTimeout(8, TimeUnit.SECONDS)
        .readTimeout(10, TimeUnit.SECONDS)
        .writeTimeout(10, TimeUnit.SECONDS)
        .addInterceptor(privateHttpOnly)
        .build()

    private val json = "application/json; charset=utf-8".toMediaType()

    private fun errorText(code: Int, body: String): String {
        val msg = try { JSONObject(body).optString("error") } catch (e: Exception) { "" }
        return when {
            code == 401 -> "API key is invalid or revoked"
            msg.isNotEmpty() -> msg
            else -> "HTTP $code"
        }
    }

    private fun exec(req: Request): Pair<Int, String> =
        client.newCall(req).execute().use { r: Response -> r.code to (r.body?.string() ?: "") }

    /** Registers (or re-registers) this phone. Returns the server-side device id. */
    fun register(base: String, key: String, name: String, model: String, existingId: Long): Long {
        val body = JSONObject().put("name", name).put("model", model)
        if (existingId > 0) body.put("device_id", existingId)
        val (code, text) = exec(
            Request.Builder().url("${base.trimEnd('/')}/api/v1/devices/register")
                .header("x-api-key", key).post(body.toString().toRequestBody(json)).build()
        )
        if (code !in 200..299) throw ApiException(code, errorText(code, text))
        return JSONObject(text).getLong("id")
    }

    fun pending(ctx: Context): List<PendingMsg> {
        val (code, text) = exec(
            Request.Builder()
                .url("${Prefs.serverUrl(ctx).trimEnd('/')}/api/v1/devices/${Prefs.deviceId(ctx)}/pending")
                .header("x-api-key", Prefs.apiKey(ctx)).build()
        )
        if (code !in 200..299) throw ApiException(code, errorText(code, text))
        val arr = JSONObject(text).getJSONArray("messages")
        return List(arr.length()) {
            val o = arr.getJSONObject(it)
            PendingMsg(o.getLong("id"), o.getString("recipient"), o.getString("body"))
        }
    }

    /** Returns the HTTP status code; throws IOException when the server is unreachable. */
    fun postStatus(ctx: Context, id: Long, status: String, error: String?): Int {
        val body = JSONObject().put("status", status)
        if (error != null) body.put("error", error)
        val (code, _) = exec(
            Request.Builder()
                .url("${Prefs.serverUrl(ctx).trimEnd('/')}/api/v1/messages/$id/status")
                .header("x-api-key", Prefs.apiKey(ctx)).post(body.toString().toRequestBody(json)).build()
        )
        return code
    }
}

/**
 * Status reports (sent / delivered / failed) are queued on disk and retried with backoff,
 * so a Wi-Fi hiccup or a restart does not lose them.
 */
object StatusReporter {
    private const val KEY = "reports"
    private val lock = Any()
    private val io = java.util.concurrent.Executors.newSingleThreadExecutor()

    @Synchronized
    fun enqueue(ctx: Context, id: Long, status: String, error: String?) {
        val arr = org.json.JSONArray(Prefs.sp(ctx).getString(KEY, "[]") ?: "[]")
        arr.put(JSONObject().put("id", id).put("status", status).put("error", error ?: JSONObject.NULL)
            .put("attempts", 0).put("next", 0L))
        Prefs.sp(ctx).edit().putString(KEY, arr.toString()).apply()
    }

    @Synchronized
    private fun snapshot(ctx: Context): List<JSONObject> {
        val arr = org.json.JSONArray(Prefs.sp(ctx).getString(KEY, "[]") ?: "[]")
        return List(arr.length()) { arr.getJSONObject(it) }
    }

    @Synchronized
    private fun store(ctx: Context, list: List<JSONObject>) {
        Prefs.sp(ctx).edit().putString(KEY, org.json.JSONArray(list).toString()).apply()
    }

    /** Safe to call from any background thread. */
    fun flush(ctx: Context) = synchronized(lock) {
        val now = System.currentTimeMillis()
        val remaining = ArrayList<JSONObject>()
        for (r in snapshot(ctx)) {
            if (r.optLong("next") > now) { remaining.add(r); continue }
            val err = if (r.isNull("error")) null else r.optString("error")
            val code = try {
                Api.postStatus(ctx, r.getLong("id"), r.getString("status"), err)
            } catch (e: Exception) { -1 }
            val retry = code == -1 || code >= 500 || code == 408 || code == 429
            when {
                code in 200..299 -> Unit   // delivered to the server, drop it
                retry && r.optInt("attempts") < 40 -> {
                    val attempts = r.optInt("attempts") + 1
                    r.put("attempts", attempts)
                    r.put("next", now + min(60_000L, 2_000L shl min(attempts, 5)))
                    remaining.add(r)
                }
                else -> Unit   // 4xx (e.g. message deleted) or too many attempts: give up
            }
        }
        store(ctx, remaining)
    }

    fun flushAsync(ctx: Context, done: (() -> Unit)? = null) {
        val app = ctx.applicationContext
        io.execute {
            try { flush(app) } finally { done?.invoke() }
        }
    }
}
