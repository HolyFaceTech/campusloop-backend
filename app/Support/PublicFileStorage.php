<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

class PublicFileStorage
{
    public static function disk()
    {
        return Storage::disk('public');
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
     * Store an uploaded file on the public disk and return the persisted path value.
     *
     * @param  \Illuminate\Http\UploadedFile  $file
     */
    public static function storeUploaded($file, string $directory): string
    {
        $relativePath = $file->store($directory, 'public');

        if (! is_string($relativePath) || $relativePath === '') {
            throw new \RuntimeException('File upload failed. Check storage configuration and permissions.');
        }

        return self::publicPath($relativePath);
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
            return $storedPath;
        }

        if (config('filesystems.disks.public.driver') === 's3') {
            return self::disk()->temporaryUrl($relativePath, now()->addHours(2));
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
