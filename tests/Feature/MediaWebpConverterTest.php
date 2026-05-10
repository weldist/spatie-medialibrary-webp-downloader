<?php

declare(strict_types=1);

namespace Weldist\Spatie\MediaLibrary\WebpDownloader\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Weldist\Spatie\MediaLibrary\WebpDownloader\ConversionResult;
use Weldist\Spatie\MediaLibrary\WebpDownloader\MediaWebpConverter;
use Weldist\Spatie\MediaLibrary\WebpDownloader\Tests\Concerns\CreatesMedia;
use Weldist\Spatie\MediaLibrary\WebpDownloader\Tests\TestCase;

class MediaWebpConverterTest extends TestCase
{
    use CreatesMedia;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_jpeg_media_is_converted_to_webp(): void
    {
        $bytes = $this->makeJpegBytes();
        $media = $this->persistMedia('public', 'photo.jpg', $bytes, [
            'file_name' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
        ]);

        $result = app(MediaWebpConverter::class)->convert($media);

        $this->assertSame(ConversionResult::STATUS_CONVERTED, $result->status);
        $this->assertSame(strlen($bytes), $result->oldSize);
        $this->assertGreaterThan(0, $result->newSize);

        $media->refresh();
        $this->assertSame('photo.webp', $media->file_name);
        $this->assertSame('image/webp', $media->mime_type);
        $this->assertSame($result->newSize, $media->size);

        $disk = Storage::disk('public');
        $this->assertTrue($disk->exists($media->id.'/photo.webp'));
        $this->assertFalse($disk->exists($media->id.'/photo.jpg'));
        $this->assertSame('image/webp', mime_content_type($disk->path($media->id.'/photo.webp')));
    }

    public function test_png_media_is_converted_to_webp(): void
    {
        $media = $this->persistMedia('public', 'graphic.png', $this->makePngBytes(), [
            'file_name' => 'graphic.png',
            'mime_type' => 'image/png',
        ]);

        $result = app(MediaWebpConverter::class)->convert($media);

        $this->assertSame(ConversionResult::STATUS_CONVERTED, $result->status);

        $media->refresh();
        $this->assertSame('graphic.webp', $media->file_name);
        $this->assertSame('image/webp', $media->mime_type);
    }

    public function test_already_webp_media_is_skipped(): void
    {
        $media = $this->persistMedia('public', 'photo.webp', $this->makeWebpBytes(), [
            'file_name' => 'photo.webp',
            'mime_type' => 'image/webp',
        ]);

        $result = app(MediaWebpConverter::class)->convert($media);

        $this->assertSame(ConversionResult::STATUS_SKIPPED, $result->status);
        $this->assertSame(0, $result->oldSize);
        $this->assertSame(0, $result->newSize);

        $media->refresh();
        $this->assertSame('photo.webp', $media->file_name);
    }

    public function test_non_image_media_is_skipped(): void
    {
        $media = $this->persistMedia('public', 'doc.pdf', '%PDF-1.4 fake', [
            'file_name' => 'doc.pdf',
            'mime_type' => 'application/pdf',
        ]);

        $result = app(MediaWebpConverter::class)->convert($media);

        $this->assertSame(ConversionResult::STATUS_SKIPPED, $result->status);

        $media->refresh();
        $this->assertSame('doc.pdf', $media->file_name);
        $this->assertSame('application/pdf', $media->mime_type);
    }

    public function test_skip_mimes_entries_are_skipped(): void
    {
        $svg = $this->persistMedia('public', 'icon.svg', '<svg/>', [
            'file_name' => 'icon.svg',
            'mime_type' => 'image/svg+xml',
        ]);
        $gif = $this->persistMedia('public', 'anim.gif', 'GIF89a', [
            'file_name' => 'anim.gif',
            'mime_type' => 'image/gif',
        ]);

        $converter = app(MediaWebpConverter::class);

        $this->assertSame(ConversionResult::STATUS_SKIPPED, $converter->convert($svg)->status);
        $this->assertSame(ConversionResult::STATUS_SKIPPED, $converter->convert($gif)->status);
    }

    public function test_dry_run_does_not_modify_disk_or_db(): void
    {
        $bytes = $this->makeJpegBytes();
        $media = $this->persistMedia('public', 'photo.jpg', $bytes, [
            'file_name' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
        ]);

        $result = app(MediaWebpConverter::class)->convert($media, dryRun: true);

        $this->assertSame(ConversionResult::STATUS_CONVERTED, $result->status);
        $this->assertSame(strlen($bytes), $result->oldSize);
        $this->assertSame(0, $result->newSize);

        $media->refresh();
        $this->assertSame('photo.jpg', $media->file_name);
        $this->assertSame('image/jpeg', $media->mime_type);

        $disk = Storage::disk('public');
        $this->assertTrue($disk->exists($media->id.'/photo.jpg'));
        $this->assertFalse($disk->exists($media->id.'/photo.webp'));
    }

    public function test_throws_when_source_file_missing_on_disk(): void
    {
        $media = $this->persistMedia('public', 'photo.jpg', $this->makeJpegBytes(), [
            'file_name' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
        ]);

        Storage::disk('public')->delete($media->id.'/photo.jpg');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("File not found on disk 'public'");

        app(MediaWebpConverter::class)->convert($media);
    }

    public function test_extensionless_file_name_is_handled(): void
    {
        $disk = Storage::disk('public');

        /** @var Media $media */
        $media = Media::query()->create([
            'model_type' => 'TestModel',
            'model_id' => 1,
            'collection_name' => 'default',
            'name' => 'photo',
            'file_name' => 'photo',
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
            'size' => 0,
            'manipulations' => [],
            'custom_properties' => [],
            'generated_conversions' => [],
            'responsive_images' => [],
        ]);

        $bytes = $this->makeJpegBytes();
        $disk->put($media->id.'/photo', $bytes);
        $media->size = strlen($bytes);
        $media->saveQuietly();

        $result = app(MediaWebpConverter::class)->convert($media);

        $this->assertSame(ConversionResult::STATUS_CONVERTED, $result->status);

        $media->refresh();
        $this->assertSame('photo.webp', $media->file_name);
        $this->assertSame('image/webp', $media->mime_type);

        $this->assertTrue($disk->exists($media->id.'/photo.webp'));
        $this->assertFalse($disk->exists($media->id.'/photo'));
    }

    public function test_old_file_is_deleted_after_conversion(): void
    {
        $media = $this->persistMedia('public', 'photo.jpg', $this->makeJpegBytes(), [
            'file_name' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
        ]);

        $disk = Storage::disk('public');
        $this->assertTrue($disk->exists($media->id.'/photo.jpg'));

        app(MediaWebpConverter::class)->convert($media);

        $this->assertFalse($disk->exists($media->id.'/photo.jpg'));
        $this->assertTrue($disk->exists($media->id.'/photo.webp'));
    }

    public function test_byte_savings_are_reported_correctly(): void
    {
        $media = $this->persistMedia('public', 'photo.jpg', $this->makeJpegBytes(), [
            'file_name' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
        ]);

        $disk = Storage::disk('public');
        $oldSizeOnDisk = $disk->size($media->id.'/photo.jpg');

        $result = app(MediaWebpConverter::class)->convert($media);

        $this->assertSame($oldSizeOnDisk, $result->oldSize);
        $this->assertSame($disk->size($media->id.'/photo.webp'), $result->newSize);
    }
}
