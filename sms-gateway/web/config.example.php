<?php
// Copy this file to config.php and edit. config.php is never committed/shared.
return [
    'db' => [
        'dsn'  => 'mysql:host=127.0.0.1;port=3306;dbname=sms_gateway;charset=utf8mb4',
        'user' => 'root',
        'pass' => '',
    ],
    // LAN URL the phone will use to reach this server (shown on the Devices page).
    'base_url' => 'http://192.168.1.10:8000',
    // Max send-sms API calls per API key per minute.
    'send_rate_limit_per_minute' => 60,
    // Set false after you created your account to block new sign-ups.
    'allow_registration' => true,
];
