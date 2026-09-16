<?php

declare(strict_types=1);

namespace App\Support\Analytics;

/**
 * Chart SVG yang dibuat sendiri — TANPA pustaka (spec §9).
 *
 * Alasan tidak memakai pustaka chart: satu dependensi frontend berarti satu
 * rangkaian kerentanan, satu siklus rilis yang harus diikuti, dan satu format
 * data yang dipaksakan. Yang dibutuhkan di sini hanya polyline; itu ~40 baris
 * matematika, dan membuatnya sendiri menunjukkan pemahaman menyajikan data
 * alih-alih pemahaman memasang pustaka.
 *
 * Kelas MURNI: angka masuk, string SVG keluar. Tidak ada DB, tidak ada request,
 * tidak ada container — sehingga setiap kasus batas yang biasanya merusak grafik
 * di produksi (data kosong, semua nol, satu titik, lonjakan ekstrem) bisa diuji
 * dengan pasti.
 *
 * Tiga keputusan yang mencegah kerusakan yang paling sering terjadi:
 *  1. Rentang nol TIDAK memakai pembagian (puncak == dasar) -> grafik tidak
 *     pernah menghasilkan NAN.
 *  2. Semua nilai non-numerik diperlakukan sebagai 0, bukan diteruskan.
 *  3. Koordinat dijepit ke dalam viewBox, sehingga tidak ada elemen yang bocor
 *     keluar kanvas ketika rentangnya ekstrem.
 *
 * Warna memakai `currentColor` supaya mengikuti token tema, bukan hex yang
 * di-hardcode di dalam kelas.
 */
final class Sparkline
{
    /** Jarak tepi supaya titik tidak terpotong oleh sisi viewBox. */
    private const PADDING = 2.0;

    /**
     * @param  array<int, mixed>  $values
     */
    public static function line(
        array $values,
        int $width = 120,
        int $height = 36,
        ?string $label = null,
    ): string {
        $values = array_values($values);

        // Bersihkan: hanya angka yang dipakai; sisanya 0.
        $angka = array_map(
            static fn ($v) => is_numeric($v) ? (float) $v : 0.0,
            $values,
        );

        $points = self::points($angka, $width, $height);

        $title = $label !== null
            ? '<title>'.htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</title>'
            : '';

        $aria = $label !== null
            ? ' role="img" aria-label="'.htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'"'
            : '';

        return sprintf(
            '<svg viewBox="0 0 %1$d %2$d" width="%1$d" height="%2$d"%3$s xmlns="http://www.w3.org/2000/svg">%4$s'
            .'<polyline fill="none" stroke="currentColor" stroke-width="1.5" '
            .'stroke-linecap="round" stroke-linejoin="round" points="%5$s" /></svg>',
            $width,
            $height,
            $aria,
            $title,
            $points,
        );
    }

    /**
     * Mengubah nilai menjadi string "x,y x,y ...".
     *
     * Bila semua nilai sama (termasuk semua nol), rentangnya nol. Membagi dengan
     * nol menghasilkan NAN/INF, yang membuat SVG tidak sah dan grafik hilang
     * tanpa pesan error. Dalam kasus itu semua titik digambar di tengah tinggi.
     */
    private static function points(array $angka, int $width, int $height): string
    {
        $n = count($angka);

        if ($n === 0) {
            return '';
        }

        $puncak = max($angka);
        $dasar = min($angka);
        $rentang = $puncak - $dasar;

        $atas = self::PADDING;
        $bawah = max(self::PADDING, $height - self::PADDING);
        $tinggiBerguna = $bawah - $atas;

        // Satu titik: gambarkan di tengah supaya terlihat, bukan di tepi.
        if ($n === 1) {
            $y = $atas + $tinggiBerguna / 2;

            return sprintf('%s,%s', self::fmt($width / 2), self::fmt($y));
        }

        $langkah = $width / ($n - 1);
        $keluar = [];

        foreach ($angka as $i => $v) {
            $x = $i * $langkah;

            // Rentang nol -> garis datar di tengah (tidak ada pembagian nol).
            $rasio = $rentang > 0.0 ? ($v - $dasar) / $rentang : 0.5;
            $y = $atas + $tinggiBerguna * (1.0 - $rasio);

            // Jepit: jaga-jaga terhadap pembulatan float.
            $x = min((float) $width, max(0.0, $x));
            $y = min((float) $height, max(0.0, $y));

            $keluar[] = self::fmt($x).','.self::fmt($y);
        }

        return implode(' ', $keluar);
    }

    /**
     * Format angka untuk atribut SVG: maksimal 2 desimal, TANPA notasi ilmiah
     * (1.0E-5 tidak sah di dalam daftar points) dan tanpa "-0".
     */
    private static function fmt(float $v): string
    {
        $s = number_format($v, 2, '.', '');
        $s = rtrim(rtrim($s, '0'), '.');

        if ($s === '' || $s === '-0') {
            return '0';
        }

        return $s;
    }
}
