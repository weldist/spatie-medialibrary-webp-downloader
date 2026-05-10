<?php

declare(strict_types=1);

namespace Weldist\Spatie\MediaLibrary\WebpDownloader\Tests\Feature;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Weldist\Spatie\MediaLibrary\WebpDownloader\Jobs\ConvertMediaToWebpJob;
use Weldist\Spatie\MediaLibrary\WebpDownloader\Tests\Concerns\CreatesMedia;
use Weldist\Spatie\MediaLibrary\WebpDownloader\Tests\TestCase;

class ConvertExistingMediaToWebpCommandTest extends TestCase
{
    use CreatesMedia;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_returns_success_when_no_matching_rows(): void
    {
        $exitCode = Artisan::call('media-library:webp-convert');

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('No matching media rows found', Artisan::output());
    }

    public function test_converts_all_matching_rows(): void
    {
        $bytes = $this->makeJpegBytes();
        $a = $this->persistMedia('public', 'a.jpg', $bytes, ['file_name' => 'a.jpg', 'mime_type' => 'image/jpeg']);
        $b = $this->persistMedia('public', 'b.jpg', $bytes, ['file_name' => 'b.jpg', 'mime_type' => 'image/jpeg']);

        $exitCode = Artisan::call('media-library:webp-convert');

        $this->assertSame(Command::SUCCESS, $exitCode);

        $this->assertSame('image/webp', $a->fresh()->mime_type);
        $this->assertSame('image/webp', $b->fresh()->mime_type);

        $disk = Storage::disk('public');
        $this->assertTrue($disk->exists($a->id.'/a.webp'));
        $this->assertTrue($disk->exists($b->id.'/b.webp'));
    }

    public function test_filters_by_collection(): void
    {
        $bytes = $this->makeJpegBytes();
        $banner = $this->persistMedia('public', 'banner.jpg', $bytes, [
            'file_name' => 'banner.jpg', 'mime_type' => 'image/jpeg', 'collection_name' => 'banners',
        ]);
        $avatar = $this->persistMedia('public', 'avatar.jpg', $bytes, [
            'file_name' => 'avatar.jpg', 'mime_type' => 'image/jpeg', 'collection_name' => 'avatars',
        ]);

        Artisan::call('media-library:webp-convert', ['--collection' => ['banners']]);

        $this->assertSame('image/webp', $banner->fresh()->mime_type);
        $this->assertSame('image/jpeg', $avatar->fresh()->mime_type);
    }

    public function test_filters_by_model(): void
    {
        $bytes = $this->makeJpegBytes();
        $post = $this->persistMedia('public', 'post.jpg', $bytes, [
            'file_name' => 'post.jpg', 'mime_type' => 'image/jpeg', 'model_type' => 'App\\Models\\Post',
        ]);
        $user = $this->persistMedia('public', 'user.jpg', $bytes, [
            'file_name' => 'user.jpg', 'mime_type' => 'image/jpeg', 'model_type' => 'App\\Models\\User',
        ]);

        Artisan::call('media-library:webp-convert', ['--model' => ['App\\Models\\Post']]);

        $this->assertSame('image/webp', $post->fresh()->mime_type);
        $this->assertSame('image/jpeg', $user->fresh()->mime_type);
    }

    public function test_filters_by_id_range(): void
    {
        $bytes = $this->makeJpegBytes();
        $a = $this->persistMedia('public', 'a.jpg', $bytes, ['file_name' => 'a.jpg', 'mime_type' => 'image/jpeg']);
        $b = $this->persistMedia('public', 'b.jpg', $bytes, ['file_name' => 'b.jpg', 'mime_type' => 'image/jpeg']);
        $c = $this->persistMedia('public', 'c.jpg', $bytes, ['file_name' => 'c.jpg', 'mime_type' => 'image/jpeg']);

        Artisan::call('media-library:webp-convert', [
            '--id-from' => (string) $b->id,
            '--id-to' => (string) $b->id,
        ]);

        $this->assertSame('image/jpeg', $a->fresh()->mime_type);
        $this->assertSame('image/webp', $b->fresh()->mime_type);
        $this->assertSame('image/jpeg', $c->fresh()->mime_type);
    }

    public function test_filters_by_since(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));
        $bytes = $this->makeJpegBytes();
        $old = $this->persistMedia('public', 'old.jpg', $bytes, ['file_name' => 'old.jpg', 'mime_type' => 'image/jpeg']);

        Carbon::setTestNow(Carbon::parse('2026-06-01 00:00:00'));
        $fresh = $this->persistMedia('public', 'fresh.jpg', $bytes, ['file_name' => 'fresh.jpg', 'mime_type' => 'image/jpeg']);

        Carbon::setTestNow();

        Artisan::call('media-library:webp-convert', ['--since' => '2026-03-01']);

        $this->assertSame('image/jpeg', $old->fresh()->mime_type);
        $this->assertSame('image/webp', $fresh->fresh()->mime_type);
    }

    public function test_dry_run_does_not_modify_media(): void
    {
        $bytes = $this->makeJpegBytes();
        $media = $this->persistMedia('public', 'photo.jpg', $bytes, ['file_name' => 'photo.jpg', 'mime_type' => 'image/jpeg']);

        Artisan::call('media-library:webp-convert', ['--dry-run' => true]);

        $this->assertSame('image/jpeg', $media->fresh()->mime_type);
        $this->assertTrue(Storage::disk('public')->exists($media->id.'/photo.jpg'));
    }

    public function test_queue_mode_dispatches_one_job_per_row(): void
    {
        Queue::fake();

        $bytes = $this->makeJpegBytes();
        $a = $this->persistMedia('public', 'a.jpg', $bytes, ['file_name' => 'a.jpg', 'mime_type' => 'image/jpeg']);
        $b = $this->persistMedia('public', 'b.jpg', $bytes, ['file_name' => 'b.jpg', 'mime_type' => 'image/jpeg']);

        Artisan::call('media-library:webp-convert', ['--queue' => true]);

        Queue::assertPushed(ConvertMediaToWebpJob::class, 2);
        Queue::assertPushed(ConvertMediaToWebpJob::class, fn (ConvertMediaToWebpJob $job) => $job->mediaId === $a->id);
        Queue::assertPushed(ConvertMediaToWebpJob::class, fn (ConvertMediaToWebpJob $job) => $job->mediaId === $b->id);

        $this->assertSame('image/jpeg', $a->fresh()->mime_type);
        $this->assertSame('image/jpeg', $b->fresh()->mime_type);
    }

    public function test_queue_mode_with_dry_run_does_not_dispatch(): void
    {
        Queue::fake();

        $this->persistMedia('public', 'photo.jpg', $this->makeJpegBytes(), [
            'file_name' => 'photo.jpg', 'mime_type' => 'image/jpeg',
        ]);

        Artisan::call('media-library:webp-convert', ['--queue' => true, '--dry-run' => true]);

        Queue::assertNothingPushed();
    }

    public function test_queue_mode_honours_connection_and_queue_name(): void
    {
        Queue::fake();

        $this->persistMedia('public', 'photo.jpg', $this->makeJpegBytes(), [
            'file_name' => 'photo.jpg', 'mime_type' => 'image/jpeg',
        ]);

        Artisan::call('media-library:webp-convert', [
            '--queue' => true,
            '--queue-connection' => 'redis',
            '--queue-name' => 'images',
        ]);

        Queue::assertPushedOn('images', ConvertMediaToWebpJob::class);
        Queue::assertPushed(ConvertMediaToWebpJob::class, fn (ConvertMediaToWebpJob $job) => $job->connection === 'redis');
    }

    public function test_failed_conversion_returns_failure_exit_code(): void
    {
        $bytes = $this->makeJpegBytes();
        $broken = $this->persistMedia('public', 'broken.jpg', $bytes, ['file_name' => 'broken.jpg', 'mime_type' => 'image/jpeg']);
        $ok = $this->persistMedia('public', 'ok.jpg', $bytes, ['file_name' => 'ok.jpg', 'mime_type' => 'image/jpeg']);

        Storage::disk('public')->delete($broken->id.'/broken.jpg');

        $exitCode = Artisan::call('media-library:webp-convert');

        $this->assertSame(Command::FAILURE, $exitCode);

        $this->assertSame('image/jpeg', $broken->fresh()->mime_type);
        $this->assertSame('image/webp', $ok->fresh()->mime_type);
    }

    public function test_summary_reports_byte_savings(): void
    {
        $this->persistMedia('public', 'photo.jpg', $this->makeJpegBytes(), [
            'file_name' => 'photo.jpg', 'mime_type' => 'image/jpeg',
        ]);

        Artisan::call('media-library:webp-convert');

        $this->assertStringContainsString('saved', Artisan::output());
    }

    public function test_skipped_rows_count_in_summary(): void
    {
        $this->persistMedia('public', 'photo.webp', $this->makeWebpBytes(), [
            'file_name' => 'photo.webp', 'mime_type' => 'image/webp',
        ]);

        $exitCode = Artisan::call('media-library:webp-convert');

        $this->assertSame(Command::SUCCESS, $exitCode);

        $output = Artisan::output();
        $this->assertMatchesRegularExpression('/Skipped\s*\|\s*1/', $output);
    }
}
