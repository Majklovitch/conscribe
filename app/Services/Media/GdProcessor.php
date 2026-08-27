<?php

namespace App\Services\Media;

/**
 * Fallback driver for hosts without Imagick. Covers jpeg/png/gif/webp,
 * the formats that actually show up on the web.
 */
class GdProcessor implements ImageProcessor
{
    /** @var \GdImage|null */
    private $image = null;

    private int $width = 0;
    private int $height = 0;

    public static function isAvailable(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor');
    }

    public static function name(): string
    {
        return 'gd';
    }

    public function open(string $sourcePath): array
    {
        $this->close();

        $info = @getimagesize($sourcePath);
        if ($info === false) {
            throw new \RuntimeException('GD: unsupported or unreadable image: ' . $sourcePath);
        }

        $image = match ($info[2]) {
            IMAGETYPE_JPEG => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($sourcePath) : false,
            IMAGETYPE_PNG  => function_exists('imagecreatefrompng') ? @imagecreatefrompng($sourcePath) : false,
            IMAGETYPE_GIF  => function_exists('imagecreatefromgif') ? @imagecreatefromgif($sourcePath) : false,
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
            default => false,
        };

        if (!$image) {
            throw new \RuntimeException('GD: cannot decode image type for ' . $sourcePath);
        }

        // A JPEG with EXIF orientation is rotated by hand - unlike Imagick,
        // GD does not do it on its own.
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $image = $this->applyExifOrientation($image, $sourcePath);
        }

        $this->image = $image;
        $this->width = imagesx($image);
        $this->height = imagesy($image);

        return [$this->width, $this->height];
    }

    public function resizeCover(int $width, int $height): void
    {
        if (!$this->image) {
            throw new \RuntimeException('GD: resizeCover() called before open().');
        }

        // Scale by the longer required side, then crop the rest from the centre.
        $scale = max($width / $this->width, $height / $this->height);
        $cropWidth = min($this->width, (int) round($width / $scale));
        $cropHeight = min($this->height, (int) round($height / $scale));
        $cropX = (int) max(0, round(($this->width - $cropWidth) / 2));
        $cropY = (int) max(0, round(($this->height - $cropHeight) / 2));

        $canvas = imagecreatetruecolor($width, $height);
        if (!$canvas) {
            throw new \RuntimeException('GD: cannot allocate ' . $width . 'x' . $height . ' canvas.');
        }

        // Without this a transparent PNG/WEBP would end up on a black background.
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $width, $height, $transparent);
        imagealphablending($canvas, false);

        imagecopyresampled(
            $canvas,
            $this->image,
            0, 0,
            $cropX, $cropY,
            $width, $height,
            $cropWidth, $cropHeight
        );

        // Since PHP 8.0 GdImage cleans up after itself, and imagedestroy() is
        // deprecated as of 8.5 - dropping the reference is enough.
        $this->image = $canvas;
        $this->width = $width;
        $this->height = $height;
    }

    public function save(string $destinationPath, string $format, int $quality): bool
    {
        if (!$this->image) {
            return false;
        }

        imagesavealpha($this->image, true);

        return match ($format) {
            'webp' => function_exists('imagewebp') && imagewebp($this->image, $destinationPath, $quality),
            'jpg', 'jpeg' => function_exists('imagejpeg')
                && imagejpeg($this->flattenForJpeg(), $destinationPath, $quality),
            // PNG takes 0-9 (0 = no compression), converted here from 0-100.
            'png' => function_exists('imagepng')
                && imagepng($this->image, $destinationPath, (int) round((100 - $quality) / 11.2)),
            'gif' => function_exists('imagegif') && imagegif($this->image, $destinationPath),
            default => false,
        };
    }

    public function close(): void
    {
        $this->image = null;
        $this->width = 0;
        $this->height = 0;
    }

    /**
     * JPEG has no alpha channel - transparency is backed with white.
     *
     * @return \GdImage
     */
    private function flattenForJpeg()
    {
        $flat = imagecreatetruecolor($this->width, $this->height);
        $white = imagecolorallocate($flat, 255, 255, 255);
        imagefilledrectangle($flat, 0, 0, $this->width, $this->height, $white);
        imagealphablending($flat, true);
        imagecopy($flat, $this->image, 0, 0, 0, 0, $this->width, $this->height);

        $this->image = $flat;

        return $flat;
    }

    /**
     * @param \GdImage $image
     * @return \GdImage
     */
    private function applyExifOrientation($image, string $sourcePath)
    {
        $exif = @exif_read_data($sourcePath);
        $orientation = isset($exif['Orientation']) ? (int) $exif['Orientation'] : 1;

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => null,
        };

        if (!$rotated) {
            return $image;
        }

        return $rotated;
    }
}
