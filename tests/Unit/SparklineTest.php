<?php

use App\Support\Analytics\Sparkline;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Chart SVG dibuat sendiri, tanpa pustaka (spec §9).
 *
 * Kelas murni: angka masuk, geometri keluar. Semua kasus batas yang biasanya
 * merusak grafik di produksi diuji di sini — data kosong, satu titik, semua nol,
 * lonjakan ekstrem, dan nilai negatif.
 */
final class SparklineTest extends TestCase
{
    public function test_data_kosong_tidak_meledak(): void
    {
        $svg = Sparkline::line([]);

        self::assertStringContainsString('<svg', $svg);
        self::assertStringContainsString('</svg>', $svg);
    }

    /**
     * Semua nilai nol -> TIDAK boleh ada pembagian dengan nol (puncak-0).
     * Ini penyebab klasik grafik hilang total setelah seharian tanpa trafik.
     */
    public function test_semua_nol_tidak_membagi_nol(): void
    {
        $svg = Sparkline::line([0, 0, 0, 0]);

        self::assertStringContainsString('<svg', $svg);
        self::assertStringNotContainsString('NAN', strtoupper($svg));
        self::assertStringNotContainsString('INF', strtoupper($svg));
        self::assertStringNotContainsString('e+', $svg);
    }

    public function test_satu_titik_tetap_menghasilkan_garis_sah(): void
    {
        $svg = Sparkline::line([42]);

        self::assertStringContainsString('<svg', $svg);
        self::assertStringNotContainsString('NAN', strtoupper($svg));
    }

    /** Nilai negatif (mis. drift) tidak boleh menghasilkan koordinat aneh. */
    public function test_nilai_negatif_ditangani(): void
    {
        $svg = Sparkline::line([-5, 0, 5]);

        self::assertStringNotContainsString('NAN', strtoupper($svg));
        self::assertMatchesRegularExpression('/points="[0-9.,\s-]+"/', $svg);
    }

    /** Puncak mendominasi: nilai kecil harus tetap punya posisi yang sah. */
    public function test_lonjakan_ekstrem(): void
    {
        $svg = Sparkline::line([0, 0, 1000000, 0]);

        self::assertStringNotContainsString('NAN', strtoupper($svg));
        self::assertStringNotContainsString('-0.000', $svg);
    }

    /** Jumlah titik sesuai jumlah nilai. */
    public function test_jumlah_titik_sesuai_data(): void
    {
        $svg = Sparkline::line([1, 2, 3, 4, 5, 6, 7]);

        $points = [];
        preg_match('/points="([^"]+)"/', $svg, $points);
        self::assertNotEmpty($points, 'tidak ada polyline points');

        $koordinat = array_filter(explode(' ', trim($points[1])));
        self::assertCount(7, $koordinat);
    }

    /** Dimensi SVG dapat dikonfigurasi (dipakai untuk sparkline kecil & chart besar). */
    public function test_dimensi_dapat_diatur(): void
    {
        $svg = Sparkline::line([1, 2, 3], width: 200, height: 50);

        self::assertStringContainsString('viewBox="0 0 200 50"', $svg);
        self::assertStringContainsString('width="200"', $svg);
    }

    /**
     * A11y: grafik harus punya peran & label, karena pembaca layar tidak bisa
     * membaca path. Tanpa ini, informasi grafik hilang total bagi sebagian pengguna.
     */
    public function test_punya_peran_dan_label_aksesibilitas(): void
    {
        $svg = Sparkline::line([1, 2, 3], label: 'Klik 7 hari terakhir');

        self::assertStringContainsString('role="img"', $svg);
        self::assertStringContainsString('Klik 7 hari terakhir', $svg);
        self::assertStringContainsString('<title', $svg);
    }

    /** Nilai tidak sah (null/string) tidak boleh membuat geometri rusak. */
    #[DataProvider('dataKotor')]
    public function test_data_kotor_ditangani(array $data): void
    {
        $svg = Sparkline::line($data);

        self::assertStringNotContainsString('NAN', strtoupper($svg));
        self::assertStringContainsString('<svg', $svg);
    }

    public static function dataKotor(): array
    {
        return [
            'string angka' => [['1', '2', '3']],
            'null' => [[null, 5, null]],
            'campur' => [[1, null, '3', 4.7]],
            'float' => [[1.5, 2.25, 3.125]],
        ];
    }

    /** Semua koordinat harus berada di dalam viewBox (tidak overflow). */
    public function test_koordinat_tidak_keluar_viewbox(): void
    {
        $svg = Sparkline::line([0, 50, 100, 25], width: 120, height: 40);

        preg_match('/points="([^"]+)"/', $svg, $m);
        self::assertNotEmpty($m);

        foreach (array_filter(explode(' ', trim($m[1]))) as $pair) {
            [$x, $y] = array_map('floatval', explode(',', $pair));
            self::assertGreaterThanOrEqual(0, $x);
            self::assertLessThanOrEqual(120, $x);
            self::assertGreaterThanOrEqual(0, $y);
            self::assertLessThanOrEqual(40, $y);
        }
    }

    /** Warna memakai currentColor supaya tema mengikuti token, bukan hex liar. */
    public function test_memakai_currentcolor_bukan_hex_liar(): void
    {
        $svg = Sparkline::line([1, 2, 3]);

        self::assertStringContainsString('currentColor', $svg);
        self::assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{6}/', $svg,
            'warna harus dari token tema, bukan hex yang di-hardcode di kelas');
    }
}
