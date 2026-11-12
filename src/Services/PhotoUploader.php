<?php
declare(strict_types=1);

namespace BusherianBiasharas\Services;

final class PhotoUploader
{
    public const MAX_PHOTOS = 5;
    public const MAX_DIMENSION = 1200;
    public const MAX_FILE_BYTES = 8388608; // 8 MB raw input ceiling before resize

    private const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /**
     * Decode existing photos_json into a clean array of relative URLs.
     */
    public static function decodePhotosJson(?string $photosJson): array
    {
        if ($photosJson === null || trim($photosJson) === '') {
            return [];
        }
        $arr = json_decode($photosJson, true);
        if (!is_array($arr)) {
            return [];
        }
        $clean = [];
        foreach ($arr as $item) {
            if (is_string($item) && str_starts_with($item, '/uploads/businesses/')) {
                $clean[] = $item;
            }
        }
        return array_slice(array_values(array_unique($clean)), 0, self::MAX_PHOTOS);
    }

    /**
     * Process existing photos (minus any marked for removal) + newly uploaded files or client-resized data URLs.
     * Re-encodes every image through GD to strip EXIF GPS/camera metadata (Kenya DPA 2019 privacy protection)
     * and enforce a strict max of 5 photos per business.
     */
    public static function processBusinessPhotos(
        ?string $existingPhotosJson,
        array $removeUrls = [],
        ?array $uploadedFiles = null,
        ?string $clientDataUrlsJson = null
    ): string {
        $current = self::decodePhotosJson($existingPhotosJson);
        if (!empty($removeUrls)) {
            $removeSet = array_flip(array_map('strval', $removeUrls));
            $current = array_values(array_filter($current, static fn(string $u): bool => !isset($removeSet[$u])));
        }

        $newBinaryImages = [];

        // 1. Check client-resized base64 data URLs first (if provided by JS compressor)
        if ($clientDataUrlsJson !== null && trim($clientDataUrlsJson) !== '') {
            $decodedList = json_decode($clientDataUrlsJson, true);
            if (is_array($decodedList)) {
                foreach ($decodedList as $dataUrl) {
                    if (!is_string($dataUrl) || !preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) {
                        continue;
                    }
                    $rawBytes = base64_decode($m[2], true);
                    if (is_string($rawBytes) && strlen($rawBytes) > 0 && strlen($rawBytes) <= self::MAX_FILE_BYTES) {
                        $newBinaryImages[] = $rawBytes;
                    }
                }
            }
        }

        // 2. Also support standard multipart $_FILES['business_photos'] when JS data URLs aren't used
        if (empty($newBinaryImages) && is_array($uploadedFiles) && isset($uploadedFiles['tmp_name'])) {
            $tmpNames = is_array($uploadedFiles['tmp_name']) ? $uploadedFiles['tmp_name'] : [$uploadedFiles['tmp_name']];
            $errors   = is_array($uploadedFiles['error']) ? $uploadedFiles['error'] : [$uploadedFiles['error']];
            $sizes    = is_array($uploadedFiles['size']) ? $uploadedFiles['size'] : [$uploadedFiles['size']];

            foreach ($tmpNames as $idx => $tmpPath) {
                $err = (int)($errors[$idx] ?? UPLOAD_ERR_NO_FILE);
                $sz  = (int)($sizes[$idx] ?? 0);
                if ($err !== UPLOAD_ERR_OK || !is_string($tmpPath) || $tmpPath === '' || $sz <= 0 || $sz > self::MAX_FILE_BYTES) {
                    continue;
                }
                if (is_uploaded_file($tmpPath) || is_file($tmpPath)) {
                    $bytes = @file_get_contents($tmpPath);
                    if (is_string($bytes) && $bytes !== '') {
                        $newBinaryImages[] = $bytes;
                    }
                }
            }
        }

        foreach ($newBinaryImages as $rawBytes) {
            if (count($current) >= self::MAX_PHOTOS) {
                break;
            }
            $savedRelUrl = self::sanitizeAndSaveImageBytes($rawBytes);
            if ($savedRelUrl !== null) {
                $current[] = $savedRelUrl;
            }
        }

        return (string)json_encode(array_slice($current, 0, self::MAX_PHOTOS), JSON_UNESCAPED_SLASHES);
    }

    /**
     * Validate image binary via getimagesizefromstring, re-encode via GD (stripping EXIF metadata),
     * resize to <= 1200px, and save under public/uploads/businesses/ (or html/uploads/businesses/ on production).
     */
    public static function sanitizeAndSaveImageBytes(string $rawBytes): ?string
    {
        if ($rawBytes === '' || strlen($rawBytes) > self::MAX_FILE_BYTES) {
            return null;
        }
        $info = @getimagesizefromstring($rawBytes);
        if (!is_array($info) || empty($info['mime']) || !in_array($info['mime'], self::ALLOWED_MIMES, true)) {
            return null;
        }
        $width = (int)($info[0] ?? 0);
        $height = (int)($info[1] ?? 0);
        if ($width < 10 || $height < 10 || $width > 10000 || $height > 10000) {
            return null;
        }

        $webRoot = self::resolvePublicWebRoot();
        $targetDir = $webRoot . '/uploads/businesses';
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0775, true);
        }

        $fileId = 'bbiz_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(5));

        if (function_exists('imagecreatefromstring')) {
            $srcImg = @imagecreatefromstring($rawBytes);
            if ($srcImg === false) {
                return null;
            }

            $newW = $width;
            $newH = $height;
            if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
                $ratio = min(self::MAX_DIMENSION / $width, self::MAX_DIMENSION / $height);
                $newW = max(1, (int)round($width * $ratio));
                $newH = max(1, (int)round($height * $ratio));
            }

            $dstImg = imagecreatetruecolor($newW, $newH);
            imagealphablending($dstImg, false);
            imagesavealpha($dstImg, true);
            $white = imagecolorallocate($dstImg, 255, 255, 255);
            imagefilledrectangle($dstImg, 0, 0, $newW, $newH, $white);
            imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $newW, $newH, $width, $height);

            if (function_exists('imagewebp')) {
                $filename = $fileId . '.webp';
                $fullPath = $targetDir . '/' . $filename;
                $ok = @imagewebp($dstImg, $fullPath, 82);
            } else {
                $filename = $fileId . '.jpg';
                $fullPath = $targetDir . '/' . $filename;
                $ok = @imagejpeg($dstImg, $fullPath, 84);
            }

            imagedestroy($srcImg);
            imagedestroy($dstImg);

            if ($ok && is_file($fullPath)) {
                return '/uploads/businesses/' . $filename;
            }
        }

        return null;
    }

    private static function resolvePublicWebRoot(): string
    {
        $explicitRoot = \BusherianBiasharas\Config\Database::env('BBIZ_PUBLIC_WEB_ROOT', '');
        if ($explicitRoot !== '' && is_dir($explicitRoot)) {
            return rtrim($explicitRoot, '/');
        }
        $projectRoot = dirname(__DIR__, 2);
        if (is_dir($projectRoot . '/html')) {
            return $projectRoot . '/html';
        }
        return $projectRoot . '/public';
    }

    /**
     * Delete sanitized photo files belonging to a deleted business listing.
     */
    public static function deleteBusinessPhotoFiles(?string $photosJson): void
    {
        $urls = self::decodePhotosJson($photosJson);
        if (empty($urls)) {
            return;
        }
        $targetDir = self::resolvePublicWebRoot() . '/uploads/businesses';
        foreach ($urls as $relUrl) {
            $base = basename($relUrl);
            if (preg_match('/^bbiz_[A-Za-z0-9_]+\.(?:webp|jpg|jpeg|png)$/', $base)) {
                $fullPath = $targetDir . '/' . $base;
                if (is_file($fullPath)) {
                    @unlink($fullPath);
                }
            }
        }
    }
}
