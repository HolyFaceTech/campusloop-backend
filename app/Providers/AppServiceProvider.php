<?php

namespace App\Providers;

use App\Filesystem\NoAclS3Adapter;
use Aws\S3\S3Client;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use App\Models\PersonalAccessToken;
use League\Flysystem\Filesystem;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        Storage::extend('s3', function ($app, array $config) {
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

            $adapter = new NoAclS3Adapter(
                $client,
                $config['bucket'],
                $config['root'] ?? '',
                null,
                null,
                $config['options'] ?? [],
            );

            $filesystemConfig = array_merge($config, [
                'retain_visibility' => false,
            ]);

            return new FilesystemAdapter(
                new Filesystem($adapter, $filesystemConfig),
                $adapter,
                $config
            );
        });
    }
}
