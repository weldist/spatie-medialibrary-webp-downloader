<?php

declare(strict_types=1);

namespace Weldist\Spatie\MediaLibrary\WebpDownloader;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Weldist\Spatie\MediaLibrary\WebpDownloader\Console\ConvertExistingMediaToWebpCommand;

class WebpDownloaderServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerCommands();
        $this->registerFileNameAutoCorrection();
    }

    private function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            ConvertExistingMediaToWebpCommand::class,
        ]);
    }

    /**
     * Disk write only finishes inside Filesystem::add() (which runs *after*
     * the model save), and uses FileAdder::$fileName — not Media::$file_name —
     * for the on-disk name. So the file lands as e.g. `photo.jpg` even though
     * its bytes are WebP. We listen to MediaHasBeenAddedEvent (fired by Spatie
     * once the file is on disk) and rename both the on-disk file and the DB
     * column to `.webp` in lock-step.
     */
    private function registerFileNameAutoCorrection(): void
    {
        $this->app['events']->listen(
            MediaHasBeenAddedEvent::class,
            function (MediaHasBeenAddedEvent $event): void {
                $this->renameToWebp($event->media);
            }
        );
    }

    private function renameToWebp(Media $media): void
    {
        if (! $this->shouldRewriteFileName($media)) {
            return;
        }

        $oldFileName = (string) $media->file_name;
        $newFileName = pathinfo($oldFileName, PATHINFO_FILENAME).'.webp';

        $disk = Storage::disk($media->disk);

        $oldRelative = $media->getPathRelativeToRoot();
        $newRelative = $this->replaceBasename($oldRelative, $newFileName);

        // If a previous run already moved the file (idempotent re-fire) or the
        // disk never received the source for some reason, don't blow up — just
        // align the DB column with whatever .webp file is (or should be) there.
        if ($oldRelative !== $newRelative && $disk->exists($oldRelative)) {
            $this->safeMove($disk, $oldRelative, $newRelative);
        }

        $media->file_name = $newFileName;
        $media->saveQuietly();
    }

    private function shouldRewriteFileName(Media $media): bool
    {
        $downloader = config('media-library.media_downloader');

        if (! is_string($downloader) || ! is_a($downloader, WebpDownloader::class, true)) {
            return false;
        }

        if ($media->mime_type !== 'image/webp') {
            return false;
        }

        if (str_ends_with(strtolower((string) $media->file_name), '.webp')) {
            return false;
        }

        return true;
    }

    private function replaceBasename(string $relativePath, string $newBasename): string
    {
        $directory = pathinfo($relativePath, PATHINFO_DIRNAME);

        if ($directory === '' || $directory === '.') {
            return $newBasename;
        }

        return $directory.'/'.$newBasename;
    }

    private function safeMove(Filesystem $disk, string $from, string $to): void
    {
        // If the target already exists (shouldn't happen, but be defensive)
        // delete it so move() doesn't fail on drivers that don't overwrite.
        if ($disk->exists($to)) {
            $disk->delete($to);
        }

        $disk->move($from, $to);
    }
}