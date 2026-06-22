<?php
namespace App\Helpers;

use DateTime;
use Exception;

class TemplateHelper {
    public static function esc(string $string): string {
        return trim(htmlspecialchars($string, ENT_QUOTES));
    }

    /**
     * @throws Exception
     */
    public static function date(?string $date, string $format = 'd. m. Y'): string {
        if (!$date) return '';
        return (new DateTime($date))->format($format);
    }
    public static function truncate(string $text, int $length = 100, string $append = '...'): string {
        if (mb_strlen($text) <= $length) return $text;
        return mb_substr($text, 0, $length) . $append;
    }
    public static function component(string $name, array $data = []): void {
        $file = __DIR__ . "/../components/$name.php";
        if(file_exists($file)) {
            extract($data);
            include $file;
        } else {
            echo "";
        }
    }
    public static function asset(string $path): string {
        $version = file_exists($path) ? filemtime($path) : '1.0';
        return '/' . $path . '?v=' . $version;
    }
    public static function svg(string $name, array $attrs = []): string {
        $base = __DIR__ . '/../../public/img/icons/';
        $file = $base . $name . '.svg';
        if (!is_file($file)) {
            return '';
        }

        $svg = file_get_contents($file);
        if ($svg === false) {
            return '';
        }
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
}