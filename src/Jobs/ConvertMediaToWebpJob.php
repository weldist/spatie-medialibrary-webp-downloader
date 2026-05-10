<?php

declare(strict_types=1);

namespace Weldist\Spatie\MediaLibrary\WebpDownloader\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Weldist\Spatie\MediaLibrary\WebpDownloader\MediaWebpConverter;

class ConvertMediaToWebpJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $mediaId) {}

    public function handle(MediaWebpConverter $converter): void
    {
        $mediaModel = config('media-library.media_model', Media::class);
        $media = $mediaModel::find($this->mediaId);

        if ($media === null) {
            return;
        }

        $converter->convert($media);
    }
}
