<?php

use App\Models\Link;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('links', function (Blueprint $table) {
            $table->id();

            // Kepemilikan. `user_id` TIDAK PERNAH diambil dari request —
            // selalu dari sesi terautentikasi (spec §10).
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
             * Kode pendek, dijaga UNIQUE index.
             * Char(7) base62 ≈ 3,5×10^12 ruang kunci. Tabrakan diselesaikan lewat
             * index + retry (lihat Link::createWithUniqueCode), BUKAN cek-then-insert.
             * Kolom dipaksa binary-collation supaya 'Abc1234' dan 'abc1234' tidak
             * dianggap sama lalu menabrak index secara mengejutkan.
             */
            $table->string('code', Link::CODE_LENGTH)
                ->collation('ascii_bin')
                ->unique();

            $table->text('destination');

            $table->boolean('is_active')->default(true);

            /*
             * Counter DENORMALIZED. Ada supaya daftar link tidak perlu COUNT(*)
             * per baris. Konsekuensi: bisa melenceng dari click_events — dan
             * itulah alasan perintah reconcile ada (spec §5.2).
             * BIGINT UNSIGNED: INT biasa penuh di ~2,1 miliar.
             */
            $table->unsignedBigInteger('total_clicks')->default(0);

            $table->timestamp('expires_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Daftar link per user, diurutkan terbaru.
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('links');
    }
};
