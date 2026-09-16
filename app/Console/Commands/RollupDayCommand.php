<?php

namespace App\Console\Commands;

use App\Services\RollupService;
use App\Support\Analytics\DayWindow;
use Illuminate\Console\Command;

/**
 * Menghitung ulang rollup untuk SATU hari.
 *
 * IDEMPOTEN: menjalankannya berkali-kali untuk hari yang sama menghasilkan angka
 * yang sama, bukan angka berlipat. Karena itu perintah ini aman dijalankan ulang
 * setelah kegagalan, dan aman dijadwalkan ulang.
 *
 * Contoh:
 *   php artisan snipmark:rollup:day 2026-09-16
 *   php artisan snipmark:rollup:day --yesterday
 *   php artisan snipmark:rollup:day 2026-09-16 --link=42
 */
class RollupDayCommand extends Command
{
    protected $signature = 'snipmark:rollup:day
                            {date? : Tanggal lokal (Y-m-d) pada zona analitik}
                            {--yesterday : Proses hari kemarin (zona analitik)}
                            {--link= : Batasi ke satu link (id)}';

    protected $description = 'Hitung ulang rollup harian untuk satu tanggal (idempoten)';

    public function handle(RollupService $service): int
    {
        $date = $this->option('yesterday')
            ? DayWindow::yesterday($this->timezone())
            : ($this->argument('date') ?? DayWindow::yesterday($this->timezone()));

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
            $this->components->error("Tanggal tidak sah: {$date} (harap format Y-m-d)");

            return self::FAILURE;
        }

        $linkId = $this->option('link') !== null ? (int) $this->option('link') : null;

        $this->components->info(
            "Rollup {$date} (zona {$this->timezone()})".($linkId ? " untuk link {$linkId}" : '')
        );

        $mulai = microtime(true);
        $jumlah = $service->rollupDay((string) $date, $linkId);
        $durasi = round((microtime(true) - $mulai) * 1000);

        if ($jumlah === 0) {
            $this->components->warn("Tidak ada klik pada {$date} — tidak ada baris rollup ditulis.");

            return self::SUCCESS;
        }

        $this->components->info("{$jumlah} baris rollup ditulis dalam {$durasi} ms.");

        return self::SUCCESS;
    }

    private function timezone(): string
    {
        return config('snipmark.analytics_timezone', DayWindow::DEFAULT_TIMEZONE);
    }
}
