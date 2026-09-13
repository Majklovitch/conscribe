<?php

declare(strict_types=1);

namespace Tests\Core\Media;

use App\Services\Media\ImageCache;
use App\Services\Media\ImageProcessor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Writes a placeholder instead of an image, and counts how often it was asked to. */
final class CountingProcessor implements ImageProcessor {
    public int $opened = 0;

    public static function isAvailable(): bool {
        return true;
    }

    public static function name(): string {
        return 'counting';
    }

    public function open(string $sourcePath): array {
        $this->opened++;

        return [1, 1];
    }

    public function resizeCover(int $width, int $height): void {
    }

    public function save(string $destinationPath, string $format, int $quality): bool {
        return file_put_contents($destinationPath, 'variant') !== false;
    }

    public function close(): void {
    }
}

#[CoversClass(ImageCache::class)]
final class ImageCacheTest extends TestCase {
    /** A valid 1x1 PNG, so the source passes getimagesize(). */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $root;
    private CountingProcessor $processor;
    private ImageCache $cache;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/conscribe-images-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src/img', 0o775, true);
        file_put_contents($this->root . '/src/img/photo.png', base64_decode(self::PNG));

        $this->processor = new CountingProcessor();
        $this->cache = new ImageCache($this->root . '/src', $this->root . '/cache', 'media/cache', $this->processor);
    }

    protected function tearDown(): void {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }

        @rmdir($this->root);
    }

    #[TestDox('the first request generates the variant')]
    public function testFirstCallGenerates(): void {
        self::assertSame('/media/cache/10x10/img/photo.png.webp', $this->cache->get('img/photo.png', 10, 10));
        self::assertSame(1, $this->processor->opened);
    }

    #[TestDox('a fresh variant is served without touching the graphics library again')]
    public function testFreshVariantIsServedFromTheFastPath(): void {
        $this->cache->get('img/photo.png', 10, 10);
        $url = $this->cache->get('img/photo.png', 10, 10);

        self::assertSame('/media/cache/10x10/img/photo.png.webp', $url);
        self::assertSame(1, $this->processor->opened);
    }

    #[TestDox('a source newer than its variant is regenerated')]
    public function testStaleVariantIsRegenerated(): void {
        $this->cache->get('img/photo.png', 10, 10);

        touch($this->root . '/src/img/photo.png', time() + 60);
        clearstatcache();

        $this->cache->get('img/photo.png', 10, 10);

        self::assertSame(2, $this->processor->opened);
    }

    #[TestDox('a variant whose source is gone is not served')]
    public function testDeletedSourceIsNotServed(): void {
        // The fast path needs the source's mtime; without a source it falls
        // through to validation, which refuses - so a removed image does not
        // keep appearing from the cache.
        $this->cache->get('img/photo.png', 10, 10);
        unlink($this->root . '/src/img/photo.png');
        clearstatcache();

        self::assertNull($this->cache->get('img/photo.png', 10, 10));
    }

    #[TestDox('traversal and missing files are still refused')]
    public function testInvalidPathsAreRefused(): void {
        self::assertNull($this->cache->get('../outside.png', 10, 10));
        self::assertNull($this->cache->get('img/missing.png', 10, 10));
        self::assertSame(0, $this->processor->opened);
    }
}
