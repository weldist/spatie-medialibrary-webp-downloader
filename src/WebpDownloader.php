<?php

declare(strict_types=1);

namespace Weldist\Spatie\MediaLibrary\WebpDownloader;

use Illuminate\Support\Facades\Http;
use Spatie\Image\Image;
use Spatie\MediaLibrary\Downloaders\Downloader;
use Spatie\MediaLibrary\MediaCollections\Exceptions\UnreachableUrl;
use Throwable;

class WebpDownloader implements Downloader
{
    /**
     * @param  array<int, string>  $skipMimes  MIME types that bypass WebP conversion (passed through as-is).
     * @param  int  $timeout  Seconds the whole download may take.
     */
    public function __construct(
        private readonly int $quality = 85,
        private readonly array $skipMimes = ['image/svg+xml', 'image/gif'],
        private readonly int $timeout = 30,
    ) {}

    public function getTempFile(string $url): string
    {
        $temporaryFile = $this->download($url);

        if (! $this->shouldConvert($temporaryFile)) {
            return $temporaryFile;
        }

        try {
            return $this->convertToWebp($temporaryFile);
        } catch (Throwable $e) {
            @unlink($temporaryFile);
            throw $e;
        }
    }

    public function quality(): int
    {
        return $this->quality;
    }

    /**
     * @return array<int, string>
     */
    public function skipMimes(): array
    {
        return $this->skipMimes;
    }

    public function timeout(): int
    {
        return $this->timeout;
    }

    private function download(string $url): string
    {
        $temporaryFile = tempnam(sys_get_temp_dir(), 'media-library');

        try {
            Http::withUserAgent('Spatie MediaLibrary')
                ->withOptions(['verify' => (bool) config('media-library.media_downloader_ssl', true)])
                ->timeout($this->timeout)
                ->sink($temporaryFile)
                ->get($url)
                ->throw();
        } catch (Throwable) {
            @unlink($temporaryFile);

            throw UnreachableUrl::create($url);
        }

        return $temporaryFile;
    }

    private function shouldConvert(string $path): bool
    {
        $mime = @mime_content_type($path);

        if ($mime === false || ! str_starts_with($mime, 'image/')) {
            return false;
        }

        if ($mime === 'image/webp') {
            return false;
        }

        return ! in_array($mime, $this->skipMimes, true);
    }

    private function convertToWebp(string $sourcePath): string
    {
        $webpPath = tempnam(sys_get_temp_dir(), 'media-library-webp');

        try {
            Image::useImageDriver(config('media-library.image_driver', 'gd'))
                ->loadFile($sourcePath)
                ->format('webp')
                ->quality($this->quality)
                ->save($webpPath);
        } catch (Throwable $e) {
            @unlink($webpPath);
            throw $e;
        }

        @unlink($sourcePath);

        return $webpPath;
    }
}
