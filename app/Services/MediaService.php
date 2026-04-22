<?php

namespace App\Services;

class MediaService
{
    private string $storageRoot;
    private string $publicUrlBase;

    public function __construct(string $storageRoot, string $publicUrlBase = '/media')
    {
        $this->storageRoot = rtrim($storageRoot, '/');
        $this->publicUrlBase = rtrim($publicUrlBase, '/');
    }

    /**
     * Downloads media for SKU folder:
     * - IMAGE: converts to WEBP and creates 500x500 thumb (or reuses original when smaller)
     * - VIDEO/DOCUMENT: downloads as-is
     */
    public function downloadPhotosForSku(string $sku, array $mediaItems): array
    {
        $skuFolder = $this->normalizeSkuFolder($sku);
        $dir = $this->storageRoot . '/products/' . $skuFolder;

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return [[
                'id' => 0,
                'url' => '/img/thumb.jpg',
                'thumb_url' => '/img/thumb.jpg',
                'is_primary' => true,
                'type' => 'IMAGE',
            ]];
        }

        $result = [];

        foreach ($mediaItems as $item) {
            $type = strtoupper($item['type'] ?? 'IMAGE');
            if (!in_array($type, ['IMAGE', 'VIDEO', 'DOCUMENT'], true) || empty($item['url'])) {
                continue;
            }

            $mediaId = isset($item['id']) ? (string)$item['id'] : (string)crc32($item['url']);

            if ($type !== 'IMAGE') {
                $ext = $this->guessExtension((string)$item['url'], $type);
                $filename = $mediaId . '.' . $ext;
                $destPath = $dir . '/' . $filename;

                if (!file_exists($destPath)) {
                    $this->downloadFile((string)$item['url'], $destPath, $type);
                }

                if (file_exists($destPath)) {
                    $item['url'] = $this->publicUrlBase . '/products/' . $skuFolder . '/' . $filename;
                    $item['type'] = $type;
                    $result[] = $item;
                }
                continue;
            }

            $webpFilename = $mediaId . '.webp';
            $thumbFilename = $mediaId . '_thumb.webp';

            $webpPath = $dir . '/' . $webpFilename;
            $thumbPath = $dir . '/' . $thumbFilename;
            $useOriginalForThumb = false;

            if (!file_exists($webpPath) || !file_exists($thumbPath)) {
                $tmpPath = tempnam(sys_get_temp_dir(), 'media_alt_');
                if ($tmpPath && $this->downloadFile((string)$item['url'], $tmpPath, $type)) {
                    $this->convertAndCreateThumb($tmpPath, $webpPath, $thumbPath, $useOriginalForThumb);
                }

                if ($tmpPath && file_exists($tmpPath)) {
                    unlink($tmpPath);
                }
            }

            if (file_exists($webpPath) && ($useOriginalForThumb || file_exists($thumbPath))) {
                $item['url'] = $this->publicUrlBase . '/products/' . $skuFolder . '/' . $webpFilename;
                $item['thumb_url'] = $useOriginalForThumb
                    ? $item['url']
                    : $this->publicUrlBase . '/products/' . $skuFolder . '/' . $thumbFilename;
                $item['type'] = 'IMAGE';
                $result[] = $item;
            }
        }

        if (empty($result)) {
            return [[
                'id' => 0,
                'url' => '/img/thumb.jpg',
                'thumb_url' => '/img/thumb.jpg',
                'is_primary' => true,
                'type' => 'IMAGE',
            ]];
        }

        return $result;
    }

    public function deleteForSku(string $sku): void
    {
        $dir = $this->storageRoot . '/products/' . $this->normalizeSkuFolder($sku);
        if (!is_dir($dir)) {
            return;
        }

        foreach (glob($dir . '/*') as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        rmdir($dir);
    }

    private function normalizeSkuFolder(string $sku): string
    {
        $normalized = strtolower(trim($sku));
        $normalized = preg_replace('/[^a-z0-9-]+/', '-', $normalized);
        $normalized = trim($normalized, '-');

        if ($normalized === '') {
            $normalized = 'unknown';
        }

        if (!str_starts_with($normalized, 'sku-')) {
            $normalized = 'sku-' . $normalized;
        }

        return $normalized;
    }

    private function downloadFile(string $url, string $destination, string $type = 'IMAGE'): bool
    {
        $fp = @fopen($destination, 'wb');
        if (!$fp) {
            return false;
        }

        $timeout = match (strtoupper($type)) {
            'VIDEO' => 300,
            'DOCUMENT' => 120,
            default => 60,
        };

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FAILONERROR => true,
        ]);

        $ok = curl_exec($ch);
        curl_close($ch);
        fclose($fp);

        if (!$ok && file_exists($destination)) {
            unlink($destination);
        }

        return (bool)$ok;
    }

    private function guessExtension(string $url, string $type): string
    {
        $path = (string)parse_url($url, PHP_URL_PATH);
        $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));

        if ($ext !== '' && strlen($ext) <= 8) {
            return $ext;
        }

        return match (strtoupper($type)) {
            'VIDEO' => 'mp4',
            'DOCUMENT' => 'pdf',
            default => 'bin',
        };
    }

    private function convertAndCreateThumb(string $sourcePath, string $webpPath, string $thumbPath, bool &$useOriginalForThumb = false): bool
    {
        $useOriginalForThumb = false;

        if (!extension_loaded('imagick') || !class_exists('Imagick')) {
            return false;
        }

        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            return false;
        }

        $image = null;
        $thumb = null;
        $okWebp = false;
        $okThumb = false;

        try {
            $image = new \Imagick();
            $image->readImage($sourcePath);
            $image->autoOrient();
            $image->setImageFormat('webp');
            $image->setImageCompressionQuality(82);

            $okWebp = (bool)$image->writeImage($webpPath);

            $width = $image->getImageWidth();
            $height = $image->getImageHeight();

            if ($width < 500 || $height < 500) {
                $useOriginalForThumb = true;
                if (file_exists($thumbPath)) {
                    unlink($thumbPath);
                }
                return $okWebp;
            }

            $thumb = clone $image;
            $thumb->cropThumbnailImage(500, 500);
            $thumb->setImageFormat('webp');
            $thumb->setImageCompressionQuality(82);
            $okThumb = (bool)$thumb->writeImage($thumbPath);

            return $okWebp && $okThumb;
        } catch (\Throwable $e) {
            return false;
        } finally {
            if ($thumb instanceof \Imagick) {
                $thumb->clear();
                $thumb->destroy();
            }

            if ($image instanceof \Imagick) {
                $image->clear();
                $image->destroy();
            }

            $thumbRequired = !$useOriginalForThumb;
            if (!$okWebp || ($thumbRequired && !$okThumb)) {
                if (file_exists($webpPath)) {
                    unlink($webpPath);
                }
                if (file_exists($thumbPath)) {
                    unlink($thumbPath);
                }
            }
        }
    }
}

