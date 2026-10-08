# weldist/spatie-medialibrary-webp-downloader

[![Tests](https://github.com/weldist/spatie-medialibrary-webp-downloader/actions/workflows/tests.yml/badge.svg)](https://github.com/weldist/spatie-medialibrary-webp-downloader/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-blue)](https://www.php.net)
[![Laravel](https://img.shields.io/badge/Laravel-12%20|%2013-red)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE.md)

A WebP-converting `Downloader` for [spatie/laravel-medialibrary](https://github.com/spatie/laravel-medialibrary).

> A [weld.ist](https://weld.ist) project.
>
> Unofficial plugin. Not affiliated with Spatie.

## The Problem

spatie/laravel-medialibrary's `addMediaFromUrl()` (and friends) downloads a remote file and stores it byte-for-byte as it arrived:

```
addMediaFromUrl('https://example.com/photo.jpg')   →   42/photo.jpg   (the original 2 MB JPEG)
```

That is wasteful for the common case — user avatars, article hero images, product photos pulled from a supplier feed. WebP routinely shaves 25–35% off a JPEG and far more off a PNG at the same visual quality, but media-library exposes no hook to transcode on ingest:

- **Bandwidth & storage:** Every imported image is served and stored at its source format and size.
- **No ingest-time hook:** Conversions (`->withResponsiveImages()`, registered conversions) run *after* the original is already on disk — the original itself is never touched.
- **Manual workarounds are brittle:** Re-encoding in a model observer or a queued job means re-implementing media-library's path/disk resolution and racing its own pipeline.

## The Solution

This package ships a drop-in `Downloader` that re-encodes images to WebP *while they are being downloaded*, before media-library ever writes the original to disk:

```
addMediaFromUrl('https://example.com/photo.jpg')   →   42/photo.webp   (re-encoded, smaller)
```

- Implements Spatie's `Downloader` interface — you register it as the `media_downloader` in `config/media-library.php`.
- Downloads through Laravel's `Http` client with a total timeout and honours Spatie's `media_downloader_ssl` config. A slow or stalled server fails with `UnreachableUrl` instead of hanging the worker or leaving a truncated file behind.
- Re-encodes via `spatie/image` — the *same* library media-library uses for its own conversions — so there are zero new dependencies and the driver enum (`gd` / `imagick` / `vips`) is identical.
- Reads Spatie's existing `media-library.image_driver` config — no extra env var, no plugin config file.
- Non-image payloads (PDF, video, archives) and configured skip MIME types pass through untouched. SVG and animated GIF are skipped by default.
- A bundled, auto-discovered service provider keeps the `media` row's `file_name` extension in sync with the re-encoded file (see [Automatic File Name Correction](#automatic-file-name-correction)).

## Requirements

- PHP ^8.3
- Laravel ^12.0 | ^13.0
- `spatie/laravel-medialibrary ^11.0 | ^12.0`
- `spatie/image ^3.0` (already installed transitively by media-library)
- The PHP extension matching `media-library.image_driver` — `ext-gd`, `ext-imagick`, or libvips for `vips`

## Installation

```bash
composer require weldist/spatie-medialibrary-webp-downloader
```

The package auto-discovers a single service provider — the file-name correction listener described below. It does **not** bind the downloader for you; that is a one-line edit to your own config so you stay in control of whether it is active.

## Setup

Edit `config/media-library.php` and swap the downloader binding:

```php
use Weldist\Spatie\MediaLibrary\WebpDownloader\WebpDownloader;

return [
    // ...
    'media_downloader' => WebpDownloader::class,
    // ...
];
```

That's it. From now on every `addMediaFromUrl()` call passes through the WebP converter.

### Image Driver

The driver is chosen automatically from Spatie's existing config and forwarded to `Spatie\Image\Image::useImageDriver()`:

```php
// config/media-library.php
'image_driver' => env('IMAGE_DRIVER', 'gd'), // 'gd', 'imagick', or 'vips'
```

The required PHP extension (`ext-gd` / `ext-imagick`) — or libvips for `vips` — must be loaded.

### Tuning

`WebpDownloader` accepts three optional constructor arguments — all auto-resolved via the container with sensible defaults:

| Argument | Default | Meaning |
| --- | --- | --- |
| `quality` | `85` | WebP quality (0–100) |
| `skipMimes` | `['image/svg+xml', 'image/gif']` | MIME types that bypass conversion |
| `timeout` | `30` | Seconds the whole download may take |

To override, bind a custom instance in your own service provider:

```php
use Weldist\Spatie\MediaLibrary\WebpDownloader\WebpDownloader;

$this->app->bind(WebpDownloader::class, fn () => new WebpDownloader(
    quality: 90,
    skipMimes: ['image/svg+xml'],
));
```

> **Why is SVG always skipped, and GIF skipped by default?**
> Rasterising an SVG to WebP throws away the thing that makes it an SVG, so it is never converted. GD flattens animated GIFs to a single frame; drop `image/gif` from `skipMimes` only if you are on Imagick and want animated WebP output.

## Automatic File Name Correction

`WebpDownloaderServiceProvider` is auto-discovered. It listens for Spatie's `MediaHasBeenAddedEvent` — fired *after* the downloaded bytes land on disk — and, in lock-step, renames the on-disk file to `.webp` and updates the row's `file_name` via `saveQuietly()` when **all** of the following hold:

- The configured `media-library.media_downloader` is `WebpDownloader` (or a subclass)
- The persisted `mime_type` is `image/webp`
- The current `file_name` does not already end with `.webp` (case-insensitive)

So `obama.jpg` → `obama.webp` (basename preserved, extension swapped). Disk uploads of unrelated formats, and media added while a different downloader is configured, are never touched. The listener is idempotent — a re-fired event finds the source already renamed and the column already `.webp`, so it is a no-op.

> **Why a listener instead of `usingFileName(...)`?**
> media-library decides the on-disk name from the URL basename (`photo.jpg`) inside `FileAdder::processMediaItem`, *after* the model's `creating` event. Mutating `file_name` earlier leaves the row pointing at `.webp` while the file sits at `.jpg` → 404. Renaming after `MediaHasBeenAddedEvent` is the first point where both the file and the row can be fixed together.

To opt out, set `extra.laravel.dont-discover` in your root `composer.json` and rename manually with `->usingFileName(...)`.

## Converting Existing Media

For projects that already have thousands of JPEGs/PNGs on disk, the package ships an Artisan command — `media-library:webp-convert` — that walks the `media` table and re-encodes rows in place.

**1.** Preview first. A dry run reports which rows would be converted and touches nothing:

```bash
php artisan media-library:webp-convert --dry-run
```

**2.** Run the conversion. Sync mode for small libraries, `--queue` for large ones:

```bash
# Convert everything synchronously
php artisan media-library:webp-convert

# Dispatch one queued job per row (recommended for large libraries)
php artisan media-library:webp-convert --queue
php artisan media-library:webp-convert --queue --queue-connection=redis --queue-name=media
```

**3.** Regenerate conversions. Existing thumbnails and responsive images are now stale because their source changed — the command prints a reminder when it finishes:

```bash
php artisan media-library:regenerate
```

For each Media row the command:

1. Skips `image/webp`, non-image MIME types, and entries in the configured `WebpDownloader` `skipMimes`
2. Reads the file via the row's own `disk` and the `PathGenerator` resolved by Spatie (custom path generators are honoured automatically)
3. Re-encodes to WebP using `media-library.image_driver` and the quality from the container-bound `WebpDownloader` instance
4. Writes the new `.webp` file, then updates `file_name`, `mime_type`, and `size` via `saveQuietly()`, then deletes the original — in that order, so a crash never leaves a row pointing at a deleted file

It is safe to re-run: already-WebP rows hit the skip guard. Both modes support `--dry-run` (queue + dry-run just reports what would be dispatched).

Options:

| Option | Description |
|---|---|
| `--collection=` | Limit to one media collection |
| `--model=` | Limit to one owning model class |
| `--since=` | Only media added on or after this date |
| `--id-from=` / `--id-to=` | Restrict to an id range — designed for sharded parallel runs (two terminals split the range and dispatch independently) |
| `--chunk=` | `chunkById()` batch size for large tables |
| `--queue` | Dispatch one `ConvertMediaToWebpJob` per row instead of converting inline |
| `--queue-connection=` / `--queue-name=` | Target connection / queue for `--queue` |
| `--dry-run` | Report what would be converted (or dispatched) without changing anything |

```bash
# Scope examples
php artisan media-library:webp-convert --collection=images
php artisan media-library:webp-convert --model="App\Models\Post"
php artisan media-library:webp-convert --since=2026-04-01 --chunk=500

# Parallel workers splitting the id range
# Terminal A:
php artisan media-library:webp-convert --id-from=1 --id-to=50000 --queue
# Terminal B:
php artisan media-library:webp-convert --id-from=50001 --id-to=100000 --queue
```

## Testing

```bash
# Build first (once per PHP version)
DOCKER_BUILDKIT=0 docker compose --profile php83 build

# Run tests
docker compose --profile php83 up
docker compose --profile php84 up
docker compose --profile php85 up
```

## License

This package is open-sourced software licensed under the [MIT license](https://opensource.org/license/MIT).