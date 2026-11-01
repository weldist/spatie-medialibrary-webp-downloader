<?php

declare(strict_types=1);

namespace Weldist\Spatie\MediaLibrary\WebpDownloader\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Weldist\Spatie\MediaLibrary\WebpDownloader\Tests\Concerns\CreatesMedia;
use Weldist\Spatie\MediaLibrary\WebpDownloader\Tests\TestCase;

class MacroTestPost extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'posts';

    protected $guarded = [];
}

class UsingWebpExtensionMacroTest extends TestCase
{
    use CreatesMedia;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });
    }

    private function temporaryFile(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'macro-test');
        file_put_contents($path, $bytes);

        return $path;
    }

    public function test_names_a_webp_file_webp_before_it_is_written(): void
    {
        $post = MacroTestPost::query()->create();

        $media = $post->addMedia($this->temporaryFile($this->makeWebpBytes()))
            ->usingFileName('photo.jpg')
            ->usingWebpExtension()
            ->toMediaCollection();

        $this->assertSame('photo.webp', $media->file_name);
        $this->assertTrue(Storage::disk('public')->exists($media->id.'/photo.webp'));
        $this->assertFalse(Storage::disk('public')->exists($media->id.'/photo.jpg'));
    }

    public function test_appends_webp_to_an_extensionless_name(): void
    {
        $post = MacroTestPost::query()->create();

        $media = $post->addMedia($this->temporaryFile($this->makeWebpBytes()))
            ->usingFileName('photo')
            ->usingWebpExtension()
            ->toMediaCollection();

        $this->assertSame('photo.webp', $media->file_name);
        $this->assertTrue(Storage::disk('public')->exists($media->id.'/photo.webp'));
    }

    public function test_keeps_the_name_of_a_file_that_is_not_webp(): void
    {
        $post = MacroTestPost::query()->create();

        $media = $post->addMedia($this->temporaryFile($this->makeJpegBytes()))
            ->usingFileName('photo.jpg')
            ->usingWebpExtension()
            ->toMediaCollection();

        $this->assertSame('photo.jpg', $media->file_name);
        $this->assertTrue(Storage::disk('public')->exists($media->id.'/photo.jpg'));
    }

    public function test_keeps_a_name_that_already_ends_with_webp(): void
    {
        $post = MacroTestPost::query()->create();

        $media = $post->addMedia($this->temporaryFile($this->makeWebpBytes()))
            ->usingFileName('photo.WEBP')
            ->usingWebpExtension()
            ->toMediaCollection();

        $this->assertSame('photo.WEBP', $media->file_name);
    }
}
