<?php

namespace App\Jobs;

use App\Models\ClickEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Menulis satu klik melalui queue.
 *
 * Ada supaya benchmark `snipmark:bench:click-logging` punya jalur pembanding yang
 * sungguhan — bukan jalur buatan. Ini juga jalur yang akan dipakai bila aplikasi
 * kelak punya Redis/queue worker sungguhan (spec §8).
 */
class LogClickJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(public array $attributes) {}

    public function handle(): void
    {
        ClickEvent::create($this->attributes);
    }
}
