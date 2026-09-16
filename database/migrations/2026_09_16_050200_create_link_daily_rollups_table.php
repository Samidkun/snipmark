<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hasil agregasi harian. UNIQUE(link_id, date) adalah yang membuat rollup
 * IDEMPOTEN: menjalankan perintah rollup berkali-kali tidak menambah baris,
 * hanya menimpa nilai yang sama (spec §5.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_daily_rollups', function (Blueprint $table) {
            $table->id();

            $table->foreignId('link_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->date('date');

            // total = semua klik termasuk bot. human + bots = total.
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('human')->default(0);
            $table->unsignedInteger('bots')->default(0);

            // Distinct visitor_hash pada hari itu (hanya manusia).
            $table->unsignedInteger('unique_visitors')->default(0);

            // Dimensi siap-saji: {"chrome": 12, ...} maks 25 kunci + "(other)".
            $table->json('by_device');
            $table->json('by_browser');
            $table->json('by_os');
            $table->json('by_referrer');

            $table->dateTime('computed_at');

            $table->timestamps();

            // Penegak idempotensi. Bukan sekadar index pencarian.
            $table->unique(['link_id', 'date']);

            // Rekap lintas link per hari.
            $table->index(['date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_daily_rollups');
    }
};
