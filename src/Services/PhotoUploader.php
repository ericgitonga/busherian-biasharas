<?php
declare(strict_types=1);

namespace BusherianBiasharas\Services;

use PDO;

final class PhotoUploader
{
    public const MAX_PHOTOS = 10;
    public const MAX_DIMENSION = 1200;
    public const MAX_FILE_BYTES = 8388608; // 8 MB per raw input file (#176)
    public const MAX_TOTAL_UPLOAD_BYTES = 41943040; // 40 MB aggregate per request (#176, #411)
    public const MAX_SOURCE_EDGE = 4096; // Max width or height BEFORE GD allocation (#176)
    public const MAX_SOURCE_PIXELS = 16000000; // 16 MP max total pixels BEFORE GD allocation (#176)

    private const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public static function resolvePublicWebRoot(): string
    {
        $explicitRoot = \BusherianBiasharas\Config\Database::env('BBIZ_PUBLIC_WEB_ROOT', '');
        if ($explicitRoot !== '') {
            $clean = rtrim($explicitRoot, '/');
            if (!is_dir($clean)) {
                @mkdir($clean, 0775, true);
            }
            return $clean;
        }
        $projectRoot = dirname(__DIR__, 2);
        if (is_dir($projectRoot . '/html')) {
            return $projectRoot . '/html';
        }
        return $projectRoot . '/public';
    }

    public static function uploadDir(): string
    {
        $dir = self::resolvePublicWebRoot() . '/uploads/businesses';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public static function logoUploadDir(): string
    {
        $dir = self::resolvePublicWebRoot() . '/uploads/logos';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public static function quarantineDirRoot(): string
    {
        $override = \BusherianBiasharas\Config\Database::env('BBIZ_QUARANTINE_MEDIA_DIR', '');
        if ($override !== '') {
            $dir = rtrim($override, '/');
        } else {
            $customPub = \BusherianBiasharas\Config\Database::env('BBIZ_PUBLIC_WEB_ROOT', '');
            if ($customPub !== '') {
                $cleanPub = rtrim($customPub, '/');
                $dir = dirname($cleanPub) . '/' . basename($cleanPub) . '_quarantined_media';
            } else {
                $dir = dirname(__DIR__, 2) . '/storage/quarantined_media';
            }
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        return $dir;
    }

    public static function resolveSafeUploadFilePath(string $urlPath, bool $mustExist = false): ?string
    {
        $trimmed = trim($urlPath);
        $baseDir = null;
        $filename = '';

        if (preg_match('~^/assets/photos/([A-Za-z0-9._-]+)$~', $trimmed, $m)) {
            $filename = $m[1];
            $assetsDir = self::resolvePublicWebRoot() . '/assets/photos';
            if (!is_dir($assetsDir)) {
                @mkdir($assetsDir, 0775, true);
            }
            $baseDir = realpath($assetsDir);
        } elseif (preg_match('~^/assets/img/logos/([A-Za-z0-9._-]+)$~', $trimmed, $m)) {
            $filename = $m[1];
            $assetsDir = self::resolvePublicWebRoot() . '/assets/img/logos';
            if (!is_dir($assetsDir)) {
                @mkdir($assetsDir, 0775, true);
            }
            $baseDir = realpath($assetsDir);
        } elseif (preg_match('~^/uploads/logos/([A-Za-z0-9._-]+)$~', $trimmed, $m)) {
            $filename = $m[1];
            $baseDir = realpath(self::logoUploadDir());
        } elseif (preg_match('~^/uploads/businesses/([A-Za-z0-9._-]+)$~', $trimmed, $m)) {
            $filename = $m[1];
            $baseDir = realpath(self::uploadDir());
        } else {
            return null;
        }

        if ($filename === '' || $filename[0] === '.' || str_contains($filename, '..') || $baseDir === false) {
            return null;
        }
        $candidate = $baseDir . DIRECTORY_SEPARATOR . $filename;
        if (is_link($candidate)) {
            return null;
        }
        if (file_exists($candidate)) {
            $resolved = realpath($candidate);
            if ($resolved === false || !str_starts_with($resolved, $baseDir . DIRECTORY_SEPARATOR)) {
                return null;
            }
            return is_file($resolved) ? $resolved : null;
        }
        return $mustExist ? null : $candidate;
    }

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
            if (is_string($item) && self::resolveSafeUploadFilePath($item, false) !== null) {
                $clean[] = $item;
            }
        }
        return array_slice(array_values(array_unique($clean)), 0, self::MAX_PHOTOS);
    }

    public static function processBusinessPhotos(
        ?string $existingPhotosJson,
        array $removeUrls = [],
        ?array $uploadedFiles = null,
        ?string $clientDataUrlsJson = null,
        int $stageQuarantineBusinessId = 0
    ): string {
        $current = self::decodePhotosJson($existingPhotosJson);
        if (!empty($removeUrls)) {
            $removeSet = array_flip(array_map('strval', $removeUrls));
            $current = array_values(array_filter($current, static fn(string $u): bool => !isset($removeSet[$u])));
        }

        $newBinaryImages = [];
        $aggregateBytes = 0;

        if ($clientDataUrlsJson !== null && trim($clientDataUrlsJson) !== '' && trim($clientDataUrlsJson) !== '[]') {
            $decodedList = json_decode($clientDataUrlsJson, true);
            if (!is_array($decodedList)) {
                throw new PhotoUploadValidationException('Malformed inline photo upload payload.');
            }
            if ((count($current) + count($decodedList)) > self::MAX_PHOTOS) {
                throw new PhotoUploadValidationException(
                    'You may upload at most ' . self::MAX_PHOTOS . ' photos per business listing.'
                );
            }
            foreach ($decodedList as $dataUrl) {
                if (!is_string($dataUrl) || trim($dataUrl) === '') {
                    continue;
                }
                if (!preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=\s]+)$#', $dataUrl, $m)) {
                    throw new PhotoUploadValidationException('Unsupported inline photo format (use JPEG, PNG, or WebP).');
                }
                $rawBytes = base64_decode($m[2], true);
                if (!is_string($rawBytes) || $rawBytes === '') {
                    throw new PhotoUploadValidationException('Invalid base64 image data.');
                }
                $len = strlen($rawBytes);
                if ($len > self::MAX_FILE_BYTES) {
                    throw new PhotoUploadValidationException('Each photo must be 8 MB or smaller.');
                }
                $aggregateBytes += $len;
                if ($aggregateBytes > self::MAX_TOTAL_UPLOAD_BYTES) {
                    throw new PhotoUploadValidationException('Combined photo upload size must be 20 MB or smaller.');
                }
                $newBinaryImages[] = $rawBytes;
            }
        }

        if (empty($newBinaryImages) && is_array($uploadedFiles) && isset($uploadedFiles['tmp_name'])) {
            $tmpNames = is_array($uploadedFiles['tmp_name']) ? $uploadedFiles['tmp_name'] : [$uploadedFiles['tmp_name']];
            $errors   = is_array($uploadedFiles['error'] ?? null) ? $uploadedFiles['error'] : [$uploadedFiles['error'] ?? UPLOAD_ERR_NO_FILE];
            $sizes    = is_array($uploadedFiles['size'] ?? null) ? $uploadedFiles['size'] : [$uploadedFiles['size'] ?? 0];
            $names    = is_array($uploadedFiles['name'] ?? null) ? $uploadedFiles['name'] : [$uploadedFiles['name'] ?? ''];

            $nonEmptyFiles = 0;
            foreach ($tmpNames as $idx => $tmpPath) {
                $err = (int)($errors[$idx] ?? UPLOAD_ERR_NO_FILE);
                $nm = trim((string)($names[$idx] ?? ''));
                if ($err === UPLOAD_ERR_NO_FILE || ($nm === '' && (!is_string($tmpPath) || $tmpPath === ''))) {
                    continue;
                }
                $nonEmptyFiles++;
                if ((count($current) + $nonEmptyFiles) > self::MAX_PHOTOS) {
                    throw new PhotoUploadValidationException(
                        'You may upload at most ' . self::MAX_PHOTOS . ' photos per business listing.'
                    );
                }
                if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                    throw new PhotoUploadValidationException('Uploaded photo exceeds the 8 MB per-file size limit.');
                }
                if ($err !== UPLOAD_ERR_OK) {
                    throw new PhotoUploadValidationException('Photo upload failed with upload error code ' . $err . '.');
                }
                if (!is_string($tmpPath) || $tmpPath === '' || (!is_uploaded_file($tmpPath) && !is_file($tmpPath))) {
                    continue;
                }
                $declaredSz = (int)($sizes[$idx] ?? 0);
                $actualSz = (int)(@filesize($tmpPath) ?: $declaredSz);
                if ($actualSz <= 0) {
                    continue;
                }
                if ($actualSz > self::MAX_FILE_BYTES || $declaredSz > self::MAX_FILE_BYTES) {
                    throw new PhotoUploadValidationException('Each photo must be 8 MB or smaller.');
                }
                $aggregateBytes += $actualSz;
                if ($aggregateBytes > self::MAX_TOTAL_UPLOAD_BYTES) {
                    throw new PhotoUploadValidationException('Combined photo upload size must be 20 MB or smaller.');
                }
                $bytes = @file_get_contents($tmpPath);
                if (is_string($bytes) && $bytes !== '') {
                    $newBinaryImages[] = $bytes;
                }
            }
        }

        if ((count($current) + count($newBinaryImages)) > self::MAX_PHOTOS) {
            throw new PhotoUploadValidationException(
                'You may upload at most ' . self::MAX_PHOTOS . ' photos per business listing.'
            );
        }

        $newlySavedUrls = [];
        try {
            foreach ($newBinaryImages as $rawBytes) {
                $savedRelUrl = self::sanitizeAndSaveImageBytes($rawBytes, $stageQuarantineBusinessId);
                if ($savedRelUrl !== null) {
                    $newlySavedUrls[] = $savedRelUrl;
                    $current[] = $savedRelUrl;
                }
            }
        } catch (\Throwable $e) {
            self::deleteRemovedBusinessPhotos($stageQuarantineBusinessId, $newlySavedUrls, []);
            throw $e;
        }

        return (string)json_encode(array_slice($current, 0, self::MAX_PHOTOS), JSON_UNESCAPED_SLASHES);
    }

    public static function sanitizeAndSaveImageBytes(string $rawBytes, int $stageQuarantineBusinessId = 0): ?string
    {
        if ($rawBytes === '') {
            return null;
        }
        if (strlen($rawBytes) > self::MAX_FILE_BYTES) {
            throw new PhotoUploadValidationException('Each photo must be 8 MB or smaller.');
        }

        $info = @getimagesizefromstring($rawBytes);
        if (!is_array($info) || empty($info['mime']) || !in_array($info['mime'], self::ALLOWED_MIMES, true)) {
            return null;
        }
        $width = (int)($info[0] ?? 0);
        $height = (int)($info[1] ?? 0);
        if ($width < 10 || $height < 10) {
            return null;
        }

        // Decompression-bomb guard (#176): reject >4096px edge or >16 MP BEFORE calling imagecreatefromstring()
        if ($width > self::MAX_SOURCE_EDGE || $height > self::MAX_SOURCE_EDGE || ($width * $height) > self::MAX_SOURCE_PIXELS) {
            throw new PhotoUploadValidationException(
                "Image dimensions ({$width}x{$height}) exceed the maximum allowed " . self::MAX_SOURCE_EDGE . "px edge / 16 MP security limit."
            );
        }

        if ($stageQuarantineBusinessId > 0) {
            $targetDir = self::quarantineDirRoot() . '/' . $stageQuarantineBusinessId;
            if (!is_dir($targetDir) && !@mkdir($targetDir, 0750, true) && !is_dir($targetDir)) {
                throw new \RuntimeException("Failed to initialize staging media directory for business #{$stageQuarantineBusinessId}.");
            }
            if (is_link($targetDir) || !is_writable($targetDir)) {
                throw new \RuntimeException("Staging media directory is not writable for business #{$stageQuarantineBusinessId}.");
            }
        } else {
            $targetDir = self::uploadDir();
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
                @chmod($fullPath, $stageQuarantineBusinessId > 0 ? 0640 : 0644);
                return '/uploads/businesses/' . $filename;
            }
        }

        return null;
    }

    public static function isMutableUploadPath(?string $safePath): bool
    {
        if ($safePath === null) {
            return false;
        }
        $upDir = realpath(self::uploadDir());
        $logoDir = realpath(self::logoUploadDir());
        return ($upDir !== false && str_starts_with($safePath, $upDir . DIRECTORY_SEPARATOR))
            || ($logoDir !== false && str_starts_with($safePath, $logoDir . DIRECTORY_SEPARATOR));
    }

    public static function deleteRemovedLogoFile(?string $urlPath): bool
    {
        if ($urlPath === null || trim($urlPath) === '') {
            return false;
        }
        $safePath = self::resolveSafeUploadFilePath($urlPath, true);
        if (self::isMutableUploadPath($safePath) && @unlink($safePath)) {
            return true;
        }
        return false;
    }

    public static function processBusinessLogo(
        ?string $existingLogoPath,
        bool $removeLogo = false,
        ?array $uploadedFile = null,
        ?string $clientDataUrl = null,
        int $stageBusinessId = 0,
        bool $deferDeletion = false,
        ?string &$replacedLogoPath = null
    ): ?string {
        $replacedLogoPath = null;
        if ($removeLogo) {
            if ($existingLogoPath !== null) {
                if ($deferDeletion) {
                    $replacedLogoPath = $existingLogoPath;
                } else {
                    self::deleteRemovedLogoFile($existingLogoPath);
                }
            }
            return null;
        }

        $rawBytes = null;
        if ($clientDataUrl !== null && trim($clientDataUrl) !== '') {
            if (preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=\s]+)$#', $clientDataUrl, $m)) {
                $rawBytes = base64_decode($m[2], true);
                if (!is_string($rawBytes) || $rawBytes === '') {
                    throw new PhotoUploadValidationException('Invalid base64 logo image data.');
                }
            } else {
                throw new PhotoUploadValidationException('Unsupported inline logo format (use PNG, WebP, or JPEG).');
            }
        } elseif (is_array($uploadedFile) && isset($uploadedFile['tmp_name'])) {
            $err = (int)($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE);
            $tmpPath = (string)($uploadedFile['tmp_name'] ?? '');
            $nm = trim((string)($uploadedFile['name'] ?? ''));
            if ($err !== UPLOAD_ERR_NO_FILE && $nm !== '' && $tmpPath !== '') {
                if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                    throw new PhotoUploadValidationException('Uploaded logo exceeds the 8 MB file size limit.');
                }
                if ($err !== UPLOAD_ERR_OK) {
                    throw new PhotoUploadValidationException('Logo upload failed with upload error code ' . $err . '.');
                }
                if (!is_uploaded_file($tmpPath) && !is_file($tmpPath)) {
                    throw new PhotoUploadValidationException('Invalid uploaded logo file.');
                }
                $size = (int)(@filesize($tmpPath) ?: ($uploadedFile['size'] ?? 0));
                if ($size > self::MAX_FILE_BYTES) {
                    throw new PhotoUploadValidationException('Logo image must be 8 MB or smaller.');
                }
                $rawBytes = @file_get_contents($tmpPath);
            }
        }

        if ($rawBytes === null || $rawBytes === '') {
            return $existingLogoPath;
        }

        if (strlen($rawBytes) > self::MAX_FILE_BYTES) {
            throw new PhotoUploadValidationException('Logo image must be 8 MB or smaller.');
        }

        $info = @getimagesizefromstring($rawBytes);
        if (!is_array($info) || empty($info['mime']) || !in_array($info['mime'], self::ALLOWED_MIMES, true)) {
            throw new PhotoUploadValidationException('Invalid logo file format. Please upload a PNG, WebP, or JPEG image.');
        }

        $width = (int)($info[0] ?? 0);
        $height = (int)($info[1] ?? 0);
        if ($width < 10 || $height < 10) {
            throw new PhotoUploadValidationException('Logo image dimensions are too small (minimum 10x10 pixels).');
        }

        if ($width > self::MAX_SOURCE_EDGE || $height > self::MAX_SOURCE_EDGE || ($width * $height) > self::MAX_SOURCE_PIXELS) {
            throw new PhotoUploadValidationException(
                "Logo dimensions ({$width}x{$height}) exceed the maximum allowed " . self::MAX_SOURCE_EDGE . "px edge / 16 MP security limit."
            );
        }

        $targetDir = self::logoUploadDir();
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('Failed to initialize logo upload directory.');
        }

        $fileId = 'logo_' . ($stageBusinessId > 0 ? ($stageBusinessId . '_') : '') . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4));

        if (function_exists('imagecreatefromstring')) {
            $srcImg = @imagecreatefromstring($rawBytes);
            if ($srcImg === false) {
                throw new PhotoUploadValidationException('Could not process the uploaded logo image.');
            }

            $maxLogoEdge = 1600;
            $newW = $width;
            $newH = $height;
            if ($width > $maxLogoEdge || $height > $maxLogoEdge) {
                $ratio = min($maxLogoEdge / $width, $maxLogoEdge / $height);
                $newW = max(1, (int)round($width * $ratio));
                $newH = max(1, (int)round($height * $ratio));
            }

            $dstImg = imagecreatetruecolor($newW, $newH);
            imagealphablending($dstImg, false);
            imagesavealpha($dstImg, true);
            $transparent = imagecolorallocatealpha($dstImg, 0, 0, 0, 127);
            imagefilledrectangle($dstImg, 0, 0, $newW, $newH, $transparent);
            imagealphablending($dstImg, true);
            imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $newW, $newH, $width, $height);

            $filename = $fileId . '.png';
            $fullPath = $targetDir . '/' . $filename;
            $ok = @imagepng($dstImg, $fullPath, 6);

            imagedestroy($srcImg);
            imagedestroy($dstImg);

            if ($ok && is_file($fullPath)) {
                @chmod($fullPath, 0644);
                if ($existingLogoPath !== null) {
                    if ($deferDeletion) {
                        $replacedLogoPath = $existingLogoPath;
                    } else {
                        self::deleteRemovedLogoFile($existingLogoPath);
                    }
                }
                return '/uploads/logos/' . $filename;
            }
        }

        throw new PhotoUploadValidationException('Server could not process logo image.');
    }

    public static function deleteBusinessPhotoFiles(?string $photosJson): void
    {
        foreach (self::decodePhotosJson($photosJson) as $relUrl) {
            $safePath = self::resolveSafeUploadFilePath($relUrl, true);
            if (self::isMutableUploadPath($safePath)) {
                @unlink($safePath);
            }
        }
    }

    public static function deleteRemovedPhotoUrls(array $previousUrls, array $keptUrls): int
    {
        $removed = array_diff($previousUrls, $keptUrls);
        $deleted = 0;
        foreach ($removed as $url) {
            if (!is_string($url)) {
                continue;
            }
            $safePath = self::resolveSafeUploadFilePath($url, true);
            if (self::isMutableUploadPath($safePath) && @unlink($safePath)) {
                $deleted++;
            }
        }
        return $deleted;
    }

    /**
     * Relocate all public business photos out of webroot into storage/quarantined_media/<businessId>/ (#177, #208).
     * Fails closed with RuntimeException if any referenced public upload file cannot be removed from public webroot.
     */
    public static function quarantineBusinessPhotos(int $businessId, ?string $photosJson): int
    {
        if ($businessId <= 0) {
            return 0;
        }
        $urls = self::decodePhotosJson($photosJson);
        if (empty($urls)) {
            return 0;
        }
        $qDir = self::quarantineDirRoot() . '/' . $businessId;
        if (!is_dir($qDir) && !@mkdir($qDir, 0750, true) && !is_dir($qDir)) {
            throw new \RuntimeException("Failed to initialize quarantine media directory for business #{$businessId}.");
        }
        if (is_link($qDir) || !is_writable($qDir)) {
            throw new \RuntimeException("Quarantine media directory is not writable for business #{$businessId}.");
        }

        $moved = 0;
        foreach ($urls as $url) {
            $srcPath = self::resolveSafeUploadFilePath($url, true);
            if ($srcPath === null) {
                continue;
            }
            if (!self::isMutableUploadPath($srcPath)) {
                // Static assets are repository-managed and should not be unlinked or quarantined
                continue;
            }
            $destPath = $qDir . '/' . basename($srcPath);
            $movedOk = false;
            if (@rename($srcPath, $destPath)) {
                $movedOk = true;
            } elseif (@copy($srcPath, $destPath) && @unlink($srcPath)) {
                $movedOk = true;
            }
            if (!$movedOk || file_exists($srcPath) || !is_file($destPath)) {
                throw new \RuntimeException("Failed to quarantine public media file for business #{$businessId}.");
            }
            @chmod($destPath, 0640);
            $moved++;
        }
        return $moved;
    }

    /**
     * Delete removed photo files from BOTH public uploads and storage/quarantined_media/<businessId>/ (#208).
     */
    public static function deleteRemovedBusinessPhotos(int $businessId, array $previousUrls, array $keptUrls): int
    {
        $deleted = self::deleteRemovedPhotoUrls($previousUrls, $keptUrls);
        if ($businessId <= 0) {
            return $deleted;
        }
        $keptMap = array_fill_keys($keptUrls, true);
        $qDir = self::quarantineDirRoot() . '/' . $businessId;
        if (!is_dir($qDir) || is_link($qDir)) {
            return $deleted;
        }
        foreach ($previousUrls as $oldUrl) {
            if (!is_string($oldUrl) || isset($keptMap[$oldUrl])) {
                continue;
            }
            $expectedPub = self::resolveSafeUploadFilePath($oldUrl, false);
            if ($expectedPub === null) {
                continue;
            }
            $qPath = $qDir . '/' . basename($expectedPub);
            if (is_file($qPath) && !is_link($qPath) && @unlink($qPath)) {
                $deleted++;
            }
        }
        return $deleted;
    }

    public static function restoreQuarantinedBusinessPhotos(int $businessId, ?string $photosJson): int
    {
        if ($businessId <= 0) {
            return 0;
        }
        $urls = self::decodePhotosJson($photosJson);
        $qDir = self::quarantineDirRoot() . '/' . $businessId;
        if (!is_dir($qDir)) {
            return 0;
        }
        $restored = 0;
        foreach ($urls as $url) {
            $destPath = self::resolveSafeUploadFilePath($url, false);
            if ($destPath === null) {
                continue;
            }
            $qFile = $qDir . '/' . basename($destPath);
            if (!is_file($qFile) || is_link($qFile)) {
                continue;
            }
            if (@rename($qFile, $destPath)) {
                @chmod($destPath, 0644);
                $restored++;
            } elseif (@copy($qFile, $destPath) && @unlink($qFile)) {
                @chmod($destPath, 0644);
                $restored++;
            }
        }
        $remaining = @scandir($qDir);
        if (is_array($remaining) && count(array_diff($remaining, ['.', '..'])) === 0) {
            @rmdir($qDir);
        }
        return $restored;
    }

    /**
     * Relocate public company logo file out of webroot into storage/quarantined_media/<businessId>/ (#442).
     */
    public static function quarantineBusinessLogo(int $businessId, ?string $logoPath): bool
    {
        if ($businessId <= 0 || $logoPath === null || trim($logoPath) === '') {
            return false;
        }
        $srcPath = self::resolveSafeUploadFilePath($logoPath, true);
        if ($srcPath === null || !self::isMutableUploadPath($srcPath)) {
            return false;
        }
        $qDir = self::quarantineDirRoot() . '/' . $businessId;
        if (!is_dir($qDir) && !@mkdir($qDir, 0750, true) && !is_dir($qDir)) {
            throw new \RuntimeException("Failed to initialize quarantine media directory for business #{$businessId}.");
        }
        if (is_link($qDir) || !is_writable($qDir)) {
            throw new \RuntimeException("Quarantine media directory is not writable for business #{$businessId}.");
        }

        $destPath = $qDir . '/' . basename($srcPath);
        $movedOk = false;
        if (@rename($srcPath, $destPath)) {
            $movedOk = true;
        } elseif (@copy($srcPath, $destPath) && @unlink($srcPath)) {
            $movedOk = true;
        }
        if (!$movedOk || file_exists($srcPath) || !is_file($destPath)) {
            throw new \RuntimeException("Failed to quarantine public logo file for business #{$businessId}.");
        }
        @chmod($destPath, 0640);
        return true;
    }

    /**
     * Restore quarantined company logo file from storage/quarantined_media/<businessId>/ back into webroot (#442).
     */
    public static function restoreQuarantinedBusinessLogo(int $businessId, ?string $logoPath): bool
    {
        if ($businessId <= 0 || $logoPath === null || trim($logoPath) === '') {
            return false;
        }
        $destPath = self::resolveSafeUploadFilePath($logoPath, false);
        if ($destPath === null || !self::isMutableUploadPath($destPath)) {
            return false;
        }
        $qDir = self::quarantineDirRoot() . '/' . $businessId;
        if (!is_dir($qDir)) {
            return false;
        }
        $qFile = $qDir . '/' . basename($destPath);
        if (!is_file($qFile) || is_link($qFile)) {
            return false;
        }
        $parentDir = dirname($destPath);
        if (!is_dir($parentDir) && !@mkdir($parentDir, 0775, true) && !is_dir($parentDir)) {
            return false;
        }
        $restored = false;
        if (@rename($qFile, $destPath)) {
            @chmod($destPath, 0644);
            $restored = true;
        } elseif (@copy($qFile, $destPath) && @unlink($qFile)) {
            @chmod($destPath, 0644);
            $restored = true;
        }
        $remaining = @scandir($qDir);
        if (is_array($remaining) && count(array_diff($remaining, ['.', '..'])) === 0) {
            @rmdir($qDir);
        }
        return $restored;
    }

    public static function deleteAllBusinessMedia(int $businessId, ?string $photosJson, ?string $logoPath = null): int
    {
        $deleted = 0;
        foreach (self::decodePhotosJson($photosJson) as $url) {
            $safePath = self::resolveSafeUploadFilePath($url, true);
            if (self::isMutableUploadPath($safePath) && @unlink($safePath)) {
                $deleted++;
            }
        }
        if ($logoPath !== null && trim($logoPath) !== '') {
            $logoSafe = self::resolveSafeUploadFilePath($logoPath, true);
            if (self::isMutableUploadPath($logoSafe) && @unlink($logoSafe)) {
                $deleted++;
            }
        }
        if ($businessId > 0) {
            $qDir = self::quarantineDirRoot() . '/' . $businessId;
            if (is_dir($qDir) && !is_link($qDir)) {
                foreach (@scandir($qDir) ?: [] as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    $p = $qDir . '/' . $entry;
                    if (is_file($p) && !is_link($p) && @unlink($p)) {
                        $deleted++;
                    }
                }
                @rmdir($qDir);
            }
        }
        return $deleted;
    }

    public static function hasRemainingBusinessMediaOnDisk(int $businessId, ?string $photosJson, ?string $logoPath = null): bool
    {
        foreach (self::decodePhotosJson($photosJson) as $url) {
            $safePath = self::resolveSafeUploadFilePath($url, true);
            if ($safePath !== null && is_file($safePath)) {
                return true;
            }
        }
        if ($logoPath !== null && trim($logoPath) !== '') {
            $logoSafe = self::resolveSafeUploadFilePath($logoPath, true);
            if ($logoSafe !== null && is_file($logoSafe)) {
                return true;
            }
        }
        if ($businessId > 0) {
            $qDir = self::quarantineDirRoot() . '/' . $businessId;
            if (is_dir($qDir) && !is_link($qDir)) {
                foreach (@scandir($qDir) ?: [] as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    if (is_file($qDir . '/' . $entry)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    public static function sweepOrphanedMedia(PDO $pdo, int $minAgeSeconds = 3600): array
    {
        $ownSweepTx = !$pdo->inTransaction();
        if ($ownSweepTx) {
            $pdo->beginTransaction();
        }
        try {
            VettingPurger::acquireNamedSequenceLock($pdo, 'media_sweep_lock');
            $referencedFiles = [];
            $quarantinedBizIds = [];

            $stmt = $pdo->query("SELECT id, status, photos_json FROM businesses");
        foreach ($stmt->fetchAll() as $row) {
            $bid = (int)($row['id'] ?? 0);
            $st = (string)($row['status'] ?? '');
            if ($st === 'quarantined_dpa') {
                $quarantinedBizIds[$bid] = true;
                continue; // Quarantined records MUST NEVER keep files in public/uploads/ (#208)
            }
            if ($st === 'rejected') {
                continue;
            }
            foreach (self::decodePhotosJson($row['photos_json'] ?? null) as $u) {
                $referencedFiles[basename($u)] = true;
            }
        }

        $cutoff = time() - max(0, $minAgeSeconds);
        $pubDeleted = 0;
        $upDir = realpath(self::uploadDir());
        if ($upDir !== false && is_dir($upDir)) {
            foreach (@scandir($upDir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || $entry[0] === '.') {
                    continue;
                }
                if (isset($referencedFiles[$entry])) {
                    continue;
                }
                $full = $upDir . DIRECTORY_SEPARATOR . $entry;
                if (is_link($full) || !is_file($full)) {
                    continue;
                }
                $mtime = (int)(@filemtime($full) ?: time());
                if ($mtime <= $cutoff && @unlink($full)) {
                    $pubDeleted++;
                }
            }
        }

        $quarDeleted = 0;
        $qRoot = realpath(self::quarantineDirRoot());
        if ($qRoot !== false && is_dir($qRoot)) {
            foreach (@scandir($qRoot) ?: [] as $bizFolder) {
                if ($bizFolder === '.' || $bizFolder === '..' || !ctype_digit($bizFolder)) {
                    continue;
                }
                $bid = (int)$bizFolder;
                if (isset($quarantinedBizIds[$bid])) {
                    continue;
                }
                $bDir = $qRoot . DIRECTORY_SEPARATOR . $bizFolder;
                if (!is_dir($bDir) || is_link($bDir)) {
                    continue;
                }
                $allRemovedInDir = true;
                foreach (@scandir($bDir) ?: [] as $f) {
                    if ($f === '.' || $f === '..') {
                        continue;
                    }
                    $fp = $bDir . DIRECTORY_SEPARATOR . $f;
                    if (!is_file($fp) || is_link($fp)) {
                        continue;
                    }
                    $qMtime = (int)(@filemtime($fp) ?: time());
                    if ($qMtime <= $cutoff && @unlink($fp)) {
                        $quarDeleted++;
                    } else {
                        $allRemovedInDir = false;
                    }
                }
                if ($allRemovedInDir) {
                    @rmdir($bDir);
                }
            }
        }
            if ($ownSweepTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownSweepTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return [
            'orphaned_public_deleted' => $pubDeleted,
            'orphaned_quarantine_deleted' => $quarDeleted,
        ];
    }
}
