<?php
/**
 * Simple SVG helper to inline icons from public/img/icons and allow adding attributes.
 * Usage: echo svg_icon('phone', ['class' => 'icon-sm', 'aria-hidden' => 'true']);
 */
function svg_icon(string $name, array $attrs = []): string
{
    $base = __DIR__ . '/../../public/img/icons/';
    $file = $base . $name . '.svg';
    if (!is_file($file)) {
        return '';
    }

    $svg = file_get_contents($file);
    if ($svg === false) {
        return '';
    }

    // Default aria-hidden true unless explicitly provided
    if (!array_key_exists('aria-hidden', $attrs)) {
        $attrs['aria-hidden'] = 'true';
    }

    // Build class merging logic
    $class = '';
    if (isset($attrs['class'])) {
        $class = trim($attrs['class']);
        unset($attrs['class']);
    }

    // Insert attributes into the opening <svg ...> tag.
    $svg = preg_replace_callback('#<svg(\b[^>]*)>#i', function ($m) use ($class, $attrs) {
        $existing = $m[1];

        // Merge classes
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
            // skip empty
            if ($v === null || $v === '') continue;
            // avoid duplicating attributes already present
            if (preg_match('/\b' . preg_quote($k, '/') . '\s*=\s*/i', $existing)) continue;
            $existing .= ' ' . $k . '="' . htmlspecialchars((string)$v, ENT_QUOTES) . '"';
        }

        return '<svg' . $existing . '>';
    }, $svg, 1);

    return $svg;
}

