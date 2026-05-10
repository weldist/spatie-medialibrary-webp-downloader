<?php

declare(strict_types=1);

namespace Weldist\Spatie\MediaLibrary\WebpDownloader\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;
use Weldist\Spatie\MediaLibrary\WebpDownloader\WebpDownloader;
use Weldist\Spatie\MediaLibrary\WebpDownloader\WebpDownloaderServiceProvider;

abstract class TestCase extends BaseTestCase
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('media-library.image_driver', env('IMAGE_DRIVER', 'gd'));
        $app['config']->set('media-library.media_downloader', WebpDownloader::class);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            MediaLibraryServiceProvider::class,
            WebpDownloaderServiceProvider::class,
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Migrations');
    }
}
