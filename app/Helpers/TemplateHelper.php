<?php

use App\Core\Config;
use App\Core\Http\Session;

/**
 * Escapes a value for insertion into HTML.
 * Accepts null and numbers too, because template data is typically optional.
 */
function esc(mixed $value): string {
    if ($value === null || is_array($value) || (is_object($value) && !$value instanceof Stringable)) {
        return '';
    }

    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * @throws DateMalformedStringException
 */
function dateFormat(?string $date, string $format = 'd. m. Y'): string {
    if (!$date) return '';
    return (new DateTime($date))->format($format);
}
function truncate(string $text, int $length = 100, string $append = '...'): string {
    if (mb_strlen($text) <= $length) return $text;
    return mb_substr($text, 0, $length) . $append;
}
function component(string $name, array $data = []): void {
    if (str_contains($name, '..')) {
        return;
    }

    $file = dirname(__DIR__) . "/Views/components/$name.php";
    if (is_file($file)) {
        extract($data, EXTR_SKIP);
        include $file;
    }
}

/**
 * Link to a static file with a cache buster derived from its modification time.
 * The path is resolved inside public/, regardless of the working directory.
 */
function asset(string $path): string {
    $relative = ltrim($path, '/');
    $file = dirname(__DIR__, 2) . '/public/' . $relative;
    $version = is_file($file) ? (string) filemtime($file) : '1.0';

    return '/' . $relative . '?v=' . $version;
}
/**
 * Link to a resized variant of an image in public/. The file is generated on
 * the first render and afterwards merely served from the cache directory.
 *
 *   image('img/photo.jpg', 1200, 500)   => /media/cache/1200x500/img/photo.jpg.webp
 *   image('img/photo.jpg', 'thumb')     => dimensions from the media.presets config
 *
 * When the variant cannot be produced (no library, missing file), the original
 * is returned via asset() - this helper runs during rendering and must not
 * bring the page down.
 *
 * @param array{format?: string} $options
 */
function image(string $path, int|string $width, int $height = 0, array $options = []): string {
    // ImageCache rejects traversal, but asset() does not - without this
    // safeguard the fallback would print a path outside public/, complete with
    // a ?v= based on the file time.
    if (str_contains($path, '..') || str_contains($path, "\0")) {
        return '';
    }

    if (is_string($width)) {
        $preset = \App\Services\MediaService::preset($width);
        if ($preset === null) {
            return asset($path);
        }

        [$width, $height] = $preset;
    }

    if ($width < 1 || $height < 1) {
        return asset($path);
    }

    return \App\Services\MediaService::url($path, $width, $height, $options) ?? asset($path);
}

/**
 * A ready-made <img> tag with the resized image. The dimensions are written
 * into the attributes so the browser need not reflow the layout after loading.
 *
 * @param array<string, string|null> $attrs
 */
function image_tag(string $path, int|string $width, int $height = 0, array $attrs = []): string {
    if (is_string($width)) {
        $preset = \App\Services\MediaService::preset($width);
        [$width, $height] = $preset ?? [0, 0];
    }

    $attrs += [
        'src'      => image($path, $width, $height),
        'alt'      => '',
        'loading'  => 'lazy',
        'decoding' => 'async',
    ];

    if ($width > 0 && $height > 0) {
        $attrs += ['width' => (string) $width, 'height' => (string) $height];
    }

    $html = '';
    foreach ($attrs as $name => $value) {
        if ($value === null) {
            continue;
        }

        $html .= ' ' . esc($name) . '="' . esc($value) . '"';
    }

    return '<img' . $html . '>';
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

    // Inline SVGs run in the JS context of the page, so XSS vectors must be
    // stripped before output. This covers the most common cases without
    // needing an external library.
    $svg = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $svg);
    $svg = preg_replace('/<foreignObject\b[^>]*>.*?<\/foreignObject>/is', '', $svg);
    $svg = preg_replace('/\bon\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $svg);
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
 * Renders the tracking codes (GA4, Gtag, AdWords, Sklik, Facebook Pixel)
 * into the header.
 */
function renderTrackingCodes(): string {
    // Without a configuration of its own nothing is rendered - falling back to
    // main.example.php would let the sample IDs onto the live site.
    $tracking = Config::get('tracking', []);

    if (!is_array($tracking) || empty($tracking)) {
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
        // The value goes into JS as a numeric literal, hence the hard cast to int.
        $sklikId = (int) trim((string) $tracking['sklik_id']);
        $html .= "\n    <!-- Sklik Retargeting -->\n";
        $html .= "    <script type=\"text/javascript\" src=\"https://c.seznam.cz/js/rc.js\"></script>\n";
        $html .= "    <script type=\"text/javascript\">\n";
        $html .= "        /* <![CDATA[ */\n";
        $html .= "        var seznam_retargeting_id = " . $sklikId . ";\n";
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
 * A flash message stored by the previous request, e.g. after a redirect from
 * a form. It is written in the controller via $this->session()->flash().
 */
function flash(string $key, mixed $default = null): mixed {
    return Session::instance()->getFlash($key, $default);
}

function has_flash(string $key): bool {
    return Session::instance()->hasFlash($key);
}

/**
 * A previously submitted form value, for pre-filling after a validation error.
 */
function old(string $key, mixed $default = ''): mixed {
    return Session::instance()->old($key, $default);
}

function csrf_token(): string {
    return Session::instance()->token();
}

function csrf_field(): string {
    // Nothing reads form_load_time yet; it is kept for a future check against
    // a form submitted suspiciously fast.
    Session::instance()->set('form_load_time', time());

    return '<input type="hidden" name="csrf_token" value="' . esc(csrf_token()) . '">';
}

/**
 * Validates the CSRF token of the request against the one in the session.
 * With no request passed in, one is built from the superglobals.
 */
function validate_csrf(?\App\Core\Http\Request $request = null): bool {
    if ($request === null) {
        $request = \App\Core\Http\Request::createFromGlobals();
    }

    $token = $request->post('csrf_token') ?? '';

    // AJAX calls send the token in a header rather than in the body.
    if (empty($token)) {
        $token = $request->getHeader('x-csrf-token') ?? '';
    }

    return Session::instance()->validateToken(is_string($token) ? $token : '');
}

/**
 * Sends a 403 and terminates the request when the CSRF token is invalid.
 */
function check_csrf(?\App\Core\Http\Request $request = null, ?\App\Core\Http\Response $response = null): void {
    if (!validate_csrf($request)) {
        if ($response === null) {
            $response = new \App\Core\Http\Response();
        }
        $response->setStatusCode(403);
        $response->setContent("<h1>403 Forbidden</h1><p>CSRF token validation failed. Request blocked.</p>");
        $response->send();
        exit;
    }
}
