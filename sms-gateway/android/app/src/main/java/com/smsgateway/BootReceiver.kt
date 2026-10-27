package com.smsgateway

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

/** Restarts the gateway after a reboot if it was enabled. */
class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != Intent.ACTION_BOOT_COMPLETED) return
        if (Prefs.enabled(context) && Prefs.configured(context)) {
            GatewayService.start(context)
        }
    }
}
