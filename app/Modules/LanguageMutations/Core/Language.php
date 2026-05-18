<?php
namespace Modules\LanguageMutations\Core {

    class Language {
        private static $translations = [];
        private static $currentLang = 'cs';
        private static $slugMap = [
            'sypke' => ['en' => 'loose', 'pl' => 'materialy-sypkie'],
            'kusove' => ['en' => 'bulk', 'pl' => 'produkty-kawalkowe'],
            'emulze' => ['en' => 'emulsion', 'pl' => 'emulsje'],
            'kapaliny' => ['en' => 'liquids', 'pl' => 'ciecze'],
            'viskozni' => ['en' => 'viscous', 'pl' => 'produkty-lepkie'],
            'doplnky' => ['en' => 'additional', 'pl' => 'akcesoria'],
            'obchodni-podminky' => ['en' => 'terms-and-conditions', 'pl' => 'warunki-handlowe'],
            'gdpr' => ['en' => 'privacy-policy', 'pl' => 'ochrona-danych'],
            'home' => ['en' => '', 'pl' => '']
        ];
        private static $anchorMap = [
            '#onas' => ['en' => '#about-us', 'pl' => '#o-nas'],
            '#kontakt' => ['en' => '#contact-us', 'pl' => '#kontakt'],
            '#kategorie' => ['en' => '#categories', 'pl' => '#kategorie']
        ];

        public static function setLang($lang) {
            self::$currentLang = $lang;
            self::$translations = [];

            if (!isset($_COOKIE['lang_preference']) || $_COOKIE['lang_preference'] !== $lang) {
                setcookie('lang_preference', $lang, time() + (86400 * 30), "/");
            }
        }
        public static function getBrowserLang() {
            if (isset($_COOKIE['lang_preference'])) {
                return;
            }

            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $isBot = preg_match('/bot|googlebot|crawler|spider/i', $userAgent);

            if (!$isBot && $_SERVER['REQUEST_URI'] === '/') {
                $lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'cs', 0, 2);

                $supported = ['en', 'pl'];
                if (in_array($lang, $supported)) {
                    setcookie('lang_preference', $lang, time() + (86400 * 30), "/");
                    header("Location: /$lang/");
                    exit();
                }
            }
        }

        // Pro routing (index.php): převede 'loose' na 'sypke'
        public static function getInternalPath($slug) {
            if (self::$currentLang === 'cs') return $slug;

            foreach (self::$slugMap as $internal => $langs) {
                if (isset($langs[self::$currentLang]) && $langs[self::$currentLang] === $slug) {
                    return $internal;
                }
            }
            return $slug;
        }

        // Pro přepínač jazyků (vlaječky)
        public static function getSwitchUrl($targetLang) {
            $currentPage = defined('CURRENT_PAGE') ? CURRENT_PAGE : 'home';

            $slug = self::$slugMap[$currentPage][$targetLang] ?? $currentPage;

            if ($slug === 'home') $slug = '';

            if ($targetLang === 'cs') {
                return "/" . ltrim($slug, '/');
            }

            return "/" . $targetLang . "/" . ltrim($slug, '/');
        }

        public static function load($pageName) {
            $locations = [$pageName, "products/$pageName", "pages/$pageName"];
            foreach ($locations as $location) {
                $targetPath = __DIR__ . "/../Languages/" . self::$currentLang . "/$location.php";
                $defaultPath = __DIR__ . "/../Languages/cs/$location.php";
                if (file_exists($targetPath) || file_exists($defaultPath)) {
                    $data = [];
                    if (file_exists($defaultPath)) {
                        $csData = include $defaultPath;
                        if (is_array($csData)) $data = $csData;
                    }
                    if (self::$currentLang !== 'cs' && file_exists($targetPath)) {
                        $transData = include $targetPath;
                        if (is_array($transData)) $data = array_merge($data, $transData);
                    }
                    self::$translations = $data;
                    return true;
                }
            }
            return false;
        }

        public static function get($key) {
            return self::$translations[$key] ?? "[$key]";
        }

        public static function getSlug($internalPath, $lang) {
            return self::$slugMap[$internalPath][$lang] ?? $internalPath;
        }

        public static function getAnchor($internalAnchor, $lang) {
            return self::$anchorMap[$internalAnchor][$lang] ?? $internalAnchor;
        }
    }
}

namespace {
    use Modules\LanguageMutations\Core\Language;

    function __($key) {
        return Language::get($key);
    }

    // Hlavní funkce pro generování odkazů v šablonách
    function url($path) {
        $lang = defined('LANG') ? LANG : 'cs';

        // Rozdělení na cestu a kotvu (např. 'sypke#kontakt')
        $parts = explode('#', $path);
        $basePath = $parts[0] ;
        $anchor = isset($parts[1]) ? '#' . $parts[1] : '';

        if ($basePath === '' && $anchor !== '') {
            $basePath = defined('CURRENT_PAGE') ? CURRENT_PAGE : 'home';
        } elseif ($basePath === '') {
            $basePath = 'home';
        }
        // Překlad základu a kotvy
        $translatedBase = ($lang === 'cs') ? $basePath : Language::getSlug($basePath, $lang);
        $translatedAnchor = ($lang === 'cs') ? $anchor : Language::getAnchor($anchor, $lang);

        if ($translatedBase === 'home') $translatedBase = '';

        // Pokud je čeština, nevracíme prefix /cs/
        if ($lang === 'cs') {
            return "/" . ltrim($translatedBase . $translatedAnchor, '/');
        }

        return "/$lang/" . ltrim($translatedBase . $translatedAnchor, '/');
    }
    function urlLang($lang) {
        return Language::getSwitchUrl($lang);
    }
}