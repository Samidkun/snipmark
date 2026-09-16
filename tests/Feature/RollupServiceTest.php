<?php

namespace Tests\Feature;

use App\Models\ClickEvent;
use App\Models\Link;
use App\Models\LinkDailyRollup;
use App\Models\User;
use App\Services\RollupService;
use App\Support\Analytics\DayWindow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Menguji mesin rollup terhadap DATABASE nyata (MariaDB) — karena yang diuji di
 * sini justru interaksi agregator dengan penyimpanan: idempotensi tingkat baris,
 * upsert pada UNIQUE(link_id, date), dan kebenaran SQL agregasi.
 *
 * Kelas murni RollupAggregator sudah diuji terpisah (tests/Unit). Yang di sini:
 * S3 (rollup benar), S4 (idempoten — jalankan 3×, hasil identik), S5 (drift).
 */
final class RollupServiceTest extends TestCase
{
    use RefreshDatabase;

    private RollupService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(RollupService::class);
    }

    private function link(?User $user = null): Link
    {
        return Link::factory()->create(['user_id' => ($user ?? User::factory()->create())->id]);
    }

    /**
     * @param  array<string, mixed>  $over
     */
    private function click(Link $link, string $atUtc, array $over = []): ClickEvent
    {
        return ClickEvent::create([
            'link_id' => $link->id,
            'occurred_at' => $atUtc,
            'occurred_on' => DayWindow::localDateOf(
                CarbonImmutable::parse($atUtc, 'UTC')
            ),
            'visitor_hash' => str_pad('v', 64, '0'),
            'device_type' => 'desktop',
            'browser_family' => 'chrome',
            'os_family' => 'linux',
            'is_bot' => false,
            'source' => 'web',
            'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64) Chrome/120.0.0.0 Safari/537.36',
            ...$over,
        ]);
    }

    // ---------------------------------------------------------------- S3: benar

    public function test_rollup_menghitung_link_dan_hari_yang_bener(): void
    {
        $link = $this->link();

        // Tiga klik pada 16 September WIB + satu bot + satu klik di hari lain.
        $this->click($link, '2026-09-16 02:00:00');
        $this->click($link, '2026-09-16 04:00:00');
        $this->click($link, '2026-09-16 04:30:00', ['visitor_hash' => str_pad('w', 64, '0')]);
        $this->click($link, '2026-09-16 05:00:00', ['is_bot' => true, 'bot_name' => 'curl']);
        $this->click($link, '2026-09-15 02:00:00');

        $this->service->rollupDay('2026-09-16');

        $r = LinkDailyRollup::where('link_id', $link->id)->where('date', '2026-09-16')->sole();

        self::assertSame(4, $r->total);
        self::assertSame(3, $r->human);
        self::assertSame(1, $r->bots);
        self::assertSame(2, $r->unique_visitors);
        self::assertSame('2026-09-16', $r->date->format('Y-m-d'));
        self::assertSame(3, array_sum($r->by_device));
    }

    /**
     * JEBAKAN 1-HARI (spec §4.4). Ini test paling penting di file ini.
     *
     * 2026-09-16 17:30 UTC = 2026-09-17 00:30 WIB. Kalau rollup mengira
     * "satu hari" sama dengan "satu hari UTC", klik ini masuk tanggal 16 dan
     * setiap rentang multi-hari kehilangan/menambah 7 jam di tiap ujung —
     * salah angka, nol error, tidak ada test yang gagal.
     */
    public function test_klik_sesudah_jam_17_utc_dimasukan_ke_hari_wib_berikutnya(): void
    {
        $link = $this->link();

        // 16:30 UTC = 23:30 WIB tanggal 16  -> hari 16
        $this->click($link, '2026-09-16 16:30:00');
        // 17:30 UTC = 00:30 WIB tanggal 17  -> hari 17, BUKAN 16
        $this->click($link, '2026-09-16 17:30:00');

        $this->service->rollupDay('2026-09-16');
        $this->service->rollupDay('2026-09-17');

        $d16 = LinkDailyRollup::where('link_id', $link->id)->where('date', '2026-09-16')->sole();
        $d17 = LinkDailyRollup::where('link_id', $link->id)->where('date', '2026-09-17')->sole();

        self::assertSame(1, $d16->total, '23:30 WIB harus masuk hari 16');
        self::assertSame(1, $d17->total, '00:30 WIB harus masuk hari 17');
    }

    /** Tidak ada hari = tidak ada baris rollup. */
    public function test_hari_tanpa_klik_tidak_menciptakan_baris_rollup(): void
    {
        $link = $this->link();
        $this->click($link, '2026-09-16 02:00:00');

        $this->service->rollupDay('2026-01-01');

        self::assertSame(0, LinkDailyRollup::count(),
            'rollup menciptakan baris nol untuk hari tanpa data — tumpukan sampah');
    }

    // ------------------------------------------------- S4: IDEMPOTEN (inti §5.1)

    /**
     * Dijalankan 3× pada hari yang sama: satu baris, angka identik.
     *
     * Inilah alasan rollup dihitung ULANG dari raw (bukan `+= delta`): job yang
     * gagal di tengah dan dijalankan ulang tidak boleh menghasilkan angka berlipat.
     */
    public function test_rollup_idempoten_terhadap_pengujian_berulang(): void
    {
        $link = $this->link();
        for ($i = 0; $i < 5; $i++) {
            $this->click($link, '2026-09-16 0'.($i % 6).':00:00', [
                'visitor_hash' => str_pad("v{$i}", 64, '0'),
            ]);
        }
        $this->click($link, '2026-09-16 03:00:00', ['is_bot' => true]);

        $this->service->rollupDay('2026-09-16');

        $snapshot = DB::table('link_daily_rollups')
            ->where('link_id', $link->id)->where('date', '2026-09-16')
            ->first();

        $this->service->rollupDay('2026-09-16');
        $this->service->rollupDay('2026-09-16');

        $after = DB::table('link_daily_rollups')
            ->where('link_id', $link->id)->where('date', '2026-09-16')
            ->first();

        self::assertSame(1, LinkDailyRollup::whereDate('date', '2026-09-16')->count(),
            'rollup 3× menghasilkan baris ganda — UNIQUE/ upsert tidak menegakkan idempotensi');

        foreach (['total', 'human', 'bots', 'unique_visitors', 'by_device', 'by_browser', 'by_os', 'by_referrer'] as $col) {
            self::assertEquals($snapshot->{$col}, $after->{$col}, "kolom {$col} berubah saat rollup diulang");
        }

        self::assertSame(5, (int) $after->human);
    }

    /** Rollup mencerminkan data saat ini (bukan menumpuk di atas dirinya sendiri). */
    public function test_rollup_menghitung_ulang_bukan_menambah(): void
    {
        $link = $this->link();
        $this->click($link, '2026-09-16 02:00:00');

        $this->service->rollupDay('2026-09-16');
        self::assertSame(1, LinkDailyRollup::sole()->total);

        // Klik baru tiba, lalu rollup ulang hari yang sama.
        $this->click($link, '2026-09-16 03:00:00');
        $this->service->rollupDay('2026-09-16');

        self::assertSame(2, LinkDailyRollup::sole()->total,
            'nilainya bertambah di atas rollup lama (+= delta), bukan dihitung ulang');
    }

    /** Setelah event dihapus, rollup ulang harus MENGURANGI angka. */
    public function test_rollup_ulang_menurunkan_angka_saat_event_hilang(): void
    {
        $link = $this->link();
        $a = $this->click($link, '2026-09-16 02:00:00');
        $this->click($link, '2026-09-16 03:00:00');

        $this->service->rollupDay('2026-09-16');
        self::assertSame(2, LinkDailyRollup::sole()->total);

        $a->forceDelete();
        $this->service->rollupDay('2026-09-16');

        self::assertSame(1, LinkDailyRollup::sole()->total,
            'rollup delta tidak bisa turun — bukti full-recompute diperlukan');
    }

    // --------------------------------------------- S5: RECONCILE (deteksi drift)

    public function test_reconcile_mendeteksi_drift_counter_dan_memperbaikinya(): void
    {
        $link = $this->link();
        $this->click($link, '2026-09-16 02:00:00');
        $this->click($link, '2026-09-16 03:00:00');

        $this->service->rollupDay('2026-09-16');

        // Rusak counter denormalized secara manual (simulasi kegagalan di produksi:
        // mis. increment hilang karena cache/bug).
        DB::table('links')->where('id', $link->id)->update(['total_clicks' => 99]);

        $report = $this->service->reconcile('2026-09-01', '2026-09-30');

        self::assertNotEmpty($report->drifts, 'drift tidak terdeteksi');
        $d = $report->drifts[0];
        self::assertSame($link->id, $d['link_id']);
        self::assertSame(99, (int) $d['recorded']);
        self::assertSame(2, (int) $d['actual']);

        // Tanpa --fix: data tidak boleh berubah.
        self::assertSame(99, $link->fresh()->total_clicks);

        $fixed = $this->service->reconcile('2026-09-01', '2026-09-30', fix: true);

        // Kontrak laporan: `drifts` = yang DITEMUKAN (bukan disapu bersih),
        // `fixed` = berapa yang ditangani.
        self::assertSame(1, $fixed->driftCount());
        self::assertSame(1, $fixed->fixed, 'laporan tidak mencatat perbaikan');
        self::assertSame(2, $link->fresh()->total_clicks, 'counter tidak diperbaiki');

        // Pemanggilan KEDUA yang membuktikan kebersihan.
        $ulang = $this->service->reconcile('2026-09-01', '2026-09-30');
        self::assertTrue($ulang->isClean(), 'setelah --fix, masih ada drift: '.json_encode($ulang->drifts));
    }

    public function test_reconcile_bersaat_saat_angka_konsisten(): void
    {
        $link = $this->link();
        $this->click($link, '2026-09-16 02:00:00');

        $link->forceFill(['total_clicks' => 1])->save();
        $this->service->rollupDay('2026-09-16');

        $report = $this->service->reconcile('2026-09-16', '2026-09-16');

        self::assertSame([], $report->drifts);
    }

    /**
     * Drift tingkat rollup: total_clicks benar tetapi baris rollup basi
     * (mis. rollup sempat dijalankan sebelum data lengkap).
     */
    public function test_reconcile_mendeteksi_rollup_basi(): void
    {
        $link = $this->link();
        $this->click($link, '2026-09-16 02:00:00');
        $this->service->rollupDay('2026-09-16');

        // Klik lagi, TANPA rollup ulang -> rollup basi tapi counter ikut naik.
        $this->click($link, '2026-09-16 05:00:00');
        DB::table('links')->where('id', $link->id)->increment('total_clicks');

        $report = $this->service->reconcile('2026-09-16', '2026-09-16', fix: true);

        self::assertGreaterThanOrEqual(1, $report->fixed, 'laporan tidak mencatat perbaikan');
        self::assertSame(2, (int) LinkDailyRollup::where('link_id', $link->id)
            ->whereDate('date', '2026-09-16')->value('total'),
            'rollup basi tidak diperbaiki');
        self::assertSame(2, $link->fresh()->total_clicks, 'counter tidak disinkronkan');

        self::assertTrue(
            $this->service->reconcile('2026-09-16', '2026-09-16')->isClean(),
            'setelah fix, drift masih terdeteksi'
        );
    }

    // ------------------------------------------------------------------ cakupan

    /** Rollup lintas link pada hari yang sama harus memisahkan per link. */
    public function test_rollup_memisah_per_link(): void
    {
        $a = $this->link();
        $b = $this->link();
        $this->click($a, '2026-09-16 02:00:00');
        $this->click($b, '2026-09-16 02:00:00');
        $this->click($b, '2026-09-16 03:00:00');

        $this->service->rollupDay('2026-09-16');

        self::assertSame(1, LinkDailyRollup::where('link_id', $a->id)->sole()->total);
        self::assertSame(2, LinkDailyRollup::where('link_id', $b->id)->sole()->total);
    }

    public function test_rentang_hari_memproses_setiap_hari(): void
    {
        $link = $this->link();
        $this->click($link, '2026-09-14 02:00:00');
        $this->click($link, '2026-09-15 02:00:00');
        $this->click($link, '2026-09-16 02:00:00');

        $diproses = $this->service->rollupRange('2026-09-14', '2026-09-16');

        self::assertSame(3, $diproses);
        self::assertSame(3, LinkDailyRollup::count());
        self::assertSame(1, LinkDailyRollup::whereDate('date', '2026-09-14')->sole()->total);
    }

    /** `--link` mempersempit pekerjaan (spec §5.1). */
    public function test_rollup_bisa_dibatasi_satu_link(): void
    {
        $a = $this->link();
        $b = $this->link();
        $this->click($a, '2026-09-16 02:00:00');
        $this->click($b, '2026-09-16 02:00:00');

        $this->service->rollupDay('2026-09-16', linkId: $a->id);

        self::assertSame(1, LinkDailyRollup::count(), 'link lain ikut diproses');
        self::assertSame($a->id, LinkDailyRollup::sole()->link_id);
    }

    /**
     * `computed_at` menandai kapan agregat dihitung — tanpa itu mustahil
     * mendiagnosis rollup basi.
     */
    public function test_computed_at_terisi(): void
    {
        $link = $this->link();
        $this->click($link, '2026-09-16 02:00:00');

        $this->service->rollupDay('2026-09-16');

        self::assertNotNull(LinkDailyRollup::sole()->computed_at);
    }
}
