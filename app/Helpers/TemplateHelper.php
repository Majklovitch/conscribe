<?php
function esc(string $string): string {
    return trim(htmlspecialchars($string, ENT_QUOTES));
}

function dateFormat(?string $date, string $format = 'd. m. Y'): string {
    if (!$date) return '';
    return (new DateTime($date))->format($format);
}
function truncate(string $text, int $length = 100, string $append = '...'): string {
    if (mb_strlen($text) <= $length) return $text;
    return mb_substr($text, 0, $length) . $append;
}
function component(string $name, array $data = []): void {
    $file = __DIR__ . "/../components/$name.php";
    if(file_exists($file)) {
        extract($data);
        include $file;
    } else {
        echo "";
    }
}
function asset(string $path): string {
    $version = file_exists($path) ? filemtime($path) : '1.0';
    return '/' . $path . '?v=' . $version;
}
function svg(string $name, array $attrs = []): string {
    $base = __DIR__ . '/../../public/img/icons/';
    $file = $base . $name . '.svg';
    if (!is_file($file)) {
        return '';
    }

    $svg = file_get_contents($file);
    if ($svg === false) {
        return '';
    }

    // --- SVG Sanitization ---
    // Inline SVGs run in the page's JS context, so we must strip XSS vectors
    // before output. This covers the most common attack vectors without needing
    // an external library.

    // 1. Remove <script> … </script> blocks (case-insensitive, multiline)
    $svg = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $svg);

    // 2. Remove <foreignObject> … </foreignObject> (allows arbitrary HTML injection)
    $svg = preg_replace('/<foreignObject\b[^>]*>.*?<\/foreignObject>/is', '', $svg);

    // 3. Strip on* event handler attributes (onclick, onload, onerror, etc.)
    $svg = preg_replace('/\bon\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $svg);

    // 4. Strip href / xlink:href / src with javascript: or data: URIs
    $svg = preg_replace('/\b(?:href|xlink:href|src)\s*=\s*["\']?\s*(?:javascript|data):[^"\'>\s]*/i', '', $svg);

    if (!array_key_exists('aria-hidden', $attrs)) {
        $attrs['aria-hidden'] = 'true';
    }
    $class = '';
    if (isset($attrs['class'])) {
        $class = trim($attrs['class']);
        unset($attrs['class']);
    }
    $svg = preg_replace_callback('#<svg(\b[^>]*)>#i', function ($m) use ($class, $attrs) {
        $existing = $m[1];
        if ($class !== '') {
            if (preg_match('/class\s*=\s*"([^"]*)"/i', $existing, $cm)) {
                $newClass = trim($cm[1] . ' ' . $class);
                $existing = preg_replace('/class\s*=\s*"([^"]*)"/i', 'class="' . $newClass . '"', $existing);
            } else {
                $existing .= ' class="' . $class . '"';
            }
        }

        // Append other attributes
        foreach ($attrs as $k => $v) {
            if ($v === null || $v === '') continue;
            if (preg_match('/\b' . preg_quote($k, '/') . '\s*=\s*/i', $existing)) continue;
            $existing .= ' ' . $k . '="' . htmlspecialchars((string)$v, ENT_QUOTES) . '"';
        }

        return '<svg' . $existing . '>';
    }, $svg, 1);

    return $svg;
}

/**
 * Renders tracking codes (GA4, Gtag, AdWords, Sklik, Facebook Pixel) in the header.
 * 
 * @return string HTML script tags for the tracking codes.
 */
function renderTrackingCodes(): string {
    $configPath = dirname(__DIR__) . '/config.php';
    if (!file_exists($configPath)) {
        // Fallback to example configuration if the main config doesn't exist
        $configPath = dirname(__DIR__) . '/config.example.php';
    }

    if (!file_exists($configPath)) {
        return '';
    }

    $config = require $configPath;
    $tracking = $config['tracking'] ?? [];

    if (empty($tracking)) {
        return '';
    }

    $html = '';

    // --- Google Tag (gtag.js) Configuration (GA4, Gtag, AdWords) ---
    $googleIds = [];
    if (!empty($tracking['ga4_id'])) {
        $googleIds[] = trim($tracking['ga4_id']);
    }
    if (!empty($tracking['gtag_id'])) {
        $googleIds[] = trim($tracking['gtag_id']);
    }
    if (!empty($tracking['adwords_id'])) {
        $googleIds[] = trim($tracking['adwords_id']);
    }

    if (!empty($googleIds)) {
        $primaryId = $googleIds[0];
        $html .= "\n    <!-- Global site tag (gtag.js) - Google Analytics / Ads -->\n";
        $html .= "    <script async src=\"https://www.googletagmanager.com/gtag/js?id=" . esc($primaryId) . "\"></script>\n";
        $html .= "    <script>\n";
        $html .= "        window.dataLayer = window.dataLayer || [];\n";
        $html .= "        function gtag(){dataLayer.push(arguments);}\n";
        $html .= "        gtag('js', new Date());\n";
        foreach ($googleIds as $id) {
            $html .= "        gtag('config', '" . esc($id) . "');\n";
        }
        $html .= "    </script>\n";
    }

    // --- Sklik Retargeting ---
    if (!empty($tracking['sklik_id'])) {
        $sklikId = trim($tracking['sklik_id']);
        $html .= "\n    <!-- Sklik Retargeting -->\n";
        $html .= "    <script type=\"text/javascript\" src=\"https://c.seznam.cz/js/rc.js\"></script>\n";
        $html .= "    <script type=\"text/javascript\">\n";
        $html .= "        /* <![CDATA[ */\n";
        $html .= "        var seznam_retargeting_id = " . esc($sklikId) . ";\n";
        $html .= "        if (window.rc && window.rc.retargeting) {\n";
        $html .= "            window.rc.retargeting(seznam_retargeting_id);\n";
        $html .= "        }\n";
        $html .= "        /* ]]> */\n";
        $html .= "    </script>\n";
    }

    // --- Facebook Pixel ---
    if (!empty($tracking['fb_pixel_id'])) {
        $fbPixelId = trim($tracking['fb_pixel_id']);
        $html .= "\n    <!-- Facebook Pixel Code -->\n";
        $html .= "    <script>\n";
        $html .= "        !function(f,b,e,v,n,t,s)\n";
        $html .= "        {if(f.fbq)return;n=f.fbq=function(){n.callMethod?\n";
        $html .= "        n.callMethod.apply(n,arguments):n.queue.push(arguments)};\n";
        $html .= "        if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';\n";
        $html .= "        n.queue=[];t=b.createElement(e);t.async=!0;\n";
        $html .= "        t.src=v;s=b.getElementsByTagName(e)[0];\n";
        $html .= "        s.parentNode.insertBefore(t,s)}(window, document,'script',\n";
        $html .= "        'https://connect.facebook.net/en_US/fbevents.js');\n";
        $html .= "        fbq('init', '" . esc($fbPixelId) . "');\n";
        $html .= "        fbq('track', 'PageView');\n";
        $html .= "    </script>\n";
        $html .= "    <noscript>\n";
        $html .= "        <img height=\"1\" width=\"1\" style=\"display:none\" src=\"https://www.facebook.com/tr?id=" . esc($fbPixelId) . "&ev=PageView&noscript=1\"/>\n";
        $html .= "    </noscript>\n";
        $html .= "    <!-- End Facebook Pixel Code -->\n";
    }

    return $html;
}

/**
 * Generates a CSRF token if one doesn't exist, stores it in session, and returns it.
 * 
 * @return string The CSRF token.
 */
function csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Returns a hidden input HTML tag containing the CSRF token.
 * 
 * @return string HTML input element.
 */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . esc(csrf_token()) . '">';
}

/**
 * Validates the request's CSRF token against the one stored in session.
 * Checks both POST data and HTTP headers (X-CSRF-TOKEN).
 * 
 * @return bool True if the token is valid, false otherwise.
 */
function validate_csrf(): bool {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $token = $_POST['csrf_token'] ?? '';

    // Check header if not present in POST (useful for AJAX)
    if (empty($token) && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'];
    }

    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Aborts the request with a 403 Forbidden response if CSRF validation fails.
 * 
 * @return void
 */
function check_csrf(): void {
    if (!validate_csrf()) {
        http_response_code(403);
        echo "<h1>403 Forbidden</h1>";
        echo "<p>CSRF token validation failed. Request blocked.</p>";
        exit;
    }
}


