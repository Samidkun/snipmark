<?php

namespace Tests\Feature;

use App\Models\ClickEvent;
use App\Models\Link;
use App\Models\LinkDailyRollup;
use App\Models\User;
use App\Support\Analytics\DayWindow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Perintah artisan adalah ANTARMUKA: exit code-nya dipakai scheduler dan CI,
 * jadi exit code juga harus diuji — bukan hanya outputnya.
 *
 * Pelajaran dari run ini: gate yang melaporkan sukses tanpa benar-benar lolos
 * adalah kegagalan yang paling berbahaya. `reconcile` yang keluar 0 padahal
 * masih ada drift akan membuat scheduler diam-diam menerima data rusak.
 */
final class RollupCommandsTest extends TestCase
{
    use RefreshDatabase;

    private function link(): Link
    {
        return Link::factory()->create(['user_id' => User::factory()->create()->id]);
    }

    private function click(Link $link, string $utc): void
    {
        ClickEvent::create([
            'link_id' => $link->id,
            'occurred_at' => $utc,
            'occurred_on' => DayWindow::localDateOf(
                CarbonImmutable::parse($utc, 'UTC')
            ),
            'visitor_hash' => str_pad('x', 64, '0'),
            'device_type' => 'desktop',
            'browser_family' => 'chrome',
            'os_family' => 'linux',
            'is_bot' => false,
            'source' => 'web',
        ]);
    }

    // ------------------------------------------------------ snipmark:rollup:day

    public function test_rollup_day_menulis_baris_dan_keluar_0(): void
    {
        $link = $this->link();
        $this->click($link, '2026-09-16 02:00:00');

        $this->artisan('snipmark:rollup:day', ['date' => '2026-09-16'])
            ->assertSuccessful();

        self::assertSame(1, LinkDailyRollup::count());
    }

    public function test_rollup_day_tanpa_data_tetap_keluar_0_dengan_peringatan(): void
    {
        $this->artisan('snipmark:rollup:day', ['date' => '2026-01-01'])
            ->expectsOutputToContain('Tidak ada klik')
            ->assertSuccessful();

        self::assertSame(0, LinkDailyRollup::count());
    }

    public function test_rollup_day_menolak_tanggal_tidak_sah(): void
    {
        $this->artisan('snipmark:rollup:day', ['date' => 'kemarin'])
            ->assertFailed();
    }

    /** Idempotensi lewat CLI: dijalankan 3× tetap satu baris. */
    public function test_rollup_day_idempoten_lewat_cli(): void
    {
        $link = $this->link();
        $this->click($link, '2026-09-16 02:00:00');

        foreach (range(1, 3) as $_) {
            $this->artisan('snipmark:rollup:day', ['date' => '2026-09-16'])->assertSuccessful();
        }

        self::assertSame(1, LinkDailyRollup::count());
        self::assertSame(1, (int) LinkDailyRollup::sole()->total);
    }

    public function test_rollup_day_bisa_dibatasi_link(): void
    {
        $a = $this->link();
        $b = $this->link();
        $this->click($a, '2026-09-16 02:00:00');
        $this->click($b, '2026-09-16 02:00:00');

        $this->artisan('snipmark:rollup:day', ['date' => '2026-09-16', '--link' => $a->id])
            ->assertSuccessful();

        self::assertSame(1, LinkDailyRollup::count());
        self::assertSame($a->id, LinkDailyRollup::sole()->link_id);
    }

    // ------------------------------------------------- snipmark:rollup:reconcile

    public function test_reconcile_keluar_0_saat_bersih(): void
    {
        $link = $this->link();
        $this->click($link, '2026-09-16 02:00:00');
        DB::table('links')->where('id', $link->id)->update(['total_clicks' => 1]);

        $this->artisan('snipmark:rollup:reconcile', [
            '--from' => '2026-09-16', '--to' => '2026-09-16',
        ])->assertSuccessful();
    }

    /**
     * Exit code 1 saat ada drift TANPA --fix. Ini yang membuat perintah bisa
     * dipakai sebagai gate: scheduler/CI tahu ada masalah.
     */
    public function test_reconcile_keluar_1_saat_ada_drift_tanpa_fix(): void
    {
        $link = $this->link();
        $this->click($link, '2026-09-16 02:00:00');
        DB::table('links')->where('id', $link->id)->update(['total_clicks' => 99]);

        $this->artisan('snipmark:rollup:reconcile', [
            '--from' => '2026-09-16', '--to' => '2026-09-16',
        ])->assertFailed();

        // Tanpa --fix, data tidak boleh diubah.
        self::assertSame(99, (int) DB::table('links')->where('id', $link->id)->value('total_clicks'));
    }

    public function test_reconcile_dengan_fix_memperbaiki_dan_keluar_0(): void
    {
        $link = $this->link();
        $this->click($link, '2026-09-16 02:00:00');
        DB::table('links')->where('id', $link->id)->update(['total_clicks' => 99]);

        $this->artisan('snipmark:rollup:reconcile', [
            '--from' => '2026-09-16', '--to' => '2026-09-16', '--fix' => true,
        ])->assertSuccessful();

        self::assertSame(1, (int) DB::table('links')->where('id', $link->id)->value('total_clicks'));
    }

    public function test_reconcile_last_7_days_mencakup_hari_ini(): void
    {
        $this->artisan('snipmark:rollup:reconcile', ['--last-7-days' => true])
            ->expectsOutputToContain('Reconcile')
            ->assertSuccessful();
    }

    // --------------------------------------------------- snipmark:seed:traffic

    /**
     * Seeder harus menghasilkan campuran manusia DAN bot — kalau semua manusia,
     * dashboard tidak pernah menguji pemisahan bot, dan itu bagian yang dijual.
     */
    public function test_seed_traffic_menghasilkan_manusia_dan_bot(): void
    {
        $this->artisan('snipmark:seed:traffic', [
            '--days' => 5, '--clicks' => 40, '--fresh' => true,
        ])->assertSuccessful();

        self::assertGreaterThan(0, ClickEvent::where('is_bot', false)->count(), 'tidak ada trafik manusia');
        self::assertGreaterThan(0, ClickEvent::where('is_bot', true)->count(), 'tidak ada trafik bot');
    }

    public function test_seed_traffic_membuat_12_link(): void
    {
        $this->artisan('snipmark:seed:traffic', ['--days' => 5, '--clicks' => 20, '--fresh' => true])
            ->assertSuccessful();

        self::assertSame(12, Link::count());
    }

    /** occurred_on harus konsisten dengan occurred_at pada zona WIB. */
    public function test_seed_traffic_konsisten_dengan_zona_wib(): void
    {
        $this->artisan('snipmark:seed:traffic', ['--days' => 3, '--clicks' => 30, '--fresh' => true])
            ->assertSuccessful();

        $salah = DB::table('click_events')
            ->whereRaw("occurred_on <> DATE(CONVERT_TZ(occurred_at, '+00:00', '+07:00'))")
            ->count();

        self::assertSame(0, $salah,
            "{$salah} event punya occurred_on yang tidak cocok dengan tanggal WIB-nya");
    }

    public function test_seed_traffic_dengan_rollup_menghasilkan_rollup_konsisten(): void
    {
        $this->artisan('snipmark:seed:traffic', [
            '--days' => 5, '--clicks' => 30, '--fresh' => true, '--rollup' => true,
        ])->assertSuccessful();

        self::assertGreaterThan(0, LinkDailyRollup::count());

        $this->artisan('snipmark:rollup:reconcile', ['--last-7-days' => true])
            ->assertSuccessful();
    }

    /** Seeder tidak boleh menyimpan IP mentah (kontrak privasi). */
    public function test_seed_traffic_tidak_menyimpan_ip(): void
    {
        $this->artisan('snipmark:seed:traffic', ['--days' => 2, '--clicks' => 10, '--fresh' => true])
            ->assertSuccessful();

        $kolom = array_map(fn ($c) => $c->Field, DB::select('SHOW COLUMNS FROM click_events'));
        foreach (['ip', 'ip_address', 'visitor_ip'] as $terlarang) {
            self::assertNotContains($terlarang, $kolom);
        }
    }
}
