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

        if (config('filesystems.disks.public.driver') === 's3') {
            return self::disk()->url($relativePath);
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
}
