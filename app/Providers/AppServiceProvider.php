<?php

namespace App\Providers;

use Aws\S3\S3Client;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use App\Models\PersonalAccessToken;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter as S3Adapter;
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

        // S3 buckets with "Bucket owner enforced" reject ACL headers on PutObject.
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

            $adapter = new S3Adapter(
                $client,
                $config['bucket'],
                $config['root'] ?? '',
                null,
                null,
                $config['options'] ?? [],
            );

            return new FilesystemAdapter(
                new Filesystem($adapter, $config),
                $adapter,
                $config
            );
        });
    }
}
