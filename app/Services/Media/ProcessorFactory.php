<?php

namespace App\Services\Media;

/**
 * Picks an available graphics driver. Imagick takes precedence, GD is the fallback.
 */
class ProcessorFactory
{
    /** @var array<string, class-string<ImageProcessor>> */
    private const DRIVERS = [
        'imagick' => ImagickProcessor::class,
        'gd'      => GdProcessor::class,
    ];

    /**
     * @param string $prefer 'auto' | 'imagick' | 'gd'
     * @throws \RuntimeException when no driver is available
     */
    public static function make(string $prefer = 'auto'): ImageProcessor
    {
        $class = self::resolve($prefer);

        if ($class === null) {
            throw new \RuntimeException(
                'No image driver available. Install ext-imagick (preferred) or ext-gd.'
            );
        }

        return new $class();
    }

    /**
     * Is at least one driver available? Lets the caller degrade gracefully
     * without catching an exception.
     */
    public static function hasDriver(string $prefer = 'auto'): bool
    {
        return self::resolve($prefer) !== null;
    }

    /**
     * @return class-string<ImageProcessor>|null
     */
    private static function resolve(string $prefer): ?string
    {
        $prefer = strtolower(trim($prefer));

        // An explicitly requested driver is never substituted - otherwise the
        // configuration would appear to hold while a different output was
        // silently produced.
        if (isset(self::DRIVERS[$prefer])) {
            $class = self::DRIVERS[$prefer];

            return $class::isAvailable() ? $class : null;
        }

        foreach (self::DRIVERS as $class) {
            if ($class::isAvailable()) {
                return $class;
            }
        }

        return null;
    }
}
