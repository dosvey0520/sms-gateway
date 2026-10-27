package com.smsgateway

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.os.Build
import android.os.IBinder
import android.os.PowerManager
import androidx.core.app.NotificationCompat
import androidx.core.app.ServiceCompat

/** Foreground service: polls the server every few seconds and sends the SMS it hands out. */
class GatewayService : Service() {
    @Volatile private var running = false
    private var worker: Thread? = null
    private var wakeLock: PowerManager.WakeLock? = null

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        startInForeground()
        if (!running) {
            running = true
            wakeLock = (getSystemService(Context.POWER_SERVICE) as PowerManager)
                .newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "smsgateway:poll").apply { acquire() }
            worker = Thread({ pollLoop() }, "gateway-poll").also { it.start() }
        }
        return START_STICKY
    }

    private fun startInForeground() {
        val nm = getSystemService(NotificationManager::class.java)
        if (Build.VERSION.SDK_INT >= 26) {
            nm.createNotificationChannel(
                NotificationChannel(CHANNEL, "SMS gateway", NotificationManager.IMPORTANCE_LOW)
            )
        }
        val open = PendingIntent.getActivity(
            this, 0, Intent(this, MainActivity::class.java), PendingIntent.FLAG_IMMUTABLE
        )
        val n: Notification = NotificationCompat.Builder(this, CHANNEL)
            .setSmallIcon(android.R.drawable.stat_notify_chat)
            .setContentTitle("SMS Gateway is running")
            .setContentText("Waiting for messages from your server")
            .setOngoing(true)
            .setContentIntent(open)
            .build()
        if (Build.VERSION.SDK_INT >= 29) {
            ServiceCompat.startForeground(this, NOTIF_ID, n, ServiceInfo.FOREGROUND_SERVICE_TYPE_DATA_SYNC)
        } else {
            startForeground(NOTIF_ID, n)
        }
    }

    private fun pollLoop() {
        val ctx = applicationContext
        while (running) {
            try {
                StatusReporter.flush(ctx)
                val messages = Api.pending(ctx)
                Prefs.setPollResult(ctx, null)
                for (m in messages) {
                    MessageLog.add(ctx, m.id, m.recipient, "sending")
                    SmsSender.send(ctx, m)
                    sleepQuietly(1000)   // do not burst; Android also rate-limits SMS per app
                }
            } catch (e: ApiException) {
                Prefs.setPollResult(ctx, e.message ?: "HTTP ${e.code}")
            } catch (e: InterruptedException) {
                break
            } catch (e: Exception) {
                Prefs.setPollResult(ctx, "Cannot reach server: ${e.message ?: e.javaClass.simpleName}")
            }
            if (!sleepQuietly(POLL_MS)) break
        }
    }

    /** Returns false if interrupted (service stopping). */
    private fun sleepQuietly(ms: Long): Boolean = try {
        Thread.sleep(ms); true
    } catch (e: InterruptedException) {
        false
    }

    override fun onDestroy() {
        running = false
        worker?.interrupt()
        wakeLock?.let { if (it.isHeld) it.release() }
        super.onDestroy()
    }

    companion object {
        private const val CHANNEL = "gateway"
        private const val NOTIF_ID = 1
        private const val POLL_MS = 4000L

        fun start(ctx: Context) {
            val i = Intent(ctx, GatewayService::class.java)
            if (Build.VERSION.SDK_INT >= 26) ctx.startForegroundService(i) else ctx.startService(i)
        }

        fun stop(ctx: Context) {
            ctx.stopService(Intent(ctx, GatewayService::class.java))
        }
    }
}
