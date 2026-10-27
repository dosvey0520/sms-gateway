# Local SMS Gateway (TextBee-style)

Your phone becomes an SMS gateway. A PHP + MySQL server on your PC queues messages; an Android app on the same Wi-Fi polls the server every 4 seconds and sends them through the phone's SIM.

```
 your PHP system / curl ──► PHP API + MySQL ◄── polls every 4 s ── Android app ──► SIM ──► SMS
                              ▲  dashboard                          reports sent / delivered / failed
```

v1 does one thing: **send SMS via API**. No Firebase, no cloud, no receive-SMS, no webhooks, no bulk CSV.

## 1. Server setup (PC)

Requirements: PHP 8.1+ with `pdo_mysql`, and MySQL 5.7+ / MariaDB 10.3+ (XAMPP and Laragon include both).

1. **Import the schema** (creates the `sms_gateway` database):
   ```
   mysql -u root -p < web/schema.sql
   ```
   Or paste `web/schema.sql` into phpMyAdmin's SQL tab.
2. **Create the config:** copy `web/config.example.php` to `web/config.php` and set the DB user/password and `base_url` (your PC's LAN address, see step 3).
3. **Find your PC's LAN IP:** Windows `ipconfig` (IPv4 Address), Linux `ip a`, macOS `ipconfig getifaddr en0`. Example: `192.168.1.10`.
4. **Start the server, listening on all interfaces** (not just localhost):
   ```
   php -S 0.0.0.0:8000 -t web web/router.php
   ```
   With XAMPP/Laragon you can instead put the `web` folder under `htdocs` (`.htaccess` handles the API routes; `mod_rewrite` must be on) and use that URL.
5. **Allow the port through the PC firewall.** Windows (admin PowerShell):
   ```
   New-NetFirewallRule -DisplayName "SMS Gateway" -Direction Inbound -Protocol TCP -LocalPort 8000 -Action Allow -Profile Private
   ```
6. Open `http://192.168.1.10:8000`, **register** an account. After that, set `allow_registration` to `false` in `config.php`.

## 2. Build and install the Android app

Requirements: Android Studio (or JDK 17 + Android SDK 34).

1. Open the `android/` folder in Android Studio and let Gradle sync. (Studio creates the Gradle wrapper for you.)
2. **Build → Build APK(s)**, or on the command line: `./gradlew assembleDebug`.
   The APK is at `android/app/build/outputs/apk/debug/app-debug.apk`.
3. Copy it to the phone (USB, or `adb install app-debug.apk`) and allow installs from unknown sources.
4. The phone needs a SIM that can send SMS, and must be on the **same Wi-Fi** as the PC.

## 3. Pair the phone

1. Dashboard → **Devices** → enter a name → **Generate pairing details**. A server URL and a one-time API key are shown.
2. In the app, type the URL (e.g. `http://192.168.1.10:8000`) and the key, then tap **Connect**. The device appears in the dashboard.
3. Turn on **Gateway enabled** and allow the SMS and notification permissions.
4. Tap **Allow background use (battery)** and accept. Without this, Android may stop the app when the screen is off.
5. Optional: on some phones (Xiaomi, Huawei, Samsung) also enable "Autostart" / "Never sleeping apps" in system settings.

The dashboard shows the device as **online** while it has contacted the server in the last 30 seconds.

## 4. Send an SMS through the API

Create a key under **API keys** (shown once). Numbers must be E.164 (`+` and country code).

```
curl -X POST http://192.168.1.10:8000/api/v1/gateway/devices/1/send-sms \
  -H "x-api-key: sg_your_key_here" \
  -H "Content-Type: application/json" \
  -d '{"recipients":["+639171234567"],"message":"Hello from my gateway"}'
# -> {"queued":1,"message_ids":[1]}
```

Check status:
```
curl -H "x-api-key: sg_your_key_here" "http://192.168.1.10:8000/api/v1/messages?status=delivered"
```

From your existing PHP system:
```php
$ch = curl_init('http://192.168.1.10:8000/api/v1/gateway/devices/1/send-sms');
curl_setopt_array($ch, [
  CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => ['x-api-key: sg_your_key_here', 'Content-Type: application/json'],
  CURLOPT_POSTFIELDS => json_encode(['recipients' => ['+639171234567'], 'message' => 'Order ready']),
]);
echo curl_exec($ch);
```

### Endpoints (JSON, header `x-api-key`)

| Method | Path | Used by |
|---|---|---|
| POST | `/api/v1/gateway/devices/{id}/send-sms` | you (body: `recipients[]`, `message`) |
| GET | `/api/v1/messages?status=&limit=&offset=` | you |
| POST | `/api/v1/devices/register` | the app |
| GET | `/api/v1/devices/{id}/pending` | the app |
| POST | `/api/v1/messages/{id}/status` | the app (`sent` / `delivered` / `failed`) |

Message lifecycle: `pending` → `processing` (claimed by the phone) → `sent` → `delivered`, or `failed` (with the error). A message stuck in `processing` for over 2 minutes goes back to `pending`.

## Things to know

- **Android limits SMS per app** (about 30 per 30 minutes on most versions, then it asks for confirmation or fails with "limit exceeded"). This is an OS rule, not a bug in the gateway. The app waits 1 second between messages.
- **Restart caveat:** if the app is killed after it handed a multi-part SMS to Android but before every receipt arrived, the server may re-queue it after 2 minutes and it can be sent twice.
- **Cleartext HTTP:** Android's network config cannot list IP ranges, so cleartext is allowed at the OS level and restricted in code: the app refuses `http://` to anything except localhost, private LAN IPs (10.x, 172.16-31.x, 192.168.x), link-local, and `.local`/single-word names. `https://` works anywhere.
- **No QR pairing:** a PHP QR library could not be installed here, so pairing is manual (two fields).
- This is for a trusted local network. Do not expose port 8000 to the internet without HTTPS and a reverse proxy.
- Use it only to message people who expect to hear from you.
