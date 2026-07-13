<?php

return [
    'conscribe' => [
        'ver' => '1.3.2',
        'author' => 'Majklovitch',
        'environment' => 'development',
        'base_url' => 'http://localhost:8080/',
    ],
    'contact_form' => [
        'to_email' => 'test@testserver.com',
        'from_email' => 'noreply@testserver.com',
    ],
    'db' => [
        'host'    => 'host',
        'dbname'  => 'database_name',
        'user'    => 'user',
        'pass'    => 'password',
        'charset' => 'utf8mb4',
    ],
    'tracking' => [
        'ga4_id'      => null, // Google Analytics 4 Measurement ID (e.g., 'G-XXXXXXXXXX')
        'gtag_id'     => null, // Google Tag / Global Site Tag ID (e.g., 'GTA-XXXXXXXXXX')
        'adwords_id'  => null, // Google Ads Conversion ID (e.g., 'AW-XXXXXXXXX')
        'sklik_id'    => null, // Sklik Retargeting ID (e.g., '123456')
        'fb_pixel_id' => null, // Facebook Pixel ID (e.g., '1234567890')
    ],
];
