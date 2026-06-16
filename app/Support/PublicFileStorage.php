<?php

namespace App\Support;

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
        $relativePath = ltrim($relativePath, '/');

        if ($relativePath === '') {
            throw new \RuntimeException('Cannot build a public path for an empty storage key.');
        }

        if (config('filesystems.disks.public.driver') === 's3') {
            return self::disk()->url($relativePath);
        }

        return '/storage/'.$relativePath;
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
        if (config('filesystems.disks.public.driver') === 's3') {
            return true;
        }

        if (config('filesystems.default') === 's3') {
            return true;
        }

        $bucket = config('filesystems.disks.public.bucket')
            ?? config('filesystems.disks.s3.bucket');

        return filled($bucket);
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
            try {
                return self::disk()->temporaryUrl($relativePath, now()->addHours(2));
            } catch (\Throwable $exception) {
                Log::error('PublicFileStorage: failed to create signed URL.', [
                    'key' => $relativePath,
                    'error' => $exception->getMessage(),
                ]);

                return '';
            }
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

            return ltrim(str_replace('/storage/', '', (string) $parsed), '/');
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
