<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use App\Models\LinkDailyRollup;

/**
 * Hasil agregasi satu hari untuk satu link. Immutable value object.
 *
 * `total = human + bots` adalah invariant, bukan kebetulan — ada test yang menjaganya.
 */
final readonly class RollupResult
{
    /**
     * @param  array<string, int>  $byDevice
     * @param  array<string, int>  $byBrowser
     * @param  array<string, int>  $byOs
     * @param  array<string, int>  $byReferrer
     */
    public function __construct(
        public int $total,
        public int $human,
        public int $bots,
        public int $uniqueVisitors,
        public array $byDevice,
        public array $byBrowser,
        public array $byOs,
        public array $byReferrer,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'human' => $this->human,
            'bots' => $this->bots,
            'unique_visitors' => $this->uniqueVisitors,
            'by_device' => $this->byDevice,
            'by_browser' => $this->byBrowser,
            'by_os' => $this->byOs,
            'by_referrer' => $this->byReferrer,
        ];
    }

    public function isEmpty(): bool
    {
        return $this->total === 0;
    }

    /** Jumlah kunci dimensi (untuk assertion & logging). */
    public function dimensionKeyCount(): int
    {
        return count($this->byDevice) + count($this->byBrowser)
            + count($this->byOs) + count($this->byReferrer);
    }

    /** Batas kunci per dimensi, diambil dari model supaya tidak ada dua sumber. */
    public static function maxKeys(): int
    {
        return LinkDailyRollup::MAX_DIMENSION_KEYS;
    }
}
