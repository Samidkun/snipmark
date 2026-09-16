<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Link;
use App\Models\LinkDailyRollup;
use App\Support\Analytics\DayWindow;
use App\Support\Analytics\RollupAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Mesin rollup — MAHAKARYA project ini (spec §5).
 *
 * TIGA SIFAT YANG MEMBUATNYA BUKAN "rollup biasa":
 *
 * 1. IDEMPOTEN BY FULL RECOMPUTE.
 *    Setiap hari dihitung ULANG dari `click_events`, lalu di-upsert. Tidak ada
 *    `+= delta`. Alasan: operasi delta rapuh — satu job gagal di tengah membuat
 *    angka rusak permanen dan tanpa gejala. Full recompute membuat kesalahan
 *    operasional tidak berbahaya: jalankan ulang, hasilnya benar.
 *
 * 2. RECONCILIATION = SELF-HEALING.
 *    `reconcile()` membandingkan angka tercatat (counter denormalized & rollup)
 *    dengan kenyataan (COUNT atas event mentah), melaporkan drift, dan atas
 *    permintaan memperbaikinya. Counter denormalized PASTI pernah melenceng;
 *    yang membedakan sistem serius adalah ia tahu dan bisa memperbaiki diri.
 *
 * 3. BATAS HARI DALAM ZONA ANALITIK.
 *    Hari dihitung di `Asia/Jakarta`, bukan UTC (spec §4.4). Ini menghindari
 *    pergeseran satu hari di setiap ujung rentang — bug yang tidak menimbulkan
 *    error apa pun.
 */
final class RollupService
{
    public function __construct(
        private readonly RollupAggregator $aggregator,
    ) {}

    /**
     * Menghitung ulang rollup untuk SATU tanggal lokal.
     *
     * @param  string  $localDate  Y-m-d pada zona analitik (WIB)
     * @param  int|null  $linkId  batasi ke satu link
     * @return int jumlah baris rollup yang ditulis
     */
    public function rollupDay(string $localDate, ?int $linkId = null): int
    {
        $window = DayWindow::forLocalDate($localDate, $this->timezone());

        // Ambil SEMUA event hari itu sekaligus. Untuk hari yang sangat sibuk ini
        // bisa besar; alternatifnya adalah agregasi di SQL (GROUP BY) yang jauh
        // lebih cepat tetapi memindahkan logika ke query dan sulit diuji.
        // Keputusan v1: benar & teruji dulu; `--link` tersedia untuk mempersempit.
        // Batas ini dicatat di docs/benchmarks.md.
        $query = DB::table('click_events')
            ->select([
                'link_id', 'is_bot', 'visitor_hash',
                'device_type', 'browser_family', 'os_family', 'referrer_host',
            ])
            ->where('occurred_on', $localDate);

        if ($linkId !== null) {
            $query->where('link_id', $linkId);
        }

        $grouped = [];
        foreach ($query->cursor() as $row) {
            $grouped[$row->link_id][] = (array) $row;
        }

        if ($grouped === []) {
            return 0;
        }

        $now = CarbonImmutable::now('UTC');
        $written = 0;

        DB::transaction(function () use ($grouped, $localDate, $now, &$written) {
            foreach ($grouped as $id => $events) {
                $result = $this->aggregator->aggregate($events);

                // Upsert pada UNIQUE(link_id, date) — inilah penegak idempotensi.
                // Menggunakan updateOrCreate agar nilai lama DITIMPA, bukan
                // ditambahkan; itulah bedanya dengan `+= delta`.
                LinkDailyRollup::query()->updateOrCreate(
                    ['link_id' => $id, 'date' => $localDate],
                    [
                        ...$result->toArray(),
                        'computed_at' => $now,
                    ],
                );

                $written++;
            }
        });

        return $written;
    }

    /**
     * Menghitung ulang rollup untuk rentang tanggal lokal, INKLUSIF di dua ujung.
     *
     * @return int jumlah hari yang berhasil diproses
     */
    public function rollupRange(string $fromLocalDate, string $toLocalDate, ?int $linkId = null): int
    {
        $processed = 0;

        foreach (DayWindow::datesBetween($fromLocalDate, $toLocalDate, $this->timezone()) as $date) {
            $this->rollupDay($date, $linkId);
            $processed++;
        }

        return $processed;
    }

    /**
     * Membandingkan angka tercatat dengan kenyataan, dan (opsional) memperbaikinya.
     *
     * Dua jenis drift yang diperiksa:
     *  a) `links.total_clicks` (counter denormalized) vs COUNT(click_events)
     *  b) `link_daily_rollups.total` (agregat basi) vs COUNT(click_events) hari itu
     *
     * @param  bool  $fix  perbaiki drift yang ditemukan
     */
    public function reconcile(
        string $fromLocalDate,
        string $toLocalDate,
        bool $fix = false,
        ?int $linkId = null,
    ): ReconcileReport {
        $found = $this->findDrifts($fromLocalDate, $toLocalDate, $linkId);

        if (! $fix || $found === []) {
            return new ReconcileReport($found);
        }

        $this->repair($found, $fromLocalDate, $toLocalDate);

        /*
         * PENTING: laporan mengembalikan drift yang DITEMUKAN, bukan hasil
         * pemindaian ulang. Alasannya:
         *
         *  - Pemanggil ingin tahu apa yang tadi salah. Mengembalikan [] setelah
         *    perbaikan berhasil menyembunyikan informasi itu.
         *  - Kalau perbaikan GAGAL, pemindaian ulang akan mengembalikan daftar
         *    yang sama dan pemanggil tidak bisa membedakan "tidak ada masalah"
         *    dari "masalahnya tidak terselesaikan" — kecuali dengan menghitung
         *    ulang dan membandingkan, yang justru membingungkan.
         *
         * Karena itu: `drifts` = temuan, `fixed` = jumlah yang ditangani, dan
         * pemeriksaan kebersihan dilakukan dengan memanggil reconcile() sekali
         * lagi (diuji oleh RollupServiceTest).
         */
        return new ReconcileReport($found, fixed: count($found));
    }

    /**
     * Memindai drift TANPA mengubah apa pun.
     *
     * @return list<array{type: string, link_id: int, date: ?string, recorded: int, actual: int}>
     */
    private function findDrifts(string $fromLocalDate, string $toLocalDate, ?int $linkId): array
    {
        $drifts = [];

        // (a) counter denormalized vs kenyataan
        $counter = DB::table('links')
            ->leftJoin('click_events', 'click_events.link_id', '=', 'links.id')
            ->selectRaw('links.id as link_id, links.total_clicks as recorded, COUNT(click_events.id) as actual')
            ->groupBy('links.id', 'links.total_clicks')
            ->havingRaw('links.total_clicks <> COUNT(click_events.id)');

        if ($linkId !== null) {
            $counter->where('links.id', $linkId);
        }

        foreach ($counter->get() as $row) {
            $drifts[] = [
                'type' => 'total_clicks',
                'link_id' => (int) $row->link_id,
                'date' => null,
                'recorded' => (int) $row->recorded,
                'actual' => (int) $row->actual,
            ];
        }

        // (b) agregat basi: rollup vs jumlah event hari itu
        $rollup = DB::table('link_daily_rollups as r')
            ->leftJoin('click_events as e', function ($join) {
                $join->on('e.link_id', '=', 'r.link_id')
                    ->on('e.occurred_on', '=', 'r.date');
            })
            ->whereBetween('r.date', [$fromLocalDate, $toLocalDate])
            ->selectRaw('r.link_id, r.date, r.total as recorded, COUNT(e.id) as actual')
            ->groupBy('r.link_id', 'r.date', 'r.total')
            ->havingRaw('r.total <> COUNT(e.id)');

        if ($linkId !== null) {
            $rollup->where('r.link_id', $linkId);
        }

        foreach ($rollup->get() as $row) {
            $drifts[] = [
                'type' => 'rollup_total',
                'link_id' => (int) $row->link_id,
                'date' => (string) $row->date,
                'recorded' => (int) $row->recorded,
                'actual' => (int) $row->actual,
            ];
        }

        return $drifts;
    }

    /**
     * Memperbaiki drift. Dipanggil HANYA saat `--fix`.
     *
     * @param  list<array{type: string, link_id: int, date: ?string, recorded: int, actual: int}>  $drifts
     */
    private function repair(array $drifts, string $fromLocalDate, string $toLocalDate): void
    {
        $service = $this;   // dipakai di dalam closure tanpa bergantung binding $this

        DB::transaction(function () use ($drifts, $service) {
            // Agregat basi: hitung ULANG hari yang melenceng lebih dulu, karena
            // rollup ulang bisa mengubah apa yang seharusnya jadi nilai counter.
            $tanggalRusak = collect($drifts)
                ->where('type', 'rollup_total')
                ->pluck('date')
                ->unique()
                ->values();

            foreach ($tanggalRusak as $date) {
                $service->rollupDay($date);
            }

            // Counter denormalized: setel ke nilai sebenarnya.
            $linkIds = collect($drifts)->pluck('link_id')->unique()->values();

            foreach ($linkIds as $id) {
                $actual = DB::table('click_events')->where('link_id', $id)->count();

                Link::withTrashed()->whereKey($id)->update(['total_clicks' => $actual]);
            }
        });
    }

    private function timezone(): string
    {
        return config('snipmark.analytics_timezone', DayWindow::DEFAULT_TIMEZONE);
    }
}
