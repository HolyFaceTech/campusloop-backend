<?php

namespace App\Support;

use Aws\S3\S3Client;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PublicFileStorage
{
    public static function disk()
    {
        return Storage::disk('public');
    }

    /**
     * Persisted DB value for a stored object (relative key on S3, /storage path locally).
     */
    public static function dbPath(string $relativePath): string
    {
        $relativePath = ltrim($relativePath, '/');

        if ($relativePath === '') {
            throw new \RuntimeException('Cannot build a storage path for an empty key.');
        }

        if (config('filesystems.disks.public.driver') === 's3') {
            return $relativePath;
        }

        return '/storage/'.$relativePath;
    }

    public static function publicPath(string $relativePath): string
    {
        return self::dbPath($relativePath);
    }

    /**
     * @return list<UploadedFile>
     */
    public static function normalizeUploadedFiles(mixed $files): array
    {
        if ($files === null) {
            return [];
        }

        $normalized = Arr::wrap($files);

        return array_values(array_filter(
            $normalized,
            fn ($file) => $file instanceof UploadedFile
        ));
    }

    /**
     * Store an uploaded file on the public disk and return the persisted path value.
     *
     * @param  UploadedFile  $file
     */
    public static function storeUploaded($file, string $directory): string
    {
        $relativePath = $file->store($directory, 'public');

        if (! is_string($relativePath) || $relativePath === '') {
            throw new \RuntimeException('File upload failed. Check storage configuration and permissions.');
        }

        return self::dbPath($relativePath);
    }

    public static function isUsingS3(): bool
    {
        return config('filesystems.disks.public.driver') === 's3';
    }

    /**
     * Generate a browser-safe URL for viewing a stored object inline (not forced download).
     */
    public static function createSignedUrl(
        string $relativePath,
        ?\DateTimeInterface $expiration = null,
        ?string $displayName = null,
        ?string $extension = null,
    ): ?string {
        $relativePath = ltrim($relativePath, '/');

        if ($relativePath === '') {
            return null;
        }

        if (! self::isUsingS3()) {
            return self::urlForResponse($relativePath);
        }

        $expiration ??= now()->addHours(2);
        $inlineOptions = self::inlineViewOptions($relativePath, $displayName, $extension);

        try {
            $temporaryUrl = self::disk()->temporaryUrl($relativePath, $expiration, $inlineOptions);

            if ($temporaryUrl) {
                return $temporaryUrl;
            }

            Log::warning('PublicFileStorage: temporaryUrl returned empty; trying presign.', [
                'key' => $relativePath,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('PublicFileStorage: temporaryUrl failed, trying direct presign.', [
                'key' => $relativePath,
                'error' => $exception->getMessage(),
            ]);
        }

        try {
            return self::presignS3Url($relativePath, $expiration, $inlineOptions);
        } catch (\Throwable $exception) {
            Log::error('PublicFileStorage: presign failed.', [
                'key' => $relativePath,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    private static function inlineViewOptions(
        string $relativePath,
        ?string $displayName = null,
        ?string $extension = null,
    ): array {
        $filename = self::safeFilename($displayName ?: basename($relativePath));
        $mimeType = self::mimeTypeForExtension($extension ?: pathinfo($relativePath, PATHINFO_EXTENSION));

        return [
            'ResponseContentDisposition' => 'inline; filename="'.$filename.'"',
            'ResponseContentType' => $mimeType,
        ];
    }

    private static function safeFilename(string $filename): string
    {
        $filename = trim(str_replace(['"', "\r", "\n"], '', $filename));

        return $filename !== '' ? $filename : 'file';
    }

    private static function mimeTypeForExtension(?string $extension): string
    {
        return match (strtolower((string) $extension)) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'png' => 'image/png',
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            'avi' => 'video/x-msvideo',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            default => 'application/octet-stream',
        };
    }

    /**
     * @param  array<string, string>  $inlineOptions
     */
    private static function presignS3Url(
        string $key,
        \DateTimeInterface $expiration,
        array $inlineOptions = [],
    ): string {
        $config = config('filesystems.disks.public');

        $clientConfig = [
            'version' => 'latest',
            'region' => $config['region'],
        ];

        if (! empty($config['key']) && ! empty($config['secret'])) {
            $clientConfig['credentials'] = [
                'key' => $config['key'],
                'secret' => $config['secret'],
            ];
        }

        if (! empty($config['endpoint'])) {
            $clientConfig['endpoint'] = $config['endpoint'];
        }

        if (isset($config['use_path_style_endpoint'])) {
            $clientConfig['use_path_style_endpoint'] = $config['use_path_style_endpoint'];
        }

        $client = new S3Client($clientConfig);

        $root = trim((string) ($config['root'] ?? ''), '/');
        if ($root !== '') {
            $key = $root.'/'.$key;
        }

        $command = $client->getCommand('GetObject', array_merge([
            'Bucket' => $config['bucket'],
            'Key' => $key,
        ], $inlineOptions));

        $request = $client->createPresignedRequest($command, $expiration);

        return (string) $request->getUri();
    }

    /**
     * URL safe to open in the browser (signed URL for private S3 buckets).
     */
    public static function urlForResponse(?string $storedPath): string
    {
        if ($storedPath === null || $storedPath === '') {
            return '';
        }

        $relativePath = self::relativePath($storedPath);

        if ($relativePath === '') {
            Log::warning('PublicFileStorage: could not resolve storage key from path.', [
                'stored_path' => $storedPath,
            ]);

            return '';
        }

        if (self::isUsingS3()) {
            $signedUrl = self::createSignedUrl(
                $relativePath,
                null,
                basename($relativePath),
                pathinfo($relativePath, PATHINFO_EXTENSION),
            );

            return $signedUrl ?? '';
        }

        if (str_starts_with($storedPath, 'http://') || str_starts_with($storedPath, 'https://')) {
            return $storedPath;
        }

        if (str_starts_with($storedPath, '/')) {
            return $storedPath;
        }

        return '/storage/'.$relativePath;
    }

    public static function relativePath(?string $storedPath): string
    {
        if ($storedPath === null || $storedPath === '') {
            return '';
        }

        if (str_starts_with($storedPath, 'http://') || str_starts_with($storedPath, 'https://')) {
            $parsed = parse_url($storedPath, PHP_URL_PATH);
            $path = ltrim((string) $parsed, '/');
            $bucket = config('filesystems.disks.public.bucket');

            if ($bucket && str_starts_with($path, $bucket.'/')) {
                $path = substr($path, strlen($bucket) + 1);
            }

            return ltrim(str_replace('/storage/', '', $path), '/');
        }

        return ltrim(str_replace('/storage/', '', $storedPath), '/');
    }

    public static function deleteStored(?string $storedPath): void
    {
        $relativePath = self::relativePath($storedPath);

        if ($relativePath === '') {
            return;
        }

        if (self::disk()->exists($relativePath)) {
            self::disk()->delete($relativePath);
        }
    }

    public static function readContents(?string $storedPath): ?string
    {
        $relativePath = self::relativePath($storedPath);

        if ($relativePath === '' || ! self::disk()->exists($relativePath)) {
            return null;
        }

        return self::disk()->get($relativePath);
    }
}
