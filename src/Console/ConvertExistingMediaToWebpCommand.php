<?php

declare(strict_types=1);

namespace Weldist\Spatie\MediaLibrary\WebpDownloader\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;
use Weldist\Spatie\MediaLibrary\WebpDownloader\ConversionResult;
use Weldist\Spatie\MediaLibrary\WebpDownloader\Jobs\ConvertMediaToWebpJob;
use Weldist\Spatie\MediaLibrary\WebpDownloader\MediaWebpConverter;

class ConvertExistingMediaToWebpCommand extends Command
{
    protected $signature = 'media-library:webp-convert
        {--collection=* : Limit to one or more media collection names}
        {--model=* : Limit to one or more model FQCNs}
        {--since= : Only include media created on or after this date (Y-m-d or full timestamp)}
        {--id-from= : Only include media with id >= this value}
        {--id-to= : Only include media with id <= this value}
        {--chunk=100 : Number of Media rows to process per database chunk}
        {--dry-run : Inspect what would change without modifying anything}
        {--queue : Dispatch a queued job per row instead of converting synchronously}
        {--queue-connection= : Queue connection to dispatch jobs on (requires --queue)}
        {--queue-name= : Queue name to dispatch jobs on (requires --queue)}';

    protected $description = 'Convert previously uploaded media files to WebP in place.';

    public function handle(MediaWebpConverter $converter): int
    {
        $mediaModel = config('media-library.media_model', Media::class);
        $dryRun = (bool) $this->option('dry-run');
        $useQueue = (bool) $this->option('queue');

        $query = $this->buildQuery($mediaModel);

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No matching media rows found.');

            return self::SUCCESS;
        }

        $verb = $useQueue ? 'Queueing' : 'Processing';
        $this->info("{$verb} {$total} media row(s)".($dryRun ? ' (dry run)' : '').'...');

        $stats = ['converted' => 0, 'skipped' => 0, 'queued' => 0, 'failed' => 0];
        $bytes = ['old' => 0, 'new' => 0];
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById((int) $this->option('chunk'), function ($chunk) use (&$stats, &$bytes, $bar, $dryRun, $useQueue, $converter): void {
            foreach ($chunk as $media) {
                try {
                    if ($useQueue && ! $dryRun) {
                        $this->dispatchJob($media->id);
                        $stats['queued']++;
                    } else {
                        $result = $converter->convert($media, $dryRun);
                        $stats[$result->status]++;
                        $bytes['old'] += $result->oldSize;
                        $bytes['new'] += $result->newSize;
                    }
                } catch (Throwable $e) {
                    $stats['failed']++;
                    $this->newLine();
                    $this->error("Media #{$media->id}: {$e->getMessage()}");
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $useQueue && ! $dryRun
            ? $this->renderQueueSummary($stats)
            : $this->renderSyncSummary($stats, $bytes, $dryRun);

        return $stats['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  class-string<Media>  $mediaModel
     */
    private function buildQuery(string $mediaModel): Builder
    {
        $query = $mediaModel::query();

        if ($collections = $this->option('collection')) {
            $query->whereIn('collection_name', $collections);
        }

        if ($models = $this->option('model')) {
            $query->whereIn('model_type', $models);
        }

        if ($since = $this->option('since')) {
            $query->where('created_at', '>=', $since);
        }

        if ($idFrom = $this->option('id-from')) {
            $query->where('id', '>=', (int) $idFrom);
        }

        if ($idTo = $this->option('id-to')) {
            $query->where('id', '<=', (int) $idTo);
        }

        return $query;
    }

    private function dispatchJob(int $mediaId): void
    {
        $job = new ConvertMediaToWebpJob($mediaId);

        if ($connection = $this->option('queue-connection')) {
            $job->onConnection($connection);
        }

        if ($queue = $this->option('queue-name')) {
            $job->onQueue($queue);
        }

        dispatch($job);
    }

    /**
     * @param  array{converted:int,skipped:int,queued:int,failed:int}  $stats
     * @param  array{old:int,new:int}  $bytes
     */
    private function renderSyncSummary(array $stats, array $bytes, bool $dryRun): void
    {
        $this->table(['Status', 'Count'], [
            ['Converted', $stats['converted']],
            ['Skipped', $stats['skipped']],
            ['Failed', $stats['failed']],
        ]);

        if ($stats['converted'] > 0 && ! $dryRun && $bytes['old'] > 0) {
            $saved = max(0, $bytes['old'] - $bytes['new']);
            $percent = $bytes['old'] > 0 ? round($saved / $bytes['old'] * 100, 1) : 0;

            $this->newLine();
            $this->info(sprintf(
                'Total: %s → %s (saved %s, %s%% smaller)',
                Number::fileSize($bytes['old']),
                Number::fileSize($bytes['new']),
                Number::fileSize($saved),
                $percent
            ));
        }

        if ($stats['converted'] > 0 && ! $dryRun) {
            $this->newLine();
            $this->warn('Existing conversions (thumbnails, responsive images) may be stale.');
            $this->line('Regenerate with: php artisan media-library:regenerate');
        }
    }

    /**
     * @param  array{converted:int,skipped:int,queued:int,failed:int}  $stats
     */
    private function renderQueueSummary(array $stats): void
    {
        $this->table(['Status', 'Count'], [
            ['Queued', $stats['queued']],
            ['Failed to dispatch', $stats['failed']],
        ]);

        $this->newLine();
        $this->line('Conversion will happen asynchronously in your queue workers.');
        $this->line('Monitor via Horizon or: php artisan queue:work');
        $this->line('After completion, regenerate conversions: php artisan media-library:regenerate');
    }
}
