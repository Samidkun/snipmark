<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Hasil klasifikasi satu user-agent.
 *
 * Immutable dan tidak tahu apa-apa soal HTTP/DB — ini nilai, bukan layanan.
 * `category` harus cocok dengan enum `bot_category` di tabel click_events;
 * ada test yang menjaga keduanya tidak lepas sinkron.
 */
final readonly class BotVerdict
{
    public function __construct(
        public bool $isBot,
        public ?string $name = null,
        public ?string $category = null,
    ) {}

    public static function human(): self
    {
        return new self(false);
    }

    public static function bot(string $name, string $category): self
    {
        return new self(true, $name, $category);
    }

    /** Kategori yang sah, sinkron dengan enum skema (spec §4.2). */
    public static function categories(): array
    {
        return ['search', 'scraper', 'seo', 'monitor', 'chat_preview', 'feed', 'unknown'];
    }
}
