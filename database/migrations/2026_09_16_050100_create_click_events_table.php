<?php

use App\Support\BotVerdict;
use App\Support\ParsedUserAgent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel mentah: satu baris per kunjungan. APPEND-ONLY (tidak pernah di-UPDATE).
 *
 * Kontrak privasi: TIDAK ADA kolom IP. Identitas pengunjung hanya `visitor_hash`.
 * `user_agent` mentah DISIMPAN (keputusan user) agar riwayat bisa diparse ulang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('click_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('link_id')
                ->constrained()
                ->cascadeOnDelete();

            // Waktu presisi milidetik, disimpan UTC.
            $table->dateTime('occurred_at', precision: 3);

            /*
             * AMANDEMEN SKEMA (bukan di spec awal, ditemukan saat mendesain rollup):
             * tanggal versi zona analitik (Asia/Jakarta), TERSIMPAN dan TER-INDEX.
             *
             * Tanpa kolom ini, rollup harus `GROUP BY DATE(occurred_at)`. Fungsi di
             * atas kolom membuat MariaDB tidak bisa memakai index -> full scan +
             * filesort pada tabel yang memang ditumbuhkan untuk jutaan baris.
             * Kesalahan ini tidak terlihat di database kecil, dan baru muncul
             * sebagai "dashboard lambat" saat sudah terlambat.
             */
            $table->date('occurred_on');

            // HMAC(ip|tanggal, APP_KEY). Bukan IP. Berotasi harian (ADR-0002).
            $table->char('visitor_hash', 64);

            $table->string('referrer_host', 255)->nullable();

            $table->enum('device_type', ParsedUserAgent::DEVICES)
                ->default('other');

            $table->string('browser_family', 32)->default('other');
            $table->string('os_family', 32)->default('other');

            $table->boolean('is_bot')->default(false);
            $table->string('bot_name', 32)->nullable();

            /*
             * Enum nilai kategori HARUS sama dengan BotVerdict::categories().
             * Ada test yang gagal bila keduanya lepas sinkron — kalau tidak,
             * selisihnya baru muncul sebagai error insert di produksi.
             */
            $table->enum('bot_category', BotVerdict::categories())->nullable();

            $table->enum('source', ['web', 'curl', 'sdk', 'api'])->default('web');

            /*
             * UA mentah, dipotong 512 char (spec §4.4). Ada supaya bug parser bisa
             * dipulihkan dengan menghitung ulang riwayat. Tidak pernah ditampilkan
             * di UI, tidak pernah diekspor.
             */
            $table->string('user_agent', 512)->nullable();

            // Hanya created_at; tabel append-only tidak punya updated_at.
            $table->timestamp('created_at')->nullable();

            // Index utama rollup harian per link: (link_id, occurred_on).
            $table->index(['link_id', 'occurred_on']);

            // Sweep lintas link untuk satu hari (dipakai rollup semua-link).
            $table->index(['occurred_on']);

            // Dedup / pembersihan: cari event duplikat pada link yang sama.
            $table->index(['link_id', 'visitor_hash', 'occurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('click_events');
    }
};
