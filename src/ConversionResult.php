<?php

declare(strict_types=1);

namespace Weldist\Spatie\MediaLibrary\WebpDownloader;

final readonly class ConversionResult
{
    public const STATUS_CONVERTED = 'converted';

    public const STATUS_SKIPPED = 'skipped';

    public function __construct(
        public string $status,
        public int $oldSize = 0,
        public int $newSize = 0,
    ) {}

    public static function skipped(): self
    {
        return new self(self::STATUS_SKIPPED);
    }

    public static function converted(int $oldSize, int $newSize): self
    {
        return new self(self::STATUS_CONVERTED, $oldSize, $newSize);
    }
}
