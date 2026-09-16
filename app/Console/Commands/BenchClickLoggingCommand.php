<?php

namespace App\Console\Commands;

use App\Jobs\LogClickJob;
use App\Models\ClickEvent;
use App\Models\Link;
use App\Models\User;
use App\Support\Analytics\DayWindow;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Benchmark jalur penulisan klik: sync vs queue vs batch.
 *
 * ANGKA INI YANG MEMUTUSKAN desain spec §8 — bukan opini. Kalau jalur queue ternyata
 * lebih lambat pada satu node MariaDB tanpa Redis (hipotesis gw), itu harus tertulis
 * dengan bukti, bukan diasumsikan.
 *
 * Metode: N klik ke link yang sama, tiap MODE diukur pada DB yang dibersihkan
 * di antaranya. Setiap angka dicetak dengan median 3 pengulangan, bukan satu run —
 * satu run bisa terdistorsi oleh cache/IO acak.
 */
class BenchClickLoggingCommand extends Command
{
    protected $signature = 'snipmark:bench:click-logging
                            {--iterations=1000 : Jumlah klik per mode}
                            {--rounds=3 : Pengulangan untuk median}';

    protected $description = 'Bandingkan sync vs queue vs batch insert untuk penulisan klik';

    public function handle(): int
    {
        $n = max(10, (int) $this->option('iterations'));
        $rounds = max(1, (int) $this->option('rounds'));

        $this->components->info("Benchmark penulisan klik: {$n} klik × {$rounds} ronde");

        $link = $this->prepareLink();

        $modes = [
            'sync (1 insert/klik)' => fn () => $this->benchSync($link, $n),
            'queue database (insert via worker)' => function () use ($link, $n) {
                return $this->benchQueue($link, $n);
            },
            'batch insert (insertAll)' => fn () => $this->benchBatch($link, $n),
        ];

        $hasil = [];

        foreach ($modes as $nama => $fn) {
            $waktu = [];

            for ($r = 0; $r < $rounds; $r++) {
                $this->reset($link);
                $waktu[] = $fn();
            }

            sort($waktu);
            $median = $waktu[(int) floor(count($waktu) / 2)];

            $hasil[] = [
                'mode' => $nama,
                'median_ms' => round($median, 1),
                'terbaik_ms' => round(min($waktu), 1),
                'terburuk_ms' => round(max($waktu), 1),
                ' klik/detik' => (int) round($n / ($median / 1000)),
            ];
        }

        $this->table(
            ['mode', 'median ms', 'terbaik ms', 'terburuk ms', 'klik/detik'],
            array_map(fn ($r) => [
                $r['mode'], $r['median_ms'], $r['terbaik_ms'], $r['terburuk_ms'], $r[' klik/detik'],
            ], $hasil),
        );

        $this->line('');
        $this->line(':   php artisan snipmark:bench:click-logging --iterations=1000 --rounds=3');
        $this->line(':   DB: mariadb, queue=database, cache=file,'.config('snipmark.analytics_timezone'));

        return self::SUCCESS;
    }

    private function prepareLink(): Link
    {
        // Pakai link yang SUDAH ada supaya benchmark mengukur penulisan klik,
        // bukan pembuatan link. `code` hanya 7 karakter (CHAR(7)) — nilai lebih
        // panjang ditolak MariaDB, jadi jangan hardcode kode sepanjang 8.
        $ada = Link::query()->first();

        if ($ada !== null) {
            return $ada;
        }

        $user = User::query()->first() ?? User::factory()->create();

        return Link::createWithUniqueCode([
            'user_id' => $user->id,
            'destination' => 'https://example.com/bench',
        ]);
    }

    private function reset(Link $link): void
    {
        DB::table('click_events')->where('link_id', $link->id)->delete();
        DB::table('jobs')->delete();
    }

    private function attributes(Link $link): array
    {
        return [
            'link_id' => $link->id,
            'occurred_at' => now(),
            'occurred_on' => DayWindow::localDateOf(CarbonImmutable::now()),
            'visitor_hash' => Str::lower(Str::random(64)),
            'device_type' => 'desktop',
            'browser_family' => 'chrome',
            'os_family' => 'linux',
            'is_bot' => false,
            'source' => 'web',
            'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64) Chrome/120.0.0.0 Safari/537.36',
        ];
    }

    /** Mode A: satu INSERT per klik, sinkron (jalur yang gw pakai di v1). */
    private function benchSync(Link $link, int $n): float
    {
        $t0 = microtime(true);

        for ($i = 0; $i < $n; $i++) {
            ClickEvent::create($this->attributes($link));
        }

        return (microtime(true) - $t0) * 1000;
    }

    /**
     * Mode B: dispatch ke queue database lalu kerjakan.
     * Dihitung SAMPAI event benar-benar tertulis — kalau hanya mengukur dispatch-nya,
     * benchmark ini akan menipu: kerja sesungguhnya masih di depan.
     */
    private function benchQueue(Link $link, int $n): float
    {
        $t0 = microtime(true);

        for ($i = 0; $i < $n; $i++) {
            dispatch(new LogClickJob($this->attributes($link)));
        }

        // Kerjakan semua job, selesaikan sebelum mengukur.
        $worker = Queue::connection();
        for ($i = 0; $i < $n; $i++) {
            $job = $worker->pop(0);

            if ($job !== null) {
                $job->fire();
            }
        }

        return (microtime(true) - $t0) * 1000;
    }

    /** Mode C: satu insert multi-baris (jalan tengah yang masuk akal). */
    private function benchBatch(Link $link, int $n): float
    {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = $this->attributes($link) + ['created_at' => now()];
        }

        $t0 = microtime(true);
        DB::table('click_events')->insert($rows);

        return (microtime(true) - $t0) * 1000;
    }
}
