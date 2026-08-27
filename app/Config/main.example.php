<?php

return [
    'conscribe' => [
        'ver' => '1.3.9', // version of the framework, not your app
        'author' => 'Majklovitch', // make this whatever you want, i dont mind if you copy code
        'environment' => 'development', // 'development' | 'production'
        'base_url' => 'http://localhost:8080/', // the base URL of your site, used for generating absolute URLs
    ],
    'modules' => [ // list of modules to load, in order
        'LanguageMutations',
    ],
    'media' => [
        'driver'        => 'auto',          // 'auto' | 'imagick' | 'gd'
        'source_root'   => null,            // null = public root
        'cache_path'    => 'media/cache',   // relative to public root
        'quality'       => 82,              // 0-100 - higher = better quality, bigger file
        'format'        => 'webp',          // 'webp' | 'jpg' | 'jpeg' | 'png' | 'gif'
        'max_dimension' => 4000,            // max width or height of source image, bigger images are rejected
        'presets'       => [                // named sizes for convenience, e.g.
            'thumb' => [500, 500],
            'card'  => [600, 400],
        ],
    ],
    'log' => [
        'enabled'   => true,
        'name'      => 'conscribe',        // channel name in the log line
        'path'      => 'storage/logs/app.log', // relative to project root, or absolute
        'level'     => null,               // null = debug in development, warning in production
                                           // 'debug'|'info'|'notice'|'warning'|'error'|'critical'|'alert'|'emergency'
        'max_files' => 14,                 // rotated daily; 0 = keep everything
        'stderr'    => false,              // true = also write to stderr (visible in `docker logs`)
    ],
    'session' => [
        'name'                => 'CONSCRIBEID', // cookie name; PHPSESSID advertises the stack
        'lifetime'            => 0,             // 0 = until the browser is closed
        'path'                => '/',
        'domain'              => '',
        'secure'              => null,          // null = auto-detect from the current protocol
        'httponly'            => true,
        'samesite'            => 'Lax',         // 'Lax' | 'Strict' | 'None' ('None' requires secure)
        'regenerate_interval' => 1800,          // seconds between automatic session ID rotations, 0 = off
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
