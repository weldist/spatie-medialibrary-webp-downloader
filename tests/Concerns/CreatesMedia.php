<?php

declare(strict_types=1);

namespace Weldist\Spatie\MediaLibrary\WebpDownloader\Tests\Concerns;

use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

trait CreatesMedia
{
    protected function makeJpegBytes(int $size = 40, int $r = 200, int $g = 50, int $b = 50): string
    {
        $img = imagecreatetruecolor($size, $size);
        imagefilledrectangle($img, 0, 0, $size - 1, $size - 1, imagecolorallocate($img, $r, $g, $b));

        ob_start();
        imagejpeg($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    protected function makePngBytes(int $size = 40): string
    {
        $img = imagecreatetruecolor($size, $size);
        imagefilledrectangle($img, 0, 0, $size - 1, $size - 1, imagecolorallocate($img, 50, 200, 50));

        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    protected function makeWebpBytes(int $size = 40): string
    {
        $img = imagecreatetruecolor($size, $size);
        imagefilledrectangle($img, 0, 0, $size - 1, $size - 1, imagecolorallocate($img, 100, 100, 100));

        ob_start();
        imagewebp($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function persistMedia(string $diskName, string $relativePath, string $bytes, array $overrides = []): Media
    {
        $disk = Storage::disk($diskName);

        $defaults = [
            'model_type' => 'TestModel',
            'model_id' => 1,
            'collection_name' => 'default',
            'name' => pathinfo($relativePath, PATHINFO_FILENAME),
            'file_name' => basename($relativePath),
            'mime_type' => 'image/jpeg',
            'disk' => $diskName,
            'size' => strlen($bytes),
            'manipulations' => [],
            'custom_properties' => [],
            'generated_conversions' => [],
            'responsive_images' => [],
        ];

        /** @var Media $media */
        $media = Media::query()->create(array_merge($defaults, $overrides));

        $disk->put($media->id.'/'.$media->file_name, $bytes);

        return $media;
    }
}
