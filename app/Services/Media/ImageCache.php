<?php

namespace App\Services\Media;

/**
 * Generates and caches resized image variants.
 *
 * The class knows nothing about this application - it receives the path roots
 * and a driver, so it can be reused unchanged in another project (e.g. source
 * in a private storage/, cache in public/).
 *
 * Cache layout: <cacheRoot>/<width>x<height>/<source subdirectory>/<file>.<format>
 *
 *   public/media/cache/1200x500/img/photo.jpg.webp
 *   public/media/cache/300x300/img/flags/czech-republic.png.webp
 *
 * The paths are readable, and deleting a single directory throws away one whole
 * size when the layout changes.
 */
class ImageCache
{
    /** Formats we are able to save into. */
    private const ALLOWED_FORMATS = ['webp', 'jpg', 'jpeg', 'png', 'gif'];

    private string $sourceRoot;
    private string $cacheRoot;
    private string $cacheUrlBase;
    private ImageProcessor $processor;
    private int $quality;
    private string $format;
    private int $maxDimension;

    public function __construct(
        string $sourceRoot,
        string $cacheRoot,
        string $cacheUrlBase,
        ImageProcessor $processor,
        int $quality = 82,
        string $format = 'webp',
        int $maxDimension = 4000
    ) {
        $this->sourceRoot = rtrim(str_replace('\\', '/', $sourceRoot), '/');
        $this->cacheRoot = rtrim(str_replace('\\', '/', $cacheRoot), '/');
        $this->cacheUrlBase = '/' . trim($cacheUrlBase, '/');
        $this->processor = $processor;
        $this->quality = max(1, min(100, $quality));
        $this->format = $this->normalizeFormat($format);
        $this->maxDimension = max(1, $maxDimension);
    }

    /**
     * Returns the URL of the cached variant, generating it first if needed.
     * Returns null when the source does not exist or the conversion fails - the
     * caller can then fall back to the original instead of a broken <img>.
     *
     * @param array{format?: string} $options
     */
    public function get(string $relativePath, int $width, int $height, array $options = []): ?string
    {
        $relative = $this->normalizeRelativePath($relativePath);
        if ($relative === null) {
            return null;
        }

        $source = $this->resolveSource($relative);
        if ($source === null) {
            return null;
        }

        $width = $this->clampDimension($width);
        $height = $this->clampDimension($height);
        $format = isset($options['format']) ? $this->normalizeFormat((string) $options['format']) : $this->format;

        $cacheRelative = $this->cacheRelativePath($relative, $width, $height, $format);
        $cachePath = $this->cacheRoot . '/' . $cacheRelative;

        $sourceTime = (int) filemtime($source);

        // Fast path: a finished, fresh file - the graphics library never starts up.
        if (!$this->isFresh($cachePath, $sourceTime)
            && !$this->generate($source, $cachePath, $width, $height, $format)) {
            return null;
        }

        return $this->cacheUrlBase . '/' . $cacheRelative;
    }

    /**
     * Builds the value for a srcset attribute from several widths.
     * The height is derived from the aspect ratio (width / height).
     *
     * @param int[] $widths
     */
    public function srcset(string $relativePath, array $widths, float $ratio): string
    {
        $entries = [];

        foreach ($widths as $width) {
            $width = (int) $width;
            if ($width < 1) {
                continue;
            }

            $url = $this->get($relativePath, $width, (int) round($width / max($ratio, 0.01)));
            if ($url !== null) {
                $entries[] = $url . ' ' . $width . 'w';
            }
        }

        return implode(', ', $entries);
    }

    /**
     * Deletes the whole cache, or just one size.
     * Useful after changing format or quality in the configuration - neither is
     * reflected in the directory name, so old files never invalidate themselves.
     */
    public function purge(?int $width = null, ?int $height = null): void
    {
        $target = $this->cacheRoot;

        if ($width !== null && $height !== null) {
            $target .= '/' . $this->clampDimension($width) . 'x' . $this->clampDimension($height);
        }

        $this->removeDirectory($target, $target === $this->cacheRoot);
    }

    public function getCacheRoot(): string
    {
        return $this->cacheRoot;
    }

    public function getSourceRoot(): string
    {
        return $this->sourceRoot;
    }

    /**
     * Produces the variant through a temporary file and moves it into place
     * atomically, so two concurrent requests never serve a half-written file.
     */
    private function generate(string $source, string $cachePath, int $width, int $height, string $format): bool
    {
        $directory = dirname($cachePath);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            return false;
        }

        $temp = $directory . '/.tmp_' . bin2hex(random_bytes(6)) . '.' . $format;

        try {
            $this->processor->open($source);
            $this->processor->resizeCover($width, $height);

            if (!$this->processor->save($temp, $format, $this->quality) || !is_file($temp)) {
                return false;
            }

            // rename() over an existing file is atomic on POSIX; on Windows it
            // fails, so the old file is removed there first.
            if (DIRECTORY_SEPARATOR === '\\' && is_file($cachePath)) {
                @unlink($cachePath);
            }

            if (!@rename($temp, $cachePath)) {
                return false;
            }

            @chmod($cachePath, 0644);

            return true;
        } catch (\Throwable $e) {
            return false;
        } finally {
            $this->processor->close();

            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    private function isFresh(string $cachePath, int $sourceTime): bool
    {
        if (!is_file($cachePath)) {
            return false;
        }

        $cacheTime = @filemtime($cachePath);

        return $cacheTime !== false && $cacheTime >= $sourceTime;
    }

    /**
     * Verifies the path stays inside sourceRoot and points at a readable image.
     */
    private function resolveSource(string $relative): ?string
    {
        $candidate = realpath($this->sourceRoot . '/' . $relative);
        if ($candidate === false) {
            return null;
        }

        $candidate = str_replace('\\', '/', $candidate);
        $root = realpath($this->sourceRoot);
        $root = $root === false ? $this->sourceRoot : str_replace('\\', '/', $root);

        // A symlink or ".." pointing outside the root is rejected.
        if ($candidate !== $root && !str_starts_with($candidate, $root . '/')) {
            return null;
        }

        if (!is_file($candidate) || !is_readable($candidate) || @getimagesize($candidate) === false) {
            return null;
        }

        return $candidate;
    }

    private function normalizeRelativePath(string $path): ?string
    {
        $path = str_replace('\\', '/', trim($path));

        // A cache buster or query left over from asset() has no place in the path.
        $queryPosition = strpos($path, '?');
        if ($queryPosition !== false) {
            $path = substr($path, 0, $queryPosition);
        }

        $path = ltrim($path, '/');

        if ($path === '' || str_contains($path, '..') || str_contains($path, "\0")) {
            return null;
        }

        return $path;
    }

    /**
     * <width>x<height>/<subdirectory>/<name>.<format>
     *
     * The original extension stays in the name, otherwise photo.jpg and
     * photo.png would overwrite each other in the same directory.
     */
    private function cacheRelativePath(string $relative, int $width, int $height, string $format): string
    {
        $segments = [];
        foreach (explode('/', $relative) as $segment) {
            $clean = preg_replace('/[^A-Za-z0-9._-]+/', '-', $segment);
            $clean = trim((string) $clean, '.-');

            if ($clean !== '') {
                $segments[] = $clean;
            }
        }

        $filename = array_pop($segments);
        if ($filename === null) {
            $filename = 'image';
        }

        if (strtolower((string) pathinfo($filename, PATHINFO_EXTENSION)) !== $format) {
            $filename .= '.' . $format;
        }

        $directory = $segments === [] ? '' : implode('/', $segments) . '/';

        return $width . 'x' . $height . '/' . $directory . $filename;
    }

    private function normalizeFormat(string $format): string
    {
        $format = strtolower(ltrim(trim($format), '.'));

        return in_array($format, self::ALLOWED_FORMATS, true) ? $format : 'webp';
    }

    private function clampDimension(int $value): int
    {
        return max(1, min($this->maxDimension, $value));
    }

    private function removeDirectory(string $directory, bool $keepRoot): void
    {
        if (!is_dir($directory)) {
            return;
        }

        // Safeguard against a typo in the configuration - only delete inside the cache.
        $resolved = realpath($directory);
        $root = realpath($this->cacheRoot);
        if ($resolved === false || $root === false) {
            return;
        }

        $resolved = str_replace('\\', '/', $resolved);
        $root = str_replace('\\', '/', $root);
        if ($resolved !== $root && !str_starts_with($resolved, $root . '/')) {
            return;
        }

        foreach (scandir($resolved) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.htaccess') {
                continue;
            }

            $path = $resolved . '/' . $entry;

            if (is_dir($path)) {
                $this->removeDirectory($path, false);
            } else {
                @unlink($path);
            }
        }

        if (!$keepRoot) {
            @rmdir($resolved);
        }
    }
}
