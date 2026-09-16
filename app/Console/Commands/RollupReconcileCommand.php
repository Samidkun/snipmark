<?php

namespace App\Console\Commands;

use App\Services\RollupService;
use App\Support\Analytics\DayWindow;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Memeriksa apakah angka tercatat sesuai kenyataan, dan (opsional) memperbaikinya.
 *
 * Inilah yang membedakan counter denormalized yang "dipakai" dari yang "dikelola":
 * counter PASTI pernah melenceng. Sistem yang serius tahu kapan dan bisa memperbaiki
 * diri — bukan berharap tidak pernah terjadi.
 *
 * Exit code: 0 = bersih, 1 = ada drift (ditemukan atau diperbaiki) — sehingga bisa
 * dipakai sebagai gate di CI/scheduler.
 *
 * Contoh:
 *   php artisan snipmark:rollup:reconcile --from=2026-09-01 --to=2026-09-30
 *   php artisan snipmark:rollup:reconcile --from=2026-09-01 --to=2026-09-30 --fix
 *   php artisan snipmark:rollup:reconcile --last-7-days --fix
 */
class RollupReconcileCommand extends Command
{
    protected $signature = 'snipmark:rollup:reconcile
                            {--from= : Tanggal awal (Y-m-d, zona analitik)}
                            {--to= : Tanggal akhir (Y-m-d, zona analitik)}
                            {--last-7-days : Periksa 7 hari terakhir (termasuk hari ini)}
                            {--link= : Batasi ke satu link (id)}
                            {--fix : Perbaiki drift yang ditemukan}';

    protected $description = 'Deteksi (dan opsional perbaiki) drift antara angka tercatat dan kenyataan';

    public function handle(RollupService $service): int
    {
        [$from, $to] = $this->resolveRange();

        $linkId = $this->option('link') !== null ? (int) $this->option('link') : null;
        $fix = (bool) $this->option('fix');

        $this->components->info("Reconcile {$from} .. {$to}".($fix ? ' (mode --fix)' : ' (hanya memeriksa)'));

        $report = $service->reconcile($from, $to, fix: $fix, linkId: $linkId);

        if ($report->isClean()) {
            $this->components->info('Bersih — tidak ada drift.');

            return self::SUCCESS;
        }

        $this->components->warn($report->driftCount().' drift ditemukan:');
        $this->table(
            ['jenis', 'link', 'tanggal', 'tercatat', 'sebenarnya', 'selisih'],
            $report->table(),
        );

        if (! $fix) {
            $this->components->warn('Jalankan ulang dengan --fix untuk memperbaiki.');

            return self::FAILURE;
        }

        $this->components->info($report->fixed.' diperbaiki.');

        // Verifikasi SETELAH perbaikan — jangan hanya percaya bahwa fix berhasil.
        $verifikasi = $service->reconcile($from, $to, linkId: $linkId);

        if (! $verifikasi->isClean()) {
            $this->components->error('MASIH ada drift setelah --fix:');
            $this->table(
                ['jenis', 'link', 'tanggal', 'tercatat', 'sebenarnya', 'selisih'],
                $verifikasi->table(),
            );

            return self::FAILURE;
        }

        $this->components->info('Terverifikasi bersih setelah perbaikan.');

        return self::SUCCESS;
    }

    /** @return array{0: string, 1: string} */
    private function resolveRange(): array
    {
        $tz = config('snipmark.analytics_timezone', DayWindow::DEFAULT_TIMEZONE);

        if ($this->option('last-7-days')) {
            return [
                CarbonImmutable::now($tz)->subDays(6)->format('Y-m-d'),
                DayWindow::today($tz),
            ];
        }

        $from = $this->option('from') ?: DayWindow::yesterday($tz);
        $to = $this->option('to') ?: $from;

        return [(string) $from, (string) $to];
    }
}
