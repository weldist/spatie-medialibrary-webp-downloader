<?php

declare(strict_types=1);

namespace Weldist\Spatie\MediaLibrary\WebpDownloader\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Weldist\Spatie\MediaLibrary\WebpDownloader\Tests\TestCase;
use Weldist\Spatie\MediaLibrary\WebpDownloader\WebpDownloader;

class WebpDownloaderTest extends TestCase
{
    /** @var array<int, string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            if (file_exists($path)) {
                @unlink($path);
            }
        }

        $this->cleanup = [];

        parent::tearDown();
    }

    public function test_jpeg_is_converted_to_webp(): void
    {
        $source = $this->trackForCleanup($this->makeJpeg());
        $beforeTemps = $this->mediaLibraryTempFiles();

        $result = $this->trackForCleanup(
            (new WebpDownloader())->getTempFile($this->fakeUrl($source))
        );

        $newTemps = array_values(array_diff($this->mediaLibraryTempFiles(), $beforeTemps));

        $this->assertFileExists($result);
        $this->assertSame('image/webp', mime_content_type($result));
        $this->assertStringStartsWith(
            'media-library-webp',
            basename($result),
            'Converted result must live under the media-library-webp* temp prefix'
        );
        $this->assertSame(
            [$result],
            $newTemps,
            'Intermediate parent temp file must be unlinked — only the WebP result should remain'
        );
    }

    public function test_png_is_converted_to_webp(): void
    {
        $source = $this->trackForCleanup($this->makePng());

        $result = $this->trackForCleanup(
            (new WebpDownloader())->getTempFile($this->fakeUrl($source))
        );

        $this->assertSame('image/webp', mime_content_type($result));
    }

    public function test_existing_webp_is_passed_through_unchanged(): void
    {
        $source = $this->trackForCleanup($this->makeWebp());
        $expectedBytes = file_get_contents($source);

        $result = $this->trackForCleanup(
            (new WebpDownloader())->getTempFile($this->fakeUrl($source))
        );

        $this->assertSame($expectedBytes, file_get_contents($result));
    }

    public function test_gif_is_passed_through_unchanged(): void
    {
        $source = $this->trackForCleanup($this->makeGif());
        $expectedBytes = file_get_contents($source);

        $result = $this->trackForCleanup(
            (new WebpDownloader())->getTempFile($this->fakeUrl($source))
        );

        $this->assertSame('image/gif', mime_content_type($result));
        $this->assertSame($expectedBytes, file_get_contents($result));
    }

    public function test_svg_is_passed_through_unchanged(): void
    {
        $source = $this->trackForCleanup($this->makeSvg());
        $expectedBytes = file_get_contents($source);

        $result = $this->trackForCleanup(
            (new WebpDownloader())->getTempFile($this->fakeUrl($source))
        );

        $this->assertSame($expectedBytes, file_get_contents($result));
    }

    public function test_pdf_is_passed_through_unchanged(): void
    {
        $source = $this->trackForCleanup($this->makePdf());
        $expectedBytes = file_get_contents($source);

        $result = $this->trackForCleanup(
            (new WebpDownloader())->getTempFile($this->fakeUrl($source))
        );

        $this->assertSame($expectedBytes, file_get_contents($result));
    }

    public function test_skip_mimes_argument_is_respected(): void
    {
        $source = $this->trackForCleanup($this->makeJpeg());
        $expectedBytes = file_get_contents($source);

        $result = $this->trackForCleanup(
            (new WebpDownloader(skipMimes: ['image/jpeg']))->getTempFile($this->fakeUrl($source))
        );

        $this->assertSame('image/jpeg', mime_content_type($result));
        $this->assertSame($expectedBytes, file_get_contents($result));
    }

    public function test_lower_quality_produces_smaller_file(): void
    {
        $highSource = $this->trackForCleanup($this->makeColorfulJpeg());
        $lowSource = $this->trackForCleanup($this->makeColorfulJpeg());

        $high = $this->trackForCleanup(
            (new WebpDownloader(quality: 95))->getTempFile($this->fakeUrl($highSource))
        );
        $low = $this->trackForCleanup(
            (new WebpDownloader(quality: 10))->getTempFile($this->fakeUrl($lowSource))
        );

        $this->assertLessThan(filesize($high), filesize($low));
    }

    private function makeJpeg(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fixture-jpg');
        $img = imagecreatetruecolor(40, 40);
        imagefilledrectangle($img, 0, 0, 39, 39, imagecolorallocate($img, 200, 50, 50));
        imagejpeg($img, $path);
        imagedestroy($img);

        return $path;
    }

    private function makeColorfulJpeg(): string
    {
        $size = 120;
        $path = tempnam(sys_get_temp_dir(), 'fixture-jpg');
        $img = imagecreatetruecolor($size, $size);

        for ($x = 0; $x < $size; $x++) {
            for ($y = 0; $y < $size; $y++) {
                imagesetpixel(
                    $img,
                    $x,
                    $y,
                    imagecolorallocate(
                        $img,
                        ($x * 7) % 256,
                        ($y * 13) % 256,
                        (($x + $y) * 5) % 256
                    )
                );
            }
        }

        imagejpeg($img, $path, 90);
        imagedestroy($img);

        return $path;
    }

    private function makePng(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fixture-png');
        $img = imagecreatetruecolor(40, 40);
        imagefilledrectangle($img, 0, 0, 39, 39, imagecolorallocate($img, 50, 200, 50));
        imagepng($img, $path);
        imagedestroy($img);

        return $path;
    }

    private function makeGif(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fixture-gif');
        $img = imagecreatetruecolor(40, 40);
        imagefilledrectangle($img, 0, 0, 39, 39, imagecolorallocate($img, 50, 50, 200));
        imagegif($img, $path);
        imagedestroy($img);

        return $path;
    }

    private function makeWebp(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fixture-webp');
        $img = imagecreatetruecolor(40, 40);
        imagefilledrectangle($img, 0, 0, 39, 39, imagecolorallocate($img, 100, 100, 100));
        imagewebp($img, $path);
        imagedestroy($img);

        return $path;
    }

    private function makeSvg(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fixture-svg');
        file_put_contents(
            $path,
            '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10" fill="red"/></svg>'
        );

        return $path;
    }

    private function makePdf(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fixture-pdf');
        file_put_contents(
            $path,
            "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\nxref\n0 1\n0000000000 65535 f \ntrailer<</Size 1/Root 1 0 R>>\nstartxref\n40\n%%EOF\n"
        );

        return $path;
    }

    private function fakeUrl(string $path): string
    {
        $url = 'https://example.test/'.basename($path);

        Http::fake([$url => Http::response(file_get_contents($path))]);

        return $url;
    }

    private function trackForCleanup(string $path): string
    {
        $this->cleanup[] = $path;

        return $path;
    }

    /**
     * @return array<int, string>
     */
    private function mediaLibraryTempFiles(): array
    {
        return glob(sys_get_temp_dir().'/media-library*') ?: [];
    }
}
