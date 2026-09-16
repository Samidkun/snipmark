<?php

namespace Tests\Feature;

use App\Models\ClickEvent;
use App\Models\Link;
use App\Models\User;
use App\Support\Analytics\DayWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Jalur redirect `/c/{code}` — endpoint yang paling sering dipanggil aplikasi ini.
 *
 * Yang diuji BUKAN hanya "mengembalikan 302", tetapi properti yang menentukan
 * apakah jalur ini layak dipakai:
 *   - 302, bukan 301 (kalau 301, browser meng-cache permanen -> rotasi mustahil)
 *   - counter naik ATOMIK, benar di bawah konkurensi (bukan read-then-write)
 *   - klik tercatat dengan metadata & hash, TANPA IP mentah
 *   - cache mempercepat lookup DAN dibersihkan saat link berubah
 *   - link mati/kedaluwarsa mengembalikan halaman yang benar
 *   - bot diklasifikasi, dan (v1) TIDAK diblokir — hanya dicatat
 */
final class RedirectTest extends TestCase
{
    use RefreshDatabase;

    private function link(array $attrs = []): Link
    {
        return Link::factory()->create([
            'user_id' => User::factory()->create()->id,
            ...$attrs,
        ]);
    }

    private function ua(): string
    {
        return 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    }

    // ------------------------------------------------------------- happy path

    public function test_redirect_mengembalikan_302_ke_destination(): void
    {
        $link = $this->link(['code' => 'abc1234', 'destination' => 'https://example.com/tujuan']);

        $response = $this->get('/c/abc1234');

        $response->assertStatus(302);
        $response->assertRedirect('https://example.com/tujuan');
    }

    /**
     * 302, BUKAN 301. Ini keputusan sadar (ADR-0006): 301 di-cache browser
     * secara permanen, sehingga mengganti destination atau mematikan link menjadi
     * tidak efektif bagi pengunjung yang pernah membuka tautan itu.
     */
    public function test_memakai_302_bukan_301(): void
    {
        $link = $this->link(['code' => 'abc1234']);

        self::assertSame(302, $this->get('/c/abc1234')->getStatusCode(),
            'memakai 301 akan mengunci destination di cache browser secara permanen');
    }

    public function test_klik_tercatat_dengan_metadata_lengkap(): void
    {
        $link = $this->link(['code' => 'abc1234']);

        $this->withHeaders([
            'User-Agent' => $this->ua(),
            'Referer' => 'https://www.google.com/search?q=test',
        ])->get('/c/abc1234');

        $event = ClickEvent::sole();

        self::assertSame($link->id, $event->link_id);
        self::assertSame('desktop', $event->device_type);
        self::assertSame('chrome', $event->browser_family);
        self::assertSame('linux', $event->os_family);
        self::assertFalse($event->is_bot);
        self::assertSame('google.com', $event->referrer_host, 'referrer harus dinormalisasi');
        self::assertSame(64, strlen($event->visitor_hash));
    }

    /** occurred_on harus tanggal WIB, konsisten dengan mesin rollup. */
    public function test_occurred_on_memakai_tanggal_wib(): void
    {
        $link = $this->link(['code' => 'abc1234']);

        $this->get('/c/abc1234');

        $event = ClickEvent::sole();
        $harusnya = DayWindow::localDateOf($event->occurred_at);

        self::assertSame($harusnya, $event->occurred_on->format('Y-m-d'));
    }

    public function test_ua_mentah_tersimpan_dan_dipotong(): void
    {
        $link = $this->link(['code' => 'abc1234']);
        $panjang = str_repeat('Mozilla/5.0 ', 100);

        $this->withHeaders(['User-Agent' => $panjang])->get('/c/abc1234');

        self::assertSame(512, strlen(ClickEvent::sole()->user_agent),
            'UA panjang harus dipotong, bukan membuat insert gagal (bug B9)');
    }

    // -------------------------------------------------------- counter & atomik

    public function test_counter_naik_tepat_satu_per_klik(): void
    {
        $link = $this->link(['code' => 'abc1234']);

        $this->get('/c/abc1234');
        $this->get('/c/abc1234');
        $this->get('/c/abc1234');

        self::assertSame(3, $link->fresh()->total_clicks);
        self::assertSame(3, ClickEvent::count());
    }

    /**
     * COUNTER ATOMIK — inti jalur ini.
     *
     * KENAPA BUKAN 200 increment paralel: PHPUnit menjalankan request secara
     * SEKUENSIAL, sehingga read-then-write pun akan lolos — test seperti itu
     * menguji MariaDB, bukan kode ini. (Mutasi "read-then-write" sempat HIJAU,
     * dan itu membuktikan test pertama saya tidak menguji apa pun.)
     *
     * Yang benar-benar membedakan: SQL yang dieksekusi. Atomik berarti
     * `SET total_clicks = total_clicks + 1` (dihitung DB), bukan nilai yang
     * sudah dibaca aplikasi lebih dulu.
     */
    public function test_counter_dinaikkan_dengan_sql_atomik_bukan_read_then_write(): void
    {
        $link = $this->link(['code' => 'abc1234']);

        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            $sql[] = $q->sql;
        });

        $this->get('/c/abc1234');

        $update = collect($sql)->first(fn (string $s) => str_contains($s, 'update `links`'));

        self::assertNotNull($update, 'tidak ada UPDATE links — counter tidak dinaikkan');
        self::assertMatchesRegularExpression(
            '/total_clicks`?\s*=\s*`?total_clicks`?\s*\+\s*1/i',
            $update,
            'counter BUKAN dinaikkan secara atomik di DB (indikasi read-then-write): '.$update
        );
    }

    /** Nilai akhir tetap benar setelah beberapa klik (menjaga arah perubahan). */
    public function test_counter_atomik_menghasilkan_nilai_akhir_benar(): void
    {
        $link = $this->link(['code' => 'abc1234']);

        for ($i = 0; $i < 25; $i++) {
            $this->get('/c/abc1234');
        }

        self::assertSame(25, $link->fresh()->total_clicks);
    }

    /** Counter tidak boleh dihitung dari event (itu tugas rollup/reconcile). */
    public function test_counter_tidak_bergantung_pada_jumlah_event(): void
    {
        $link = $this->link(['code' => 'abc1234']);

        $this->get('/c/abc1234');
        self::assertSame(1, $link->fresh()->total_clicks);

        // Event dihapus (mis. retensi), counter tetap nilai tercatatnya.
        ClickEvent::query()->delete();

        self::assertSame(1, $link->fresh()->total_clicks);
    }

    // ------------------------------------------------------------ link tidak sah

    public function test_kode_tidak_dikenal_mengembalikan_404(): void
    {
        $this->get('/c/tidakada')->assertStatus(404);
    }

    public function test_link_nonaktif_mengembalikan_404_tanpa_mencatat_klik(): void
    {
        $this->link(['code' => 'abc1234', 'is_active' => false]);

        $this->get('/c/abc1234')->assertStatus(404);

        self::assertSame(0, ClickEvent::count(), 'klik pada link mati tidak boleh dicatat');
    }

    public function test_link_kedaluwarsa_mengembalikan_410(): void
    {
        $this->link(['code' => 'abc1234', 'expires_at' => now()->subMinute()]);

        // 410 Gone lebih informatif daripada 404: tautannya memang pernah ada.
        $this->get('/c/abc1234')->assertStatus(410);
        self::assertSame(0, ClickEvent::count());
    }

    /** Kode dicari case-sensitive: 'ABC1234' bukan 'abc1234'. */
    public function test_kode_bersifat_case_sensitive(): void
    {
        $this->link(['code' => 'abc1234']);

        $this->get('/c/ABC1234')->assertStatus(404);
    }

    /** Rute akar tidak boleh dipakai untuk kode (ADR-0005). */
    public function test_kode_tidak_dilayani_di_rute_akar(): void
    {
        $this->link(['code' => 'login11']);

        // '/' bukan halaman redirect link.
        $this->get('/login11')->assertStatus(404);
    }

    // ------------------------------------------------------------------- cache

    public function test_lookup_kedua_tidak_menyentuh_database_untuk_memuat_link(): void
    {
        $link = $this->link(['code' => 'abc1234', 'destination' => 'https://example.com/x']);

        $this->get('/c/abc1234');
        self::assertTrue(Cache::has('snip:url:abc1234'), 'cache tidak terisi setelah klik pertama');

        // Hapus baris link dari DB: kalau masih 302, berarti dilayani dari cache.
        DB::table('links')->where('id', $link->id)->delete();

        $this->get('/c/abc1234')->assertRedirect('https://example.com/x');
    }

    /** Mengubah destination harus membatalkan cache — kalau tidak, redirect basi. */
    public function test_mengubah_destination_membersihkan_cache(): void
    {
        $link = $this->link(['code' => 'abc1234', 'destination' => 'https://example.com/lama']);

        $this->get('/c/abc1234')->assertRedirect('https://example.com/lama');

        $link->update(['destination' => 'https://example.com/baru']);

        $this->get('/c/abc1234')->assertRedirect('https://example.com/baru');
    }

    public function test_menghapus_link_membersihkan_cache(): void
    {
        $link = $this->link(['code' => 'abc1234']);

        $this->get('/c/abc1234');
        $link->delete();

        $this->get('/c/abc1234')->assertStatus(404);
    }

    // --------------------------------------------------------------------- bot

    public function test_bot_terklasifikasi_tetapi_tetap_dilayani_302(): void
    {
        $link = $this->link(['code' => 'abc1234', 'destination' => 'https://example.com/x']);

        $response = $this->withHeaders(['User-Agent' => 'curl/8.4.0'])->get('/c/abc1234');

        // SHADOW MODE (spec §6.3): dicatat, TIDAK diblokir di v1.
        $response->assertStatus(302);

        $event = ClickEvent::sole();
        self::assertTrue($event->is_bot);
        self::assertSame('curl', $event->bot_name);
        self::assertSame('scraper', $event->bot_category);
        // Dimensi bot tidak boleh mencemari laporan manusia.
        self::assertSame('other', $event->device_type);
    }

    public function test_klik_bot_tetap_menambah_counter(): void
    {
        $link = $this->link(['code' => 'abc1234']);

        $this->withHeaders(['User-Agent' => 'curl/8.4.0'])->get('/c/abc1234');

        self::assertSame(1, $link->fresh()->total_clicks,
            'counter menghitung SEMUA klik; pemisahan bot dilakukan di lapisan analytics');
    }

    /**
     * Jalur `datacenter_cidr` (spec §6.3) — LUBANG yang ditemukan mutation check:
     * sebelumnya tidak ada satu pun test yang menempuh jalur ini, sehingga
     * menghapus seluruh pemeriksaan CIDR tetap membuat suite hijau.
     *
     * UA di sini sengaja seperti browser manusia, jadi satu-satunya alasan
     * penandaan adalah IP yang berasal dari blok pusat data.
     */
    public function test_ip_dari_blok_pusat_data_ditandai_tetapi_tetap_dilayani(): void
    {
        $link = $this->link(['code' => 'abc1234', 'destination' => 'https://example.com/x']);

        // 3.5.1.1 berada di dalam 3.0.0.0/8 (rentang AWS pada config).
        $response = $this->withServerVariables(['REMOTE_ADDR' => '3.5.1.1'])
            ->withHeaders(['User-Agent' => $this->ua()])
            ->get('/c/abc1234');

        $response->assertStatus(302);

        $event = ClickEvent::sole();
        self::assertTrue($event->is_bot, 'IP pusat data tidak ditandai');
        self::assertSame('datacenter_cidr', $event->bot_name);
        self::assertSame('scraper', $event->bot_category);
    }

    /** IP rumahan tidak boleh ditandai pusat data (false positive = metrik rusak). */
    public function test_ip_rumahan_tidak_ditandai_pusat_data(): void
    {
        $link = $this->link(['code' => 'abc1234']);

        $this->withServerVariables(['REMOTE_ADDR' => '114.79.16.20'])
            ->withHeaders(['User-Agent' => $this->ua()])
            ->get('/c/abc1234');

        $event = ClickEvent::sole();
        self::assertFalse($event->is_bot, 'IP rumahan ditandai bot — false positive');
        self::assertNull($event->bot_name);
    }

    /**
     * Shadow mode: kelebihan laju TIDAK memblokir (v1). Pengunjung tetap dilayani,
     * hanya ditandai. Kalau kelak enforcement diaktifkan, test ini yang harus diubah
     * lebih dulu — bukan diam-diam berubah perilaku.
     */
    public function test_kelebihan_laju_tidak_memblokir_dan_hanya_ditandai(): void
    {
        config()->set('snipmark.redirect_rate_limit', 3);
        $link = $this->link(['code' => 'abc1234', 'destination' => 'https://example.com/x']);

        for ($i = 0; $i < 6; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '114.79.16.20'])
                ->withHeaders(['User-Agent' => $this->ua()])
                ->get('/c/abc1234')
                ->assertStatus(302);
        }

        self::assertSame(6, $link->fresh()->total_clicks, 'shadow mode tidak boleh memblokir');

        $ditandai = ClickEvent::where('bot_name', 'rate_limited')->count();
        self::assertGreaterThan(0, $ditandai, 'kelebihan laju tidak ditandai sama sekali');
    }

    // --------------------------------------------------------------- privasi

    /** Kontrak privasi: tidak ada IP mentah yang tersimpan di mana pun. */
    public function test_ip_mentah_tidak_tersimpan(): void
    {
        $link = $this->link(['code' => 'abc1234']);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])->get('/c/abc1234');

        $event = ClickEvent::sole();

        foreach (get_object_vars($event) as $nilai) {
            self::assertStringNotContainsString('203.0.113.77', (string) $nilai,
                'IP mentah bocor ke dalam baris event');
        }
    }

    /** IP yang sama, hari berbeda -> hash berbeda (rotasi harian). */
    public function test_visitor_hash_berotasi_harian(): void
    {
        $link = $this->link(['code' => 'abc1234']);

        $this->travelTo(now()->setDate(2026, 9, 16)->setTime(10, 0));
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])->get('/c/abc1234');

        $this->travelTo(now()->setDate(2026, 9, 17)->setTime(10, 0));
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])->get('/c/abc1234');

        $hash = ClickEvent::query()->orderBy('id')->pluck('visitor_hash');

        self::assertCount(2, $hash);
        self::assertNotSame($hash[0], $hash[1], 'hash tidak berotasi -> bisa melacak orang lintas hari');
    }

    /** IP sama pada hari yang sama -> hash sama (dasar unique visitor harian). */
    public function test_ip_sama_hari_sama_menghasilkan_hash_sama(): void
    {
        $link = $this->link(['code' => 'abc1234']);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])->get('/c/abc1234');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])->get('/c/abc1234');

        self::assertSame(1, ClickEvent::query()->distinct()->count('visitor_hash'));
    }
}
