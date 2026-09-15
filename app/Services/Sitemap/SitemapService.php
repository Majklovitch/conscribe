<?php

namespace App\Services\Sitemap;

use App\Core\Config;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\Router;
use App\Core\Logging\LazyLogger;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Builds sitemap.xml from the route table.
 *
 * The routes are the only place that actually knows which pages exist, so the
 * sitemap is derived from them instead of being kept by hand. The service is
 * invokable and takes the Router as a constructor argument, so it is registered
 * as a route directly:
 *
 *     $router->get('sitemap.xml', new SitemapService($router), ['sitemap' => false]);
 *
 * Which pages count as public is decided by the route files (app/Config/routes.php):
 * everything in the group carrying sitemap defaults is listed, everything in the
 * group marked ['sitemap' => false] is not.
 */
class SitemapService {
    use LazyLogger;

    /** The sitemap protocol allows no more than this many URLs in one file. */
    private const int MAX_URLS = 50000;

    private const array CHANGEFREQ = ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'];

    private Router $router;

    public function __construct(Router $router, ?LoggerInterface $logger = null) {
        $this->router = $router;
        $this->logger = $logger;
    }

    public function __invoke(Request $request): Response {
        $xml = $this->render($this->collect(), $this->baseUrl($request));

        return new Response($xml, 200, [
            'Content-Type'  => 'application/xml; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * @return SitemapUrl[]
     */
    protected function collect(): array {
        $urls = [];

        foreach ($this->router->sitemapRoutes() as $route) {
            $options = is_array($route['sitemap']) ? $route['sitemap'] : [];

            foreach ($this->expand($route['url'], $options) as $url) {
                $urls[] = $url;

                if (count($urls) >= self::MAX_URLS) {
                    $this->logger()->warning('Sitemap reached the limit of {max} URLs; the rest is omitted.', [
                        'max' => self::MAX_URLS,
                    ]);

                    return $urls;
                }
            }
        }

        return $urls;
    }

    /**
     * One route template into zero or more concrete URLs.
     *
     * @return SitemapUrl[]
     */
    protected function expand(string $template, array $options): array {
        $default = new SitemapUrl(
            $template,
            $this->stringOrNull($options['lastmod'] ?? null),
            $this->changefreq($options['changefreq'] ?? null),
            $this->priority($options['priority'] ?? null),
        );

        if (!str_contains($template, '{')) {
            return [$default];
        }

        // A parameterised route cannot be enumerated from the route table alone -
        // only the application knows which slugs exist. Without a provider it is
        // skipped, rather than emitted with a literal '{slug}' in the URL.
        $provider = $options['provider'] ?? null;
        if (!is_callable($provider)) {
            return [];
        }

        $urls = [];

        try {
            $rows = $provider();

            if (!is_iterable($rows)) {
                return [];
            }

            // The loop belongs inside the try: a provider may hand back a
            // generator, and then the query runs here, on iteration, not in the
            // call above - a failure would otherwise escape past the catch.
            foreach ($rows as $row) {
                if ($row instanceof SitemapUrl) {
                    $urls[] = $row;
                    continue;
                }

                if (!is_array($row)) {
                    continue;
                }

                $loc = $this->substitute($template, $row);
                if ($loc === null) {
                    continue;
                }

                $urls[] = new SitemapUrl($loc, $default->lastmod, $default->changefreq, $default->priority);
            }
        } catch (Throwable $e) {
            // One unreachable repository must not take the whole sitemap down.
            // Whatever the provider managed to yield first is kept.
            $this->logger()->error('Sitemap provider for route {route} failed: {message}', [
                'route'     => $template,
                'message'   => $e->getMessage(),
                'exception' => $e,
            ]);
        }

        return $urls;
    }

    /**
     * 'clanek/{slug}' + ['slug' => 'hello'] => 'clanek/hello'.
     * Null when the row does not supply every parameter the route needs.
     */
    protected function substitute(string $template, array $params): ?string {
        $missing = false;

        $loc = preg_replace_callback(
            '/\{([a-zA-Z0-9_]+)\}/',
            static function (array $match) use ($params, &$missing): string {
                $value = $params[$match[1]] ?? null;

                if (!is_scalar($value) || (string) $value === '') {
                    $missing = true;

                    return '';
                }

                return rawurlencode((string) $value);
            },
            $template,
        );

        return $missing ? null : $loc;
    }

    /**
     * Absolute site root, with exactly one trailing slash. Falls back to the
     * current request when conscribe.base_url is not configured.
     */
    protected function baseUrl(Request $request): string {
        $base = trim((string) Config::get('conscribe.base_url', ''));

        if ($base === '') {
            $scheme = Request::isSecure($request->getServerParams()) ? 'https' : 'http';
            $host = (string) ($request->getHeader('host', '') ?? '');
            $base = $host !== '' ? $scheme . '://' . $host : '/';
        }

        return rtrim($base, '/') . '/';
    }

    /**
     * @param SitemapUrl[] $urls
     */
    protected function render(array $urls, string $baseUrl): string {
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
        ];

        foreach ($urls as $url) {
            $lines[] = '  <url>';
            $lines[] = '    <loc>' . $this->escape($baseUrl . ltrim($url->loc, '/')) . '</loc>';

            if ($url->lastmod !== null) {
                $lines[] = '    <lastmod>' . $this->escape($url->lastmod) . '</lastmod>';
            }
            if ($url->changefreq !== null) {
                $lines[] = '    <changefreq>' . $url->changefreq . '</changefreq>';
            }
            if ($url->priority !== null) {
                $lines[] = '    <priority>' . number_format($url->priority, 1, '.', '') . '</priority>';
            }

            $lines[] = '  </url>';
        }

        $lines[] = '</urlset>';

        return implode("\n", $lines) . "\n";
    }

    /**
     * XML, not HTML - the global esc() helper escapes for HTML and would put
     * entities in the document that the sitemap schema does not define.
     */
    protected function escape(string $value): string {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    protected function changefreq(mixed $value): ?string {
        return is_string($value) && in_array($value, self::CHANGEFREQ, true) ? $value : null;
    }

    protected function priority(mixed $value): ?float {
        if (!is_numeric($value)) {
            return null;
        }

        return max(0.0, min(1.0, (float) $value));
    }

    protected function stringOrNull(mixed $value): ?string {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
