<?php

return [
    'conscribe' => [
        'ver' => '1.4.0', // version of the framework, not your app
        'author' => 'Majklovitch', // make this whatever you want, i dont mind if you copy code
        'environment' => 'development', // 'development' | 'production'
        'base_url' => 'http://localhost:8080/', // the base URL of your site, used for generating absolute URLs

        // Reverse proxies whose X-Forwarded-* headers may be believed. Anyone
        // can send those headers, so an empty list (the default) means the
        // application trusts only what the web server itself reports - see
        // Request::isSecure() and Request::getClientIp(). Entries are IPs or
        // CIDR blocks, IPv4 and IPv6:
        //
        //   'trusted_proxies' => ['10.0.0.0/8', '2400:cb00::/32'],
        'trusted_proxies' => [],
                                               // and for the <loc> entries in /sitemap.xml
    ],
    'security' => [
        // Content-Security-Policy. Empty = no CSP header at all, which is how
        // the framework behaved before this key existed. '{nonce}' is replaced
        // with a fresh per-request nonce; render it into your inline scripts
        // with the csp_nonce_attr() template helper.
        'csp' => '',
        // Ship a new policy report-only first: violations show up in the
        // browser console and nothing is actually blocked. Flip to false once
        // the console is quiet.
        'csp_report_only' => true,
        //
        // A starting point for a site using the built-in tracking codes:
        //
        // 'csp' => "default-src 'self'; "
        //     . "script-src 'self' 'nonce-{nonce}' https://www.googletagmanager.com https://c.seznam.cz https://connect.facebook.net; "
        //     . "style-src 'self' 'unsafe-inline'; "
        //     . "img-src 'self' data: https://www.facebook.com https://www.google-analytics.com; "
        //     . "connect-src 'self' https://www.google-analytics.com; "
        //     . "font-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'; object-src 'none'",
    ],
    // sitemap.xml is generated from the route table - there is nothing to configure
    // here. Which pages are listed is decided by the route file (app/Config/routes/),
    // and a route may override its group:
    //
    //   $router->get('secret', $h, ['sitemap' => false]);
    //   $router->get('', $h, ['sitemap' => ['changefreq' => 'daily', 'priority' => 1.0]]);
    //   $router->get('clanek/{slug}', $h, ['sitemap' => ['provider' => fn () => $repo->slugs()]]);
    //
    // A provider returns rows of parameter values (['slug' => 'hello']) or ready
    // App\Services\Sitemap\SitemapUrl objects when it can supply a lastmod.
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
    'assets' => [
        'auto_compile' => true,             // development only - recompile when a source file is newer
        'source_maps'  => true,             // development only - emit public/css/style.css.map
        'scss' => [
            'entry'  => 'assets/scss/main.scss',  // paths are relative to the project root
            'watch'  => 'assets/scss',            // scanned for staleness
            'output' => 'public/css/style.css',
        ],
        'js' => [
            'sources' => ['assets/js/main.js'],   // concatenated in this order
            'watch'   => 'assets/js',
            'output'  => 'public/js/main.js',
            'minify'  => true,              // false ships concatenated sources, see assets/js/README.md
        ],
    ],
    'log' => [
        'enabled'    => true,
        'name'       => 'conscribe',        // channel name in the log line
        'path'       => 'storage/logs/app.log', // relative to project root, or absolute
        'level'      => null,               // null = debug in development, warning in production
                                            // 'debug'|'info'|'notice'|'warning'|'error'|'critical'|'alert'|'emergency'
        'max_files'  => 14,                 // rotated daily; 0 = keep everything
                                            // also the age limit of the dumps below
        'dumps'      => true,               // save the Tracy blue screen of every uncaught
                                            // exception and fatal as an .html file
        'dumps_path' => null,               // null = storage/exceptions (beside the log directory);
                                            // relative to the project root, or absolute.
                                            // Must not be web-reachable.
        'stderr'     => false,              // true = also write to stderr (visible in `docker logs`)
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
