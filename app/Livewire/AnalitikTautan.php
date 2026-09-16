<?php

namespace App\Livewire;

use App\Models\ClickEvent;
use App\Models\Link;
use App\Models\LinkDailyRollup;
use App\Support\Analytics\DayWindow;
use App\Support\Analytics\Sparkline;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Halaman analitik satu tautan.
 *
 * STRATEGI PEMBACAAN HYBRID (spec §5.3) — ini bagian yang membuat halaman tetap
 * cepat saat `click_events` menembus jutaan baris:
 *
 *   - Hari-hari LAMPAU  -> dibaca dari `link_daily_rollups` (satu baris per hari,
 *     sudah teragregasi). O(90) baris, bukan O(1.000.000).
 *   - HARI INI          -> dibaca dari `click_events` mentah, karena rollupnya
 *     belum ada (rollup berjalan untuk hari yang sudah selesai).
 *
 * Konsekuensi yang DIDOKUMENTASIKAN, bukan disembunyikan: angka "hari ini" adalah
 * nilai berjalan dan bisa berbeda tipis dari nilai rollup setelah hari berakhir.
 */
#[Layout('layouts.app')]
class AnalitikTautan extends Component
{
    /** Rentang yang tersedia. 7/30/90 hari. */
    public const RENTANG = [7, 30, 90];

    #[Url(as: 'hari', except: 30)]
    public int $hari = 30;

    /** Sertakan bot dalam angka? Default TIDAK — itu titik jualnya. */
    #[Url(as: 'bot', except: false)]
    public bool $sertakanBot = false;

    public Link $link;

    public function mount(Link $link): void
    {
        // Otorisasi didelegasikan ke LinkPolicy (spec §10). Aturan kepemilikan
        // hidup di SATU tempat, bukan disalin di sini dan di TautanIndex.
        Gate::authorize('view', $link);

        $this->link = $link;

        if (! in_array($this->hari, self::RENTANG, true)) {
            $this->hari = 30;
        }
    }

    public function setRentang(int $hari): void
    {
        if (in_array($hari, self::RENTANG, true)) {
            $this->hari = $hari;
        }
    }

    public function render(): View
    {
        $tz = config('snipmark.analytics_timezone', DayWindow::DEFAULT_TIMEZONE);
        $hariIni = DayWindow::today($tz);
        $mulai = \Carbon\CarbonImmutable::now($tz)->subDays($this->hari - 1)->format('Y-m-d');

        return view('livewire.analitik-tautan', [
            'deret' => $this->deretHarian($mulai, $hariIni, $tz),
            'rincian' => $this->rincianDimensi($mulai, $hariIni),
            'angka' => $this->angkaRingkas($mulai, $hariIni),
            'hariIni' => $hariIni,
            'labelHariIni' => \Carbon\CarbonImmutable::now($tz)->translatedFormat('j F Y'),
        ]);
    }

    /**
     * Deret harian untuk grafik: rollup (hari lampau) + event mentah (hari ini).
     *
     * @return array{label: array<int, string>, nilai: array<int, int>, svg: string}
     */
    private function deretHarian(string $mulai, string $hariIni, string $tz): array
    {
        $kolom = $this->sertakanBot ? 'total' : 'human';

        // Hari LAMPAU dari rollup: satu query, satu baris per hari.
        $dariRollup = LinkDailyRollup::query()
            ->where('link_id', $this->link->id)
            ->whereBetween('date', [$mulai, $hariIni])
            ->where('date', '<', $hariIni)
            ->orderBy('date')
            ->pluck($kolom, 'date');

        // HARI INI dari event mentah (rollupnya belum ada).
        $hariIniQuery = ClickEvent::query()
            ->where('link_id', $this->link->id)
            ->where('occurred_on', $hariIni);

        if (! $this->sertakanBot) {
            $hariIniQuery->where('is_bot', false);
        }

        $hariIniJumlah = $hariIniQuery->count();

        $label = [];
        $nilai = [];

        foreach (DayWindow::datesBetween($mulai, $hariIni, $tz) as $tanggal) {
            $label[] = $tanggal;
            $nilai[] = $tanggal === $hariIni
                ? $hariIniJumlah
                : (int) ($dariRollup[$tanggal] ?? 0);
        }

        return [
            'label' => $label,
            'nilai' => $nilai,
            'svg' => Sparkline::line($nilai, width: 720, height: 160, label: 'Klik harian'),
        ];
    }

    /**
     * Rincian dimensi: dari rollup untuk hari lampau, dari event untuk hari ini.
     *
     * @return array<string, array<string, int>>
     */
    private function rincianDimensi(string $mulai, string $hariIni): array
    {
        $kolomDimensi = [
            'device' => 'by_device',
            'browser' => 'by_browser',
            'os' => 'by_os',
            'referrer' => 'by_referrer',
        ];

        $hasil = array_fill_keys(array_keys($kolomDimensi), []);

        // Hari lampau: JSON siap-saji di rollup.
        foreach (LinkDailyRollup::query()
            ->where('link_id', $this->link->id)
            ->whereBetween('date', [$mulai, $hariIni])
            ->where('date', '<', $hariIni)
            ->get() as $rollup) {

            foreach ($kolomDimensi as $kunci => $kolom) {
                foreach (($rollup->{$kolom} ?? []) as $nilai => $jumlah) {
                    $hasil[$kunci][$nilai] = ($hasil[$kunci][$nilai] ?? 0) + $jumlah;
                }
            }
        }

        // Hari ini: dihitung dari event mentah.
        $query = ClickEvent::query()
            ->where('link_id', $this->link->id)
            ->where('occurred_on', $hariIni);

        if (! $this->sertakanBot) {
            $query->where('is_bot', false);
        }

        $hariIniBaris = $query->selectRaw(
            'device_type, browser_family, os_family, referrer_host, COUNT(*) as jumlah'
        )->groupBy('device_type', 'browser_family', 'os_family', 'referrer_host')->get();

        foreach ($hariIniBaris as $b) {
            $hasil['device'][$b->device_type] = ($hasil['device'][$b->device_type] ?? 0) + (int) $b->jumlah;
            $hasil['browser'][$b->browser_family] = ($hasil['browser'][$b->browser_family] ?? 0) + (int) $b->jumlah;
            $hasil['os'][$b->os_family] = ($hasil['os'][$b->os_family] ?? 0) + (int) $b->jumlah;

            $ref = $b->referrer_host ?: LinkDailyRollup::DIRECT_REFERRER;
            $hasil['referrer'][$ref] = ($hasil['referrer'][$ref] ?? 0) + (int) $b->jumlah;
        }

        // Urutkan menurun dan batasi tampilan supaya tabel tidak tak berujung.
        foreach ($hasil as $kunci => $baris) {
            arsort($baris);
            $hasil[$kunci] = array_slice($baris, 0, 10, true);
        }

        return $hasil;
    }

    /**
     * @return array<string, int>
     */
    private function angkaRingkas(string $mulai, string $hariIni): array
    {
        $rollup = LinkDailyRollup::query()
            ->where('link_id', $this->link->id)
            ->whereBetween('date', [$mulai, $hariIni])
            ->where('date', '<', $hariIni)
            ->selectRaw('SUM(total) as total, SUM(human) as human, SUM(bots) as bots, SUM(unique_visitors) as unik')
            ->first();

        $hariIni = ClickEvent::query()
            ->where('link_id', $this->link->id)
            ->where('occurred_on', $hariIni)
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN is_bot = 0 THEN 1 ELSE 0 END) as human')
            ->first();

        $total = (int) ($rollup->total ?? 0) + (int) ($hariIni->total ?? 0);
        $human = (int) ($rollup->human ?? 0) + (int) ($hariIni->human ?? 0);

        return [
            'total' => $total,
            'human' => $human,
            'bots' => $total - $human,
            'unik' => (int) ($rollup->unik ?? 0),
        ];
    }
}
