<?php

namespace App\Console\Commands;

use App\Services\RollupService;
use App\Support\Analytics\DayWindow;
use Carbon\CarbonImmutable;
use Database\Seeders\SyntheticTrafficSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Membangkitkan lalu-lintas sintetis dan (opsional) menghitung rollup-nya.
 *
 * Ini yang membuat project bisa DIPAMERKAN: dashboard dengan data nyata, dan
 * benchmark yang angkanya berasal dari data, bukan hiasan.
 *
 * Contoh:
 *   php artisan snipmark:seed:traffic --days=90 --clicks=500
 *   php artisan snipmark:seed:traffic --days=90 --clicks=500 --rollup
 *   php artisan snipmark:seed:traffic --fresh   # kosongkan dulu
 */
class SeedTrafficCommand extends Command
{
    protected $signature = 'snipmark:seed:traffic
                            {--days=90 : Rentang hari ke belakang}
                            {--clicks=500 : Klik per link (link "viral" dikali 6)}
                            {--fresh : Hapus semua link & event dulu}
                            {--rollup : Hitung rollup untuk seluruh rentang setelah seeding}';

    protected $description = 'Bangkitkan lalu-lintas sintetis untuk demo & benchmark';

    public function handle(RollupService $service): int
    {
        $days = (int) $this->option('days');
        $clicks = (int) $this->option('clicks');

        if ($this->option('fresh')) {
            $this->components->warn('Mengosongkan click_events, link_daily_rollups, links...');
            DB::table('click_events')->delete();
            DB::table('link_daily_rollups')->delete();
            DB::table('links')->delete();
        }

        $this->components->info("Membangkitkan {$days} hari data, ~{$clicks} klik/link...");

        $mulai = microtime(true);

        // Dipanggil LANGSUNG, bukan lewat db:seed: `db:seed --class=...` tidak bisa
        // meneruskan argumen (days/clicks) ke konstruktor seeder.
        (new SyntheticTrafficSeeder(
            days: $days,
            clicksPerLink: $clicks,
            log: fn (string $m) => $this->line('  '.$m),
        ))->run();

        $this->components->info('Seeding selesai dalam '.round((microtime(true) - $mulai), 1).' detik.');

        $eventCount = DB::table('click_events')->count();
        $this->components->info("Total event: {$eventCount}");

        if ($this->option('rollup')) {
            $to = DayWindow::today();
            $from = CarbonImmutable::now()->subDays($days - 1)->format('Y-m-d');

            $this->components->info("Menghitung rollup {$from} .. {$to}...");

            $t0 = microtime(true);
            $hari = $service->rollupRange($from, $to);
            $durasi = round(microtime(true) - $t0, 2);

            $baris = DB::table('link_daily_rollups')->count();
            $this->components->info("Rollup: {$hari} hari diproses, {$baris} baris dalam {$durasi} detik.");

            $this->components->info('Reconcile (memeriksa konsistensi)...');
            $report = $service->reconcile($from, $to, fix: true);

            if ($report->isClean()) {
                $this->components->info('Konsisten: tidak ada drift.');
            } else {
                $this->table(
                    ['jenis', 'link', 'tanggal', 'tercatat', 'sebenarnya', 'selisih'],
                    $report->table(),
                );
                $this->components->warn($report->driftCount().' drift ditemukan & diperbaiki.');
            }
        }

        return self::SUCCESS;
    }
}
