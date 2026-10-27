package com.smsgateway

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.os.PowerManager
import android.provider.Settings
import android.view.View
import android.widget.Button
import android.widget.EditText
import android.widget.TextView
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.appcompat.widget.SwitchCompat
import androidx.core.content.ContextCompat
import okhttp3.HttpUrl.Companion.toHttpUrlOrNull
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.concurrent.Executors

class MainActivity : AppCompatActivity() {
    private lateinit var setupScreen: View
    private lateinit var statusScreen: View
    private lateinit var etServer: EditText
    private lateinit var etKey: EditText
    private lateinit var etName: EditText
    private lateinit var btnConnect: Button
    private lateinit var tvSetupError: TextView
    private lateinit var tvConnection: TextView
    private lateinit var tvDetail: TextView
    private lateinit var tvError: TextView
    private lateinit var tvLog: TextView
    private lateinit var swEnable: SwitchCompat

    private val io = Executors.newSingleThreadExecutor()
    private val ui = Handler(Looper.getMainLooper())
    private val refresh = object : Runnable {
        override fun run() {
            renderStatus()
            ui.postDelayed(this, 2000)
        }
    }
    private var suppressSwitch = false

    private val permissionRequest =
        registerForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) {
            if (hasSmsPermission()) {
                enableGateway()
            } else {
                setSwitch(false)
                tvError.text = "SMS permission is required to send messages."
            }
        }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)
        setupScreen = findViewById(R.id.setupScreen)
        statusScreen = findViewById(R.id.statusScreen)
        etServer = findViewById(R.id.etServer)
        etKey = findViewById(R.id.etKey)
        etName = findViewById(R.id.etName)
        btnConnect = findViewById(R.id.btnConnect)
        tvSetupError = findViewById(R.id.tvSetupError)
        tvConnection = findViewById(R.id.tvConnection)
        tvDetail = findViewById(R.id.tvDetail)
        tvError = findViewById(R.id.tvError)
        tvLog = findViewById(R.id.tvLog)
        swEnable = findViewById(R.id.swEnable)

        etName.setText(Build.MODEL)
        btnConnect.setOnClickListener { connect() }
        findViewById<Button>(R.id.btnBattery).setOnClickListener { requestBatteryExemption() }
        findViewById<Button>(R.id.btnDisconnect).setOnClickListener { disconnect() }
        swEnable.setOnCheckedChangeListener { _, checked ->
            if (suppressSwitch) return@setOnCheckedChangeListener
            if (checked) startEnable() else disableGateway()
        }
    }

    override fun onResume() {
        super.onResume()
        ui.post(refresh)
    }

    override fun onPause() {
        ui.removeCallbacks(refresh)
        super.onPause()
    }

    // ---- connect / disconnect ----

    private fun connect() {
        val server = etServer.text.toString().trim().trimEnd('/')
        val key = etKey.text.toString().trim()
        val name = etName.text.toString().trim().ifEmpty { Build.MODEL }
        val url = server.toHttpUrlOrNull()
        when {
            url == null || (url.scheme != "http" && url.scheme != "https") ->
                return showSetupError("Enter a full URL such as http://192.168.1.10:8000")
            url.scheme == "http" && !NetRules.isPrivateHost(url.host) ->
                return showSetupError("Plain http:// is only allowed for local network addresses. Use https:// or a LAN IP.")
            key.isEmpty() -> return showSetupError("Enter the API key from the dashboard.")
        }
        showSetupError("")
        btnConnect.isEnabled = false
        btnConnect.text = "Connecting…"
        io.execute {
            val result = try {
                Result.success(Api.register(server, key, name, Build.MODEL, Prefs.deviceId(this)))
            } catch (e: Exception) {
                Result.failure(e)
            }
            runOnUiThread {
                btnConnect.isEnabled = true
                btnConnect.text = "Connect"
                result.onSuccess {
                    Prefs.saveConnection(this, server, key, it)
                    etKey.setText("")
                    renderStatus()
                }.onFailure {
                    showSetupError(
                        if (it is ApiException) it.message ?: "HTTP ${it.code}"
                        else "Cannot reach the server: ${it.message ?: it.javaClass.simpleName}"
                    )
                }
            }
        }
    }

    private fun disconnect() {
        GatewayService.stop(this)
        Prefs.disconnect(this)
        renderStatus()
    }

    private fun showSetupError(msg: String) {
        tvSetupError.text = msg
    }

    // ---- enable / disable ----

    private fun hasSmsPermission() =
        ContextCompat.checkSelfPermission(this, Manifest.permission.SEND_SMS) == PackageManager.PERMISSION_GRANTED

    private fun startEnable() {
        if (hasSmsPermission()) {
            enableGateway()
            return
        }
        val perms = mutableListOf(Manifest.permission.SEND_SMS)
        if (Build.VERSION.SDK_INT >= 33) perms.add(Manifest.permission.POST_NOTIFICATIONS)
        permissionRequest.launch(perms.toTypedArray())
    }

    private fun enableGateway() {
        tvError.text = ""
        Prefs.setEnabled(this, true)
        GatewayService.start(this)
        setSwitch(true)
    }

    private fun disableGateway() {
        Prefs.setEnabled(this, false)
        GatewayService.stop(this)
    }

    private fun setSwitch(v: Boolean) {
        suppressSwitch = true
        swEnable.isChecked = v
        suppressSwitch = false
    }

    private fun requestBatteryExemption() {
        val pm = getSystemService(PowerManager::class.java)
        if (pm.isIgnoringBatteryOptimizations(packageName)) {
            tvError.text = "Battery optimization is already off for this app."
            return
        }
        try {
            startActivity(
                Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, Uri.parse("package:$packageName"))
            )
        } catch (e: Exception) {
            startActivity(Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS))
        }
    }

    // ---- status screen ----

    private fun renderStatus() {
        if (!Prefs.configured(this)) {
            setupScreen.visibility = View.VISIBLE
            statusScreen.visibility = View.GONE
            return
        }
        setupScreen.visibility = View.GONE
        statusScreen.visibility = View.VISIBLE

        val enabled = Prefs.enabled(this)
        setSwitch(enabled)
        val last = Prefs.lastPoll(this)
        val ageSec = (System.currentTimeMillis() - last) / 1000
        val connected = enabled && last > 0 && ageSec <= 15 && Prefs.lastError(this).isEmpty()

        tvConnection.text = when {
            !enabled -> "Gateway is off"
            connected -> "Connected"
            else -> "Not connected"
        }
        val fmt = SimpleDateFormat("HH:mm:ss", Locale.getDefault())
        tvDetail.text = "Server: ${Prefs.serverUrl(this)}\n" +
            "Device #${Prefs.deviceId(this)}\n" +
            "Last poll: " + if (last > 0) fmt.format(Date(last)) else "never"
        tvError.text = if (enabled) Prefs.lastError(this) else ""
        tvLog.text = MessageLog.lines(this)
    }
}
