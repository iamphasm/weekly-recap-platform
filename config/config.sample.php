<?php
// Copy to config/config.php and fill in.
return [
    'base_url' => 'https://example.com/recap',   // public URL, no trailing slash
    'site_name' => 'Weekly Recap',
    'timezone' => 'Europe/Oslo',
    // Students may only use emails from these domains (lowercase, without @). Empty array = allow any.
    'school_domains' => ['school.example'],

    'db' => [
        'host' => 'localhost',
        'name' => 'weekly_recap',
        'user' => 'recap',
        'pass' => 'change-me',
    ],

    'mail' => [
        'from_email' => 'recap@school.example',
        'from_name'  => 'Weekly Recap',
        // 'smtp' uses PHPMailer (composer install). If PHPMailer is missing, falls back to mail().
        'smtp_host' => 'smtp.example.com',
        'smtp_port' => 587,
        'smtp_secure' => 'tls',      // 'tls', 'ssl' or ''
        'smtp_user' => 'recap@school.example',
        'smtp_pass' => 'change-me',
    ],
];
