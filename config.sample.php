<?php
// =====================================================================
// Smart Bin - configuration
// Copy this file to config.php and fill in your own values.
// config.php is NOT in the repo (it holds passwords), only this sample.
// =====================================================================

return [
    // --- MySQL (XAMPP) -----------------------------------------------
    'db' => [
        'host' => 'localhost',
        'user' => 'root',
        'pass' => '',            // your MySQL root password ('' on a fresh XAMPP)
        'name' => 'smart_bin',
    ],

    // All times are shown in Oman time
    'timezone'     => 'Asia/Muscat',
    'utc_offset'   => '+04:00',

    // --- Alerts -------------------------------------------------------
    // Each channel can be switched off with 'enabled' => false.
    'alerts' => [

        // Email through Gmail SMTP.
        // Gmail needs an "app password" (Google account > Security >
        // 2-Step Verification > App passwords), NOT your normal password.
        'email' => [
            'enabled'      => false,
            'smtp_host'    => 'smtp.gmail.com',
            'smtp_port'    => 587,               // STARTTLS
            'username'     => 'yourteam@gmail.com',
            'app_password' => 'xxxx xxxx xxxx xxxx',
            'from_name'    => 'Smart Bin Alerts',
            'to'           => ['operator@example.com'],   // one or more addresses
        ],

        // WhatsApp through the free CallMeBot API.
        // Each phone must first send "I allow callmebot to send me messages"
        // to CallMeBot on WhatsApp, which replies with that phone's API key.
        'whatsapp' => [
            'enabled'    => false,
            'recipients' => [
                ['phone' => '+96890000000', 'apikey' => '123456'],
            ],
        ],

        // Seconds to wait for SMTP / CallMeBot before giving up (keeps
        // ingest.php fast even if the internet is slow).
        'timeout_s' => 8,

        // Objective 2: also send ONE message when a bin reaches full_pct
        // (reset when the bin is emptied). FIRE alerts are always sent.
        'notify_full' => false,
    ],
];
