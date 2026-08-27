<?php

namespace App\Services\Media;

/**
 * A single interface over a concrete graphics library (Imagick, GD, ...).
 *
 * An implementation holds one open image. The lifecycle is always
 * open() -> resizeCover() -> save() -> close(), and close() must be called
 * even on failure (typically in a finally block).
 */
interface ImageProcessor
{
    /**
     * Is the library this implementation uses available on this server?
     */
    public static function isAvailable(): bool;

    /**
     * Short driver identifier ('imagick', 'gd', ...).
     */
    public static function name(): string;

    /**
     * Opens the source file and returns its dimensions.
     *
     * @return array{0:int,1:int} [width, height]
     * @throws \RuntimeException when the file cannot be read
     */
    public function open(string $sourcePath): array;

    /**
     * Resizes and crops the image to exactly the given dimensions ("cover" mode).
     * The aspect ratio is preserved and the excess is cropped from the centre.
     */
    public function resizeCover(int $width, int $height): void;

    /**
     * Saves the result. The format is an extension without the dot
     * ('webp', 'jpg', 'png').
     */
    public function save(string $destinationPath, string $format, int $quality): bool;

    /**
     * Frees the memory. Must be safe to call repeatedly, and without open().
     */
    public function close(): void;
}
