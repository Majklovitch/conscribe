<?php

namespace App\Services;

use App\Core\Config;
use App\Services\Media\ImageCache;
use App\Services\Media\ProcessorFactory;

/**
 * Facade over ImageCache.
 *
 * The project has no DI container, so the instance is held statically and built
 * lazily from the configuration - just like Connection::get() or Config.
 * All the logic lives in App\Services\Media\ImageCache; once a container is
 * added, register ImageCache and drop this class.
 */
final class MediaService
{
    /** Defaults, so the service works even without a 'media' key in the configuration. */
    private const DEFAULTS = [
        'driver'        => 'auto',
        'source_root'   => null,
        'cache_path'    => 'media/cache',
        'quality'       => 82,
        'format'        => 'webp',
        'max_dimension' => 4000,
        'presets'       => [],
    ];

    private static ?ImageCache $cache = null;
    private static bool $resolved = false;

    /**
     * Returns null when neither Imagick nor GD is present on the server - the
     * caller then degrades to the original image instead of a fatal error
     * during rendering.
     */
    public static function cache(): ?ImageCache
    {
        if (self::$resolved) {
            return self::$cache;
        }

        self::$resolved = true;

        $config = self::config();

        if (!ProcessorFactory::hasDriver((string) $config['driver'])) {
            return self::$cache = null;
        }

        $publicRoot = self::publicRoot();
        $sourceRoot = $config['source_root'] ? (string) $config['source_root'] : $publicRoot;
        $cachePath = trim((string) $config['cache_path'], '/');

        self::$cache = new ImageCache(
            $sourceRoot,
            $publicRoot . '/' . $cachePath,
            '/' . $cachePath,
            ProcessorFactory::make((string) $config['driver']),
            (int) $config['quality'],
            (string) $config['format'],
            (int) $config['max_dimension']
        );

        return self::$cache;
    }

    /**
     * URL of the cached variant, or null when it cannot be produced.
     *
     * @param array{format?: string} $options
     */
    public static function url(string $path, int $width, int $height, array $options = []): ?string
    {
        return self::cache()?->get($path, $width, $height, $options);
    }

    /**
     * A named size from the configuration, e.g. 'thumb' => [500, 500].
     *
     * @return array{0:int,1:int}|null
     */
    public static function preset(string $name): ?array
    {
        $presets = self::config()['presets'];

        if (!is_array($presets) || !isset($presets[$name]) || !is_array($presets[$name])) {
            return null;
        }

        $size = array_values($presets[$name]);
        if (count($size) < 2) {
            return null;
        }

        return [(int) $size[0], (int) $size[1]];
    }

    /**
     * Drops the statically held instance. Meant for tests, and for the case
     * where the configuration is overwritten at runtime via Config::set().
     */
    public static function reset(): void
    {
        self::$cache = null;
        self::$resolved = false;
    }

    /**
     * @return array<string, mixed>
     */
    private static function config(): array
    {
        $media = Config::get('media', []);

        return array_merge(self::DEFAULTS, is_array($media) ? $media : []);
    }

    private static function publicRoot(): string
    {
        return dirname(__DIR__, 2) . '/public';
    }
}
