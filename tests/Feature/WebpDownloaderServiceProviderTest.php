<?php

declare(strict_types=1);

namespace Weldist\Spatie\MediaLibrary\WebpDownloader\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\Downloaders\DefaultDownloader;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Weldist\Spatie\MediaLibrary\WebpDownloader\Tests\Concerns\CreatesMedia;
use Weldist\Spatie\MediaLibrary\WebpDownloader\Tests\TestCase;
use Weldist\Spatie\MediaLibrary\WebpDownloader\WebpDownloader;

class CustomWebpDownloaderForListener extends WebpDownloader {}

class WebpDownloaderServiceProviderTest extends TestCase
{
    use CreatesMedia;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function persistAndFireAddedEvent(array $overrides = []): Media
    {
        $bytes = $this->makeWebpBytes();

        $media = $this->persistMedia(
            'public',
            $overrides['file_name'] ?? 'photo.jpg',
            $bytes,
            array_merge(['mime_type' => 'image/webp'], $overrides)
        );

        // Simulate Spatie's flow: file is already on disk under file_name,
        // then MediaHasBeenAddedEvent fires.
        event(new MediaHasBeenAddedEvent($media));

        return $media->fresh();
    }

    public function test_renames_file_when_all_conditions_are_met(): void
    {
        config(['media-library.media_downloader' => WebpDownloader::class]);

        $media = $this->persistAndFireAddedEvent([
            'file_name' => 'photo.jpg',
            'mime_type' => 'image/webp',
        ]);

        $this->assertSame('photo.webp', $media->file_name);
        $this->assertTrue(Storage::disk('public')->exists($media->id.'/photo.webp'));
        $this->assertFalse(Storage::disk('public')->exists($media->id.'/photo.jpg'));
    }

    public function test_does_not_rename_when_downloader_is_default(): void
    {
        config(['media-library.media_downloader' => DefaultDownloader::class]);

        $media = $this->persistAndFireAddedEvent([
            'file_name' => 'photo.jpg',
            'mime_type' => 'image/webp',
        ]);

        $this->assertSame('photo.jpg', $media->file_name);
        $this->assertTrue(Storage::disk('public')->exists($media->id.'/photo.jpg'));
    }

    public function test_does_not_rename_when_mime_type_is_not_webp(): void
    {
        config(['media-library.media_downloader' => WebpDownloader::class]);

        $media = $this->persistAndFireAddedEvent([
            'file_name' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
        ]);

        $this->assertSame('photo.jpg', $media->file_name);
    }

    public function test_does_not_rename_when_file_name_already_ends_with_webp_case_insensitive(): void
    {
        config(['media-library.media_downloader' => WebpDownloader::class]);

        $media = $this->persistAndFireAddedEvent([
            'file_name' => 'photo.WEBP',
            'mime_type' => 'image/webp',
        ]);

        $this->assertSame('photo.WEBP', $media->file_name);
        $this->assertTrue(Storage::disk('public')->exists($media->id.'/photo.WEBP'));
    }

    public function test_renames_extensionless_file_name(): void
    {
        config(['media-library.media_downloader' => WebpDownloader::class]);

        $media = $this->persistAndFireAddedEvent([
            'file_name' => 'photo',
            'mime_type' => 'image/webp',
        ]);

        $this->assertSame('photo.webp', $media->file_name);
        $this->assertTrue(Storage::disk('public')->exists($media->id.'/photo.webp'));
        $this->assertFalse(Storage::disk('public')->exists($media->id.'/photo'));
    }

    public function test_subclass_of_webp_downloader_also_triggers_rename(): void
    {
        config(['media-library.media_downloader' => CustomWebpDownloaderForListener::class]);

        $media = $this->persistAndFireAddedEvent([
            'file_name' => 'photo.jpg',
            'mime_type' => 'image/webp',
        ]);

        $this->assertSame('photo.webp', $media->file_name);
        $this->assertTrue(Storage::disk('public')->exists($media->id.'/photo.webp'));
    }

    public function test_rename_is_idempotent_when_event_fires_twice(): void
    {
        config(['media-library.media_downloader' => WebpDownloader::class]);

        $media = $this->persistAndFireAddedEvent([
            'file_name' => 'photo.jpg',
            'mime_type' => 'image/webp',
        ]);

        // Second fire: file is now at .webp, .jpg is gone — guard should skip.
        event(new MediaHasBeenAddedEvent($media));

        $media = $media->fresh();

        $this->assertSame('photo.webp', $media->file_name);
        $this->assertTrue(Storage::disk('public')->exists($media->id.'/photo.webp'));
    }
}