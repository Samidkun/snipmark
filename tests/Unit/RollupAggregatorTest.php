<?php

use App\Models\LinkDailyRollup as RollupConst;
use App\Support\Analytics\RollupAggregator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Agregator adalah kelas MURNI: menerima baris event, mengembalikan angka.
 * Tanpa DB, sehingga properti penting (pemisahan bot, unique visitor, pemotongan
 * dimensi) bisa diuji terhadap distribusi yang hasilnya kita ketahui.
 */
final class RollupAggregatorTest extends TestCase
{
    private RollupAggregator $agg;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agg = new RollupAggregator;
    }

    /** @param array<string, mixed> $over */
    private function event(array $over = []): array
    {
        return array_merge([
            'is_bot' => false,
            'visitor_hash' => 'v-'.str_repeat('a', 62),
            'device_type' => 'desktop',
            'browser_family' => 'chrome',
            'os_family' => 'windows',
            'referrer_host' => null,
        ], $over);
    }

    public function test_himpunan_kosong_menghasilkan_nol_semua(): void
    {
        $r = $this->agg->aggregate([]);

        self::assertSame(0, $r->total);
        self::assertSame(0, $r->human);
        self::assertSame(0, $r->bots);
        self::assertSame(0, $r->uniqueVisitors);
        self::assertSame([], $r->byDevice);
        self::assertSame([], $r->byBrowser);
        self::assertSame([], $r->byOs);
        self::assertSame([], $r->byReferrer);
    }

    /** total = human + bots. Selalu. Ini invariant, bukan kebetulan. */
    public function test_total_selalu_human_ditambah_bots(): void
    {
        $events = [];
        for ($i = 0; $i < 7; $i++) {
            $events[] = $this->event(['visitor_hash' => "v{$i}"]);
        }
        for ($i = 0; $i < 3; $i++) {
            $events[] = $this->event(['is_bot' => true, 'visitor_hash' => "b{$i}"]);
        }

        $r = $this->agg->aggregate($events);

        self::assertSame(10, $r->total);
        self::assertSame(7, $r->human);
        self::assertSame(3, $r->bots);
        self::assertSame($r->human + $r->bots, $r->total);
    }

    /**
     * Unique visitor = distinct visitor_hash MANUSIA saja.
     * Kalau bot ikuthitungan, satu scanner dengan 10.000 request bisa
     * menggelembungkan "pengunjung unik" — dan itu tidak akan pernah
     * terlihat sebagai error.
     */
    public function test_unique_visitor_hanya_manusia_dan_distinct(): void
    {
        $events = [
            $this->event(['visitor_hash' => 'sama1']),
            $this->event(['visitor_hash' => 'sama1']),
            $this->event(['visitor_hash' => 'sama1']),
            $this->event(['visitor_hash' => 'beda2']),
            $this->event(['is_bot' => true, 'visitor_hash' => 'bot-1']),
            $this->event(['is_bot' => true, 'visitor_hash' => 'bot-1']),
        ];

        $r = $this->agg->aggregate($events);

        self::assertSame(2, $r->uniqueVisitors, 'bot tidak boleh ikut hitung unique visitor');
        self::assertSame(6, $r->total);
    }

    /** Rincian dimensi MENGHITUNG MANUSIA SAJA (keputusan desain, lihat docblock). */
    public function test_dimensi_menghitung_manusia_saja(): void
    {
        $events = [
            $this->event(['device_type' => 'mobile', 'browser_family' => 'chrome']),
            $this->event(['device_type' => 'mobile', 'browser_family' => 'chrome']),
            $this->event(['device_type' => 'desktop', 'browser_family' => 'firefox']),
            $this->event(['is_bot' => true, 'device_type' => 'desktop', 'browser_family' => 'chrome']),
        ];

        $r = $this->agg->aggregate($events);

        self::assertSame(['mobile' => 2, 'desktop' => 1], $r->byDevice);
        self::assertSame(['chrome' => 2, 'firefox' => 1], $r->byBrowser);
        self::assertSame(3, array_sum($r->byDevice), 'dimensi harus menjumlah ke human, bukan total');
    }

    /** Dimensi diurutkan menurun — UI mengandalkan urutan ini. */
    public function test_dimensi_diurutkan_menurun(): void
    {
        $events = [];
        foreach (['chrome' => 5, 'firefox' => 9, 'safari' => 2] as $b => $n) {
            for ($i = 0; $i < $n; $i++) {
                $events[] = $this->event(['browser_family' => $b]);
            }
        }

        $r = $this->agg->aggregate($events);

        self::assertSame(['firefox', 'chrome', 'safari'], array_keys($r->byBrowser));
        self::assertSame([9, 5, 2], array_values($r->byBrowser));
    }

    /** Tanpa referrer => '(direct)', bukan key kosong/null. */
    public function test_referrer_kosong_menjadi_direct(): void
    {
        $r = $this->agg->aggregate([
            $this->event(['referrer_host' => null]),
            $this->event(['referrer_host' => '']),
            $this->event(['referrer_host' => '   ']),
            $this->event(['referrer_host' => 'google.com']),
        ]);

        self::assertSame([RollupConst::DIRECT_REFERRER => 3, 'google.com' => 1], $r->byReferrer);
    }

    /** Referrer dinormalisasi: www. dan huruf besar bukan dimensi berbeda. */
    public function test_referrer_dinormalisasi(): void
    {
        $r = $this->agg->aggregate([
            $this->event(['referrer_host' => 'www.Google.com']),
            $this->event(['referrer_host' => 'google.com']),
            $this->event(['referrer_host' => 'GOOGLE.COM']),
        ]);

        self::assertSame(['google.com' => 3], $r->byReferrer);
    }

    /**
     * BATAS DIMENSI (spec §4.4): maksimum 25 kunci, sisanya '(other)'.
     * Tanpa batas, satu link yang dibagikan di 500 situs berbeda akan menyimpan
     * 500 kunci JSON — rollup bengkak dan tidak terbaca.
     */
    public function test_dimensi_dipotong_ke_maks_25_dan_sisanya_other(): void
    {
        $events = [];
        // 30 referrer: 'r0' paling sering (100), lalu r1..r29 masing-masing menurun.
        for ($i = 0; $i < 100; $i++) {
            $events[] = $this->event(['referrer_host' => 'r0.test']);
        }
        for ($r1 = 1; $r1 <= 29; $r1++) {
            for ($i = 0; $i < 30 - $r1; $i++) {
                $events[] = $this->event(['referrer_host' => "r{$r1}.test"]);
            }
        }

        $res = $this->agg->aggregate($events);

        $keys = array_keys($res->byReferrer);
        self::assertCount(26, $keys, '25 kunci teratas + (other)');
        self::assertSame('r0.test', $keys[0]);
        self::assertContains('(other)', $keys);

        // TIDAK BOLEH ada angka yang hilang: jumlah dimensi = human.
        self::assertSame($res->human, array_sum($res->byReferrer),
            'pemotongan dimensi tidak boleh menjatuhkan event');
        self::assertSame($res->human, array_sum($res->byBrowser));
    }

    /** Konsistensi: setiap dimensi menjumlah ke human. */
    public function test_semua_dimensi_jumlahnya_sama_dengan_human(): void
    {
        $events = [];
        foreach (['mobile', 'desktop', 'tablet'] as $d) {
            foreach (['chrome', 'firefox'] as $b) {
                foreach (['android', 'linux'] as $o) {
                    $events[] = $this->event(['visitor_hash' => "v{$d}{$b}{$o}", 'device_type' => $d, 'browser_family' => $b, 'os_family' => $o]);
                    $events[] = $this->event(['visitor_hash' => "v{$d}{$b}{$o}", 'device_type' => $d, 'browser_family' => $b, 'os_family' => $o]);
                }
            }
        }
        $events[] = $this->event(['is_bot' => true]);

        $r = $this->agg->aggregate($events);

        self::assertSame($r->human, array_sum($r->byDevice));
        self::assertSame($r->human, array_sum($r->byBrowser));
        self::assertSame($r->human, array_sum($r->byOs));
        self::assertSame($r->human, array_sum($r->byReferrer));
    }

    /** Event dengan dimensi null/tidak dikenal harus tetap tertampung. */
    public function test_dimensi_null_ditampung_tanpa_menghilangkan_event(): void
    {
        $events = [
            $this->event(['device_type' => 'other', 'browser_family' => 'other']),
            $this->event(['device_type' => null, 'browser_family' => null, 'os_family' => null]),
        ];

        $r = $this->agg->aggregate($events);

        self::assertSame(2, $r->total);
        self::assertSame(2, array_sum($r->byDevice));
        self::assertSame(2, array_sum($r->byBrowser));
    }

    /**
     * IDEMPOTENSI tingkat kelas: input sama => output sama, dan urutan input
     * TIDAK boleh mengubah hasil. Ini fondasi §5.1 — rollup bisa dijalankan
     * ulang kapan pun tanpa menghasilkan angka berbeda.
     */
    public function test_urutan_input_tidak_mengubah_hasil(): void
    {
        $events = [];
        foreach (['a' => 'chrome', 'b' => 'firefox', 'c' => 'chrome'] as $v => $b) {
            $events[] = $this->event(['visitor_hash' => $v, 'browser_family' => $b]);
        }

        $maju = $this->agg->aggregate($events);
        $balik = $this->agg->aggregate(array_reverse($events));

        self::assertSame($maju->total, $balik->total);
        self::assertSame($maju->human, $balik->human);
        self::assertSame($maju->uniqueVisitors, $balik->uniqueVisitors);
        self::assertSame($maju->byBrowser, $balik->byBrowser);
    }

    /** Nilai dapat diserialisasi ke JSON bulat (kolom json di skema). */
    public function test_hasil_siap_diserialisasi_ke_json_skema(): void
    {
        $r = $this->agg->aggregate([$this->event(['referrer_host' => 'x.com'])]);

        $encoded = json_encode($r->toArray(), JSON_THROW_ON_ERROR);

        self::assertIsString($encoded);
        $decoded = json_decode($encoded, true);
        self::assertSame($r->toArray(), $decoded, 'toArray harus stabil lewat encode/decode');
    }

    #[DataProvider('badInput')]
    public function test_baris_rusak_ditolak_diam_diam_tidak_dipaksakan(mixed $bad, string $kenapa): void
    {
        $r = $this->agg->aggregate([$this->event(), $bad]);

        // Baris rusak TIDAK boleh membuat baris valid ikut hilang.
        self::assertSame(1, $r->total, $kenapa);
    }

    public static function badInput(): array
    {
        return [
            'bukan array' => [
                'bukan array',
                'baris non-array harus diabaikan',
            ],
        ];
    }
}
