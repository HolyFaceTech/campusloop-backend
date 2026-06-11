<?php

namespace App\Filesystem;

use Aws\S3\S3ClientInterface;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Config;
use League\Flysystem\PathPrefixer;
use League\Flysystem\UnableToWriteFile;
use ReflectionClass;
use Throwable;

/**
 * S3 adapter for buckets with "Bucket owner enforced" (ACLs disabled).
 * Skips ACL on PutObject — required for modern AWS S3 buckets.
 */
class NoAclS3Adapter extends AwsS3V3Adapter
{
    public function write(string $path, string $contents, Config $config): void
    {
        $this->putObjectWithoutAcl($path, $contents);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->putObjectWithoutAcl($path, $contents);
    }

    public function createDirectory(string $path, Config $config): void
    {
        $this->putObjectWithoutAcl(rtrim($path, '/').'/', '');
    }

    private function putObjectWithoutAcl(string $path, mixed $body): void
    {
        try {
            $client = $this->getAdapterProperty('client');
            $bucket = $this->getAdapterProperty('bucket');
            /** @var PathPrefixer $prefixer */
            $prefixer = $this->getAdapterProperty('prefixer');

            $client->putObject([
                'Bucket' => $bucket,
                'Key' => $prefixer->prefixPath($path),
                'Body' => $body,
            ]);
        } catch (Throwable $exception) {
            throw UnableToWriteFile::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    private function getAdapterProperty(string $name): mixed
    {
        $reflection = new ReflectionClass(AwsS3V3Adapter::class);
        $property = $reflection->getProperty($name);
        $property->setAccessible(true);

        return $property->getValue($this);
    }
}
