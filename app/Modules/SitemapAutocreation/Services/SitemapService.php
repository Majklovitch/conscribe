<?php

namespace Modules\SitemapAutocreation\Services;

class SitemapService
{
    private string $siteUrl = 'https://www.mvcprojekt.cz';
    private string $publicRoot;

    public function __construct()
    {
        $this->publicRoot = dirname(__DIR__, 4) . '/public';
    }

    public function generate(): void
    {
        $urls = [];
        $pages = [
            'test',
        ];

        $urls[] = $this->buildUrl('/', null, 'daily', '1.0');

        foreach ($pages as $staticPath) {
            $urls[] = $this->buildUrl('/' . $staticPath, null, 'monthly', '0.7');
        }
        /*
        foreach ($this->categoryModel->getSitemapCategories() as $category) {
            $urls[] = $this->buildUrl(
                '/category/' . $category['slug'],
                $category['lastmod'] ?? null,
                'daily',
                '0.8'
            );
        }

        foreach ($this->productModel->getSitemapProducts() as $product) {
            $urls[] = $this->buildUrl(
                '/product/' . strtolower((string)$product['sku']),
                $product['updated_at'] ?? null,
                'daily',
                '0.6'
            );
        }
        */

        $xml = $this->renderXml($urls);
        file_put_contents($this->publicRoot . '/sitemap.xml', $xml);
    }

    private function buildUrl(string $path, ?string $lastmod, string $changefreq, string $priority): array
    {
        return [
            'loc' => rtrim($this->siteUrl, '/') . $path,
            'lastmod' => $this->formatDate($lastmod),
            'changefreq' => $changefreq,
            'priority' => $priority,
        ];
    }

    private function formatDate(?string $date): ?string
    {
        if ($date === null || trim($date) === '') {
            return null;
        }

        try {
            return (new \DateTime($date))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function renderXml(array $urls): string
    {
        $lines = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];

        foreach ($urls as $url) {
            $lines[] = '  <url>';
            $lines[] = '    <loc>' . htmlspecialchars($url['loc'], ENT_XML1) . '</loc>';
            if (!empty($url['lastmod'])) {
                $lines[] = '    <lastmod>' . $url['lastmod'] . '</lastmod>';
            }
            $lines[] = '    <changefreq>' . $url['changefreq'] . '</changefreq>';
            $lines[] = '    <priority>' . $url['priority'] . '</priority>';
            $lines[] = '  </url>';
        }

        $lines[] = '</urlset>';
        $lines[] = '';

        return implode("\n", $lines);
    }
}
