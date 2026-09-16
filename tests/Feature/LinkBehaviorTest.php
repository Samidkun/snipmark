<?php

namespace Tests\Feature;

use App\Models\ClickEvent;
use App\Models\Link;
use App\Models\LinkDailyRollup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Perilaku Link: kelayakan-redirect, relasi, dan batas kolom.
 *
 * Ditulis SETELAH mutation-check menemukan bahwa `isReachable()` tidak punya test
 * sama sekali (2 mutasi lolos). Itu pelanggaran IRON LAW — kode produksi tanpa
 * test gagal lebih dulu.
 */
final class LinkBehaviorTest extends TestCase
{
    use RefreshDatabase;

    private function link(array $attrs = []): Link
    {
        return Link::factory()->create($attrs);
    }

    public function test_link_aktif_tanpa_kedaluwarsa_dapat_dibuka(): void
    {
        self::assertTrue($this->link()->isReachable());
    }

    /** Mutasi yang pernah lolos: `is_active` diabaikan. */
    public function test_link_nonaktif_tidak_dapat_dibuka(): void
    {
        self::assertFalse($this->link(['is_active' => false])->isReachable(),
            'link dinonaktifkan tapi masih layak dibuka');
    }

    /** Mutasi yang pernah lolos: `expires_at` diabaikan. */
    public function test_link_kedaluwarsa_tidak_dapat_dibuka(): void
    {
        self::assertFalse($this->link(['expires_at' => now()->subSecond()])->isReachable(),
            'link kedaluwarsa tapi masih layak dibuka');
    }

    public function test_link_yang_kedaluwarsa_di_masa_depan_masih_dapat_dibuka(): void
    {
        self::assertTrue($this->link(['expires_at' => now()->addMinute()])->isReachable());
    }

    /** Batas: kedaluwarsa tepat sekarang juga tidak dapat dibuka. */
    public function test_kedaluwarsa_tepat_sekarang_tidak_dapat_dibuka(): void
    {
        $link = $this->link(['expires_at' => now()]);

        self::assertFalse($link->isReachable());
    }

    /** Kombinasi: nonaktif DAN kedaluwarsa. */
    public function test_nonaktif_dan_kedaluwarsa_tetap_tidak_dapat_dibuka(): void
    {
        $link = $this->link(['is_active' => false, 'expires_at' => now()->addYear()]);

        self::assertFalse($link->isReachable());
    }

    public function test_short_url_memakai_prefix_c(): void
    {
        $link = $this->link(['code' => 'abc1234']);

        self::assertStringEndsWith('/c/abc1234', $link->shortUrl());
    }

    /**
     * ADR-0005: rute `/c/{code}`, bukan `/{code}`. Kalau seseorang "memperbaiki"
     * shortUrl() jadi `/{code}`, tautan akan bertabrakan dengan rute aplikasi.
     */
    public function test_short_url_tidak_memakai_prefix_polos(): void
    {
        $link = $this->link(['code' => 'login1']);

        self::assertStringNotContainsString(url('/login1'), $link->shortUrl(),
            'kode link dipakai di rute akar — akan menimpa halaman aplikasi (ADR-0005)');
    }

    // ---------------------------------------------------------------- relasi

    public function test_link_punya_pemilik(): void
    {
        $user = User::factory()->create();
        $link = $this->link(['user_id' => $user->id]);

        self::assertTrue($link->user->is($user));
    }

    public function test_menghapus_link_menghapus_klik_dan_rollup_di_level_database(): void
    {
        $link = $this->link();
        ClickEvent::create($this->clickAttributes($link));
        LinkDailyRollup::create($this->rollupAttributes($link));

        // Soft delete TIDAK menghapus anak (baris parent masih ada).
        $link->delete();
        self::assertSame(1, ClickEvent::count(), 'soft delete ternyata mencascade anak');

        // Force delete trigger ON DELETE CASCADE di MariaDB.
        $link->forceDelete();
        self::assertSame(0, ClickEvent::count(), 'klik menjadi yatim setelah link dihapus permanen');
        self::assertSame(0, LinkDailyRollup::count(), 'rollup menjadi yatim');
    }

    // ------------------------------------------------------------------ batas

    /**
     * UA lebih panjang dari kolom harus DIPOTONG, bukan membuat insert gagal.
     * UA > 512 char itu nyata (browser dengan daftar ekstensi panjang).
     */
    public function test_user_agent_dipotong_ke_512_karakter(): void
    {
        $link = $this->link();
        $panjang = str_repeat('a', 4000);

        $event = ClickEvent::create([
            ...$this->clickAttributes($link),
            'user_agent' => $panjang,
        ]);

        self::assertSame(512, strlen($event->fresh()->user_agent),
            'UA tidak dipotong — insert akan gagal di produksi pada kolom 512');
    }

    public function test_click_event_append_only_tanpa_updated_at(): void
    {
        self::assertNull(ClickEvent::UPDATED_AT,
            'tabel event harus append-only; updated_at tidak punya makna');
    }

    public function test_klik_dan_rollup_terfiltrir_per_link(): void
    {
        $a = $this->link();
        $b = $this->link();

        ClickEvent::create($this->clickAttributes($a));
        ClickEvent::create($this->clickAttributes($b));
        ClickEvent::create($this->clickAttributes($b));

        self::assertSame(1, $a->clickEvents()->count());
        self::assertSame(2, $b->clickEvents()->count());
    }

    // --------------------------------------------------------------- helper

    /** @return array<string, mixed> */
    private function clickAttributes(Link $link): array
    {
        return [
            'link_id' => $link->id,
            'occurred_at' => now(),
            'occurred_on' => now()->toDateString(),
            'visitor_hash' => str_repeat('f', 64),
            'device_type' => 'desktop',
            'browser_family' => 'chrome',
            'os_family' => 'linux',
            'is_bot' => false,
            'source' => 'web',
            'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64) Chrome/120.0.0.0 Safari/537.36',
        ];
    }

    /** @return array<string, mixed> */
    private function rollupAttributes(Link $link): array
    {
        return [
            'link_id' => $link->id,
            'date' => now()->toDateString(),
            'total' => 1,
            'human' => 1,
            'bots' => 0,
            'unique_visitors' => 1,
            'by_device' => ['desktop' => 1],
            'by_browser' => ['chrome' => 1],
            'by_os' => ['linux' => 1],
            'by_referrer' => [LinkDailyRollup::DIRECT_REFERRER => 1],
            'computed_at' => now(),
        ];
    }
}
