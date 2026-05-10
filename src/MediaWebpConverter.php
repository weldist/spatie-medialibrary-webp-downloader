<?php

declare(strict_types=1);

namespace Weldist\Spatie\MediaLibrary\WebpDownloader;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Image\Image;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MediaWebpConverter
{
    public function __construct(
        private readonly WebpDownloader $downloader,
    ) {}

    public function convert(Media $media, bool $dryRun = false): ConversionResult
    {
        if (! $this->isConvertible($media)) {
            return ConversionResult::skipped();
        }

        $disk = Storage::disk($media->disk);
        $relativePath = $media->getPathRelativeToRoot();

        if (! $disk->exists($relativePath)) {
            throw new RuntimeException("File not found on disk '{$media->disk}': {$relativePath}");
        }

        $oldSize = $disk->size($relativePath);

        if ($dryRun) {
            return ConversionResult::converted($oldSize, 0);
        }

        $newSize = $this->writeWebp($media, $disk, $relativePath);

        return ConversionResult::converted($oldSize, $newSize);
    }

    private function isConvertible(Media $media): bool
    {
        if (! str_starts_with((string) $media->mime_type, 'image/')) {
            return false;
        }

        if ($media->mime_type === 'image/webp') {
            return false;
        }

        return ! in_array($media->mime_type, $this->downloader->skipMimes(), true);
    }

    private function writeWebp(Media $media, Filesystem $disk, string $relativePath): int
    {
        $sourceTemp = tempnam(sys_get_temp_dir(), 'media-library-webp-src');
        $webpTemp = tempnam(sys_get_temp_dir(), 'media-library-webp-out');

        try {
            $this->streamDiskToFile($disk, $relativePath, $sourceTemp);

            Image::useImageDriver($this->imageDriver())
                ->loadFile($sourceTemp)
                ->format('webp')
                ->quality($this->downloader->quality())
                ->save($webpTemp);

            $newRelativePath = $this->buildWebpPath($relativePath);

            $this->streamFileToDisk($disk, $newRelativePath, $webpTemp);

            $newSize = $disk->size($newRelativePath);

            $media->file_name = pathinfo((string) $media->file_name, PATHINFO_FILENAME).'.webp';
            $media->mime_type = 'image/webp';
            $media->size = $newSize;
            $media->saveQuietly();

            if ($newRelativePath !== $relativePath) {
                $disk->delete($relativePath);
            }

            return $newSize;
        } finally {
            @unlink($sourceTemp);
            @unlink($webpTemp);
        }
    }

    private function streamDiskToFile(Filesystem $disk, string $sourcePath, string $localFile): void
    {
        $remote = $disk->readStream($sourcePath);

        if (! is_resource($remote)) {
            throw new RuntimeException("Failed to open read stream for: {$sourcePath}");
        }

        $local = fopen($localFile, 'w');

        try {
            stream_copy_to_stream($remote, $local);
        } finally {
            if (is_resource($local)) {
                fclose($local);
            }
            if (is_resource($remote)) {
                fclose($remote);
            }
        }
    }

    private function streamFileToDisk(Filesystem $disk, string $targetPath, string $localFile): void
    {
        $stream = fopen($localFile, 'r');

        try {
            $disk->put($targetPath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function buildWebpPath(string $relativePath): string
    {
        $directory = pathinfo($relativePath, PATHINFO_DIRNAME);
        $filename = pathinfo($relativePath, PATHINFO_FILENAME);
        $prefix = ($directory === '.' || $directory === '') ? '' : rtrim($directory, '/').'/';

        return $prefix.$filename.'.webp';
    }

    private function imageDriver(): string
    {
        return (string) config('media-library.image_driver', 'gd');
    }
}
