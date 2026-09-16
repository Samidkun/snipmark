<?php

namespace Tests\Feature;

use App\Models\Link;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Membuktikan properti yang diklaim di komentar Link: tabrakan kode diserap
 * lewat retry, dan UNIQUE index adalah penjaga sesungguhnya.
 *
 * Kenapa ini perlu: klaim "race-safe" tanpa test hanyalah komentar. Test ini
 * memaksa Str::random mengembalikan kode yang sudah terpakai, lalu memastikan
 * sistem pulih — bukan menghasilkan HTTP 500.
 */
final class LinkCodeGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // WAJIB: jangan biarkan generator acak deterministik merembes ke test lain.
        Str::createRandomStringsUsing(null);
        parent::tearDown();
    }

    public function test_kode_selalu_panjang_7_dan_huruf_kecil_alfanumerik(): void
    {
        $link = Link::createWithUniqueCode([
            'user_id' => User::factory()->create()->id,
            'destination' => 'https://example.com',
        ]);

        self::assertSame(7, strlen($link->code));
        self::assertMatchesRegularExpression('/^[a-z0-9]{7}$/', $link->code);
    }

    /**
     * Tabrakan kode HARUS diserap: percobaan pertama menabrak index, percobaan
     * kedua berhasil. Yang diuji bukan hanya "tidak error", tapi "kode akhirnya
     * berbeda dari yang bertabrakan".
     */
    public function test_tabrakan_kode_diserap_dengan_retry(): void
    {
        $user = User::factory()->create();

        // Kode yang sudah dipakai.
        $existing = Link::createWithUniqueCode([
            'user_id' => $user->id,
            'destination' => 'https://example.com/lama',
        ]);

        $taken = $existing->code;

        // Paksa 2 percobaan pertama menghasilkan kode yang sudah terpakai.
        Str::createRandomStringsUsingSequence([
            $taken,
            $taken,
            'fresh01',
        ]);

        $baru = Link::createWithUniqueCode([
            'user_id' => $user->id,
            'destination' => 'https://example.com/baru',
        ]);

        self::assertSame('fresh01', $baru->code, 'retry tidak memakai kode berikutnya');
        self::assertSame(2, Link::count(), 'link ganda tercipta');
    }

    /**
     * Bukti bahwa index-lah penjaganya: dua Link dengan kode sama HARUS ditolak
     * database. Kalau ini tidak ditolak, seluruh asumsi retry di atas salah.
     */
    public function test_unique_index_menolak_kode_ganda(): void
    {
        $user = User::factory()->create();

        Link::createWithUniqueCode([
            'user_id' => $user->id,
            'destination' => 'https://example.com/satu',
        ]);

        $kode = Link::first()->code;

        $this->expectException(QueryException::class);

        DB::table('links')->insert([
            'user_id' => $user->id,
            'code' => $kode,
            'destination' => 'https://example.com/dua',
            'is_active' => true,
            'total_clicks' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Percobaan habis harus melempar, bukan mengembalikan kode yang bertabrakan. */
    public function test_percobaan_habis_melempar_daripada_mengembalikan_kode_bentrok(): void
    {
        $user = User::factory()->create();

        $existing = Link::createWithUniqueCode([
            'user_id' => $user->id,
            'destination' => 'https://example.com/lama',
        ]);

        // Selalu mengembalikan kode yang sudah terpakai.
        Str::createRandomStringsUsing(fn () => $existing->code);

        $this->expectException(\RuntimeException::class);

        Link::createWithUniqueCode([
            'user_id' => $user->id,
            'destination' => 'https://example.com/baru',
        ], maxAttempts: 3);
    }

    /**
     * Kasus batas: dua user berbeda, kode sama. UNIQUE bersifat GLOBAL pada kolom
     * `code`, bukan per-user — karena URL pendek tidak punya konteks user saat
     * dibuka. Salah desain di sini berarti tautan saling menimpa.
     */
    public function test_kode_unik_secara_global_bukan_per_user(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();

        Link::createWithUniqueCode(['user_id' => $a->id, 'destination' => 'https://a.test']);
        $kode = Link::first()->code;

        Str::createRandomStringsUsingSequence([$kode, 'lain123']);

        $kedua = Link::createWithUniqueCode(['user_id' => $b->id, 'destination' => 'https://b.test']);

        self::assertSame('lain123', $kedua->code);
    }

    /** Kode yang sudah di-soft-delete TIDAK boleh dipakai ulang. */
    public function test_kode_link_terhapus_tidak_dipakai_ulang(): void
    {
        $user = User::factory()->create();

        $link = Link::createWithUniqueCode([
            'user_id' => $user->id,
            'destination' => 'https://example.com',
        ]);
        $kode = $link->code;

        $link->delete(); // soft delete

        // Baris masih ada di tabel, jadi index tetap menolak kode yang sama.
        Str::createRandomStringsUsingSequence([$kode, 'lain456']);

        $baru = Link::createWithUniqueCode([
            'user_id' => $user->id,
            'destination' => 'https://example.com/baru',
        ]);

        self::assertSame('lain456', $baru->code,
            'kode link ter-soft-delete dipakai ulang — tautan lama akan hidup kembali');
    }
}
