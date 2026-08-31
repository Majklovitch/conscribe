<?php

namespace App\Services\Sitemap;

/**
 * One <url> entry.
 *
 * A provider returns these when it has per-row metadata to offer - an article's
 * updated_at as <lastmod>, for instance. When it only knows the parameter values
 * it returns plain arrays instead and the route's own settings are used.
 *
 * $loc is a path relative to the site root ('clanek/hello'); the absolute URL is
 * built later, so a provider does not have to know the domain.
 */
final readonly class SitemapUrl {
    public function __construct(
        public string $loc,
        public ?string $lastmod = null,
        public ?string $changefreq = null,
        public ?float $priority = null,
    ) {}
}
