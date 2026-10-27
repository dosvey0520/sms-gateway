package com.smsgateway

import android.Manifest
import android.app.Activity
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.telephony.SmsManager
import android.telephony.SmsMessage
import androidx.core.content.ContextCompat
import java.util.concurrent.ConcurrentHashMap

object SmsSender {
    const val ACTION_SENT = "com.smsgateway.SMS_SENT"
    const val ACTION_DELIVERED = "com.smsgateway.SMS_DELIVERED"

    /** Per-message progress across SMS parts. In memory only; see README for the restart caveat. */
    class Track(val total: Int) {
        var sent = 0
        var delivered = 0
        var failed = false
    }

    val tracks = ConcurrentHashMap<Long, Track>()

    fun report(ctx: Context, id: Long, status: String, error: String? = null) {
        MessageLog.update(ctx, id, if (error != null) "$status: $error" else status)
        StatusReporter.enqueue(ctx, id, status, error)
    }

    private fun smsManager(ctx: Context): SmsManager =
        if (Build.VERSION.SDK_INT >= 31) ctx.getSystemService(SmsManager::class.java)
        else @Suppress("DEPRECATION") SmsManager.getDefault()

    private fun pending(ctx: Context, action: String, id: Long, part: Int, total: Int, kind: Int, mutable: Boolean): PendingIntent {
        val intent = Intent(action).setComponent(ComponentName(ctx, SmsResultReceiver::class.java))
            .putExtra("id", id).putExtra("part", part).putExtra("total", total)
        val flags = PendingIntent.FLAG_UPDATE_CURRENT or
            (if (mutable && Build.VERSION.SDK_INT >= 31) PendingIntent.FLAG_MUTABLE else PendingIntent.FLAG_IMMUTABLE)
        val requestCode = ((id % 1_000_000L).toInt() * 64 + part) * 2 + kind
        return PendingIntent.getBroadcast(ctx, requestCode, intent, flags)
    }

    fun send(ctx: Context, m: PendingMsg) {
        if (ContextCompat.checkSelfPermission(ctx, Manifest.permission.SEND_SMS) != PackageManager.PERMISSION_GRANTED) {
            report(ctx, m.id, "failed", "SEND_SMS permission not granted on the phone")
            return
        }
        try {
            val sms = smsManager(ctx)
            val parts = sms.divideMessage(m.body)
            tracks[m.id] = Track(parts.size)
            val sentIntents = ArrayList<PendingIntent>()
            val deliveredIntents = ArrayList<PendingIntent>()
            for (i in parts.indices) {
                sentIntents.add(pending(ctx, ACTION_SENT, m.id, i, parts.size, 0, mutable = false))
                // Delivery receipts carry the status PDU as an extra, which needs a mutable PendingIntent.
                deliveredIntents.add(pending(ctx, ACTION_DELIVERED, m.id, i, parts.size, 1, mutable = true))
            }
            if (parts.size == 1) {
                sms.sendTextMessage(m.recipient, null, parts[0], sentIntents[0], deliveredIntents[0])
            } else {
                sms.sendMultipartTextMessage(m.recipient, null, parts, sentIntents, deliveredIntents)
            }
        } catch (e: Exception) {
            tracks.remove(m.id)
            report(ctx, m.id, "failed", e.message ?: e.javaClass.simpleName)
        }
    }

    fun errorText(code: Int): String = when (code) {
        SmsManager.RESULT_ERROR_GENERIC_FAILURE -> "Generic failure (check SIM / balance)"
        SmsManager.RESULT_ERROR_RADIO_OFF -> "Radio off (airplane mode?)"
        SmsManager.RESULT_ERROR_NULL_PDU -> "Null PDU"
        SmsManager.RESULT_ERROR_NO_SERVICE -> "No cellular service"
        SmsManager.RESULT_ERROR_LIMIT_EXCEEDED -> "Android SMS sending limit exceeded"
        SmsManager.RESULT_ERROR_FDN_CHECK_FAILURE -> "Blocked by fixed dialing numbers"
        SmsManager.RESULT_ERROR_SHORT_CODE_NOT_ALLOWED,
        SmsManager.RESULT_ERROR_SHORT_CODE_NEVER_ALLOWED -> "Short code not allowed"
        else -> "Send failed (code $code)"
    }
}

/** Receives the sent / delivery callbacks from SmsManager and queues status reports. */
class SmsResultReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val id = intent.getLongExtra("id", -1L)
        if (id < 0) return
        val total = intent.getIntExtra("total", 1)
        val track = SmsSender.tracks.getOrPut(id) { SmsSender.Track(total) }

        var status: String? = null
        var error: String? = null

        when (intent.action) {
            SmsSender.ACTION_SENT -> synchronized(track) {
                if (resultCode == Activity.RESULT_OK) {
                    track.sent++
                    if (track.sent == track.total && !track.failed) status = "sent"
                } else if (!track.failed) {
                    track.failed = true
                    status = "failed"
                    error = SmsSender.errorText(resultCode)
                }
            }
            SmsSender.ACTION_DELIVERED -> synchronized(track) {
                val pdu = intent.getByteArrayExtra("pdu")
                val format = intent.getStringExtra("format")
                val st = if (pdu != null) {
                    try { SmsMessage.createFromPdu(pdu, format).status } catch (e: Exception) { 0 }
                } else 0
                when {
                    st == 0 -> {
                        track.delivered++
                        if (track.delivered == track.total && !track.failed) status = "delivered"
                    }
                    st >= 0x40 && !track.failed -> {   // permanent delivery failure
                        track.failed = true
                        status = "failed"
                        error = "Delivery failed (status $st)"
                    }
                    // 0x20..0x3F: temporary condition, a later report will follow
                }
            }
        }

        val s = status ?: return
        SmsSender.report(context, id, s, error)
        if (s == "failed" || s == "delivered") SmsSender.tracks.remove(id)
        val pr = goAsync()
        StatusReporter.flushAsync(context) { pr.finish() }
    }
}
