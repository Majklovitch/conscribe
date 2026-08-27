<?php

namespace App\Services\Media;

/**
 * The preferred driver. Imagick handles more formats than GD and deals better
 * with colour profiles and EXIF orientation.
 */
class ImagickProcessor implements ImageProcessor
{
    private ?\Imagick $image = null;

    public static function isAvailable(): bool
    {
        return extension_loaded('imagick') && class_exists('Imagick');
    }

    public static function name(): string
    {
        return 'imagick';
    }

    public function open(string $sourcePath): array
    {
        $this->close();

        $image = new \Imagick();
        $image->readImage($sourcePath);

        // Photos from phones are often rotated through EXIF only; without
        // autoOrient() the crop would be computed from the wrong side.
        $image->autoOrient();

        // Animated GIFs/WEBPs are flattened to a single frame, otherwise the
        // crop would apply to the first layer only.
        if ($image->getNumberImages() > 1) {
            $image = $image->coalesceImages()->flattenImages();
        }

        $this->image = $image;

        return [$image->getImageWidth(), $image->getImageHeight()];
    }

    public function resizeCover(int $width, int $height): void
    {
        if (!$this->image instanceof \Imagick) {
            throw new \RuntimeException('Imagick: resizeCover() called before open().');
        }

        // cropThumbnailImage() is exactly "cover" - it scales to cover, then
        // crops from the centre.
        $this->image->cropThumbnailImage($width, $height);
        $this->image->setImagePage(0, 0, 0, 0);
    }

    public function save(string $destinationPath, string $format, int $quality): bool
    {
        if (!$this->image instanceof \Imagick) {
            return false;
        }

        $this->image->setImageFormat($format === 'jpg' ? 'jpeg' : $format);
        $this->image->setImageCompressionQuality($quality);

        // A public copy has no reason to carry GPS coordinates and other EXIF data.
        $this->image->stripImage();

        return (bool) $this->image->writeImage($destinationPath);
    }

    public function close(): void
    {
        if ($this->image instanceof \Imagick) {
            $this->image->clear();
            $this->image->destroy();
        }

        $this->image = null;
    }
}
