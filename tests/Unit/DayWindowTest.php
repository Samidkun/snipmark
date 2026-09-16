<?php

use App\Support\Analytics\DayWindow;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Batas hari adalah salah satu tempat paling mudah menghasilkan angka yang
 * salah tanpa error: selisih satu hari penuh di SETIAP ujung rentang.
 *
 * WIB = UTC+7. Jadi hari WIB `D` sebenarnya membentang dari 17:00 UTC hari
 * sebelumnya sampai 17:00 UTC hari `D`. Kesalahan offset di sini membuat
 * "klik 90 hari terakhir" kehilangan atau menambah 7 jam data di kedua ujung.
 */
final class DayWindowTest extends TestCase
{
    public function test_rentang_utc_untuk_satu_hari_wib(): void
    {
        $w = DayWindow::forLocalDate('2026-09-16');

        self::assertSame('2026-09-15 17:00:00', $w->startUtc->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-16 17:00:00', $w->endUtc->format('Y-m-d H:i:s'));
    }

    public function test_rentang_selalu_tepat_24_jam(): void
    {
        $w = DayWindow::forLocalDate('2026-09-16');

        self::assertEqualsWithDelta(24.0, $w->startUtc->diffInHours($w->endUtc), 0.001);
    }

    /** Batas: 23:30 WIB masih hari ini; 00:30 WIB hari berikutnya sudah besok. */
    public function test_klik_2330_wib_masuk_hari_itu(): void
    {
        $w = DayWindow::forLocalDate('2026-09-16');

        $klik = CarbonImmutable::parse('2026-09-16 23:30:00', 'Asia/Jakarta');

        self::assertTrue($w->contains($klik), '23:30 WIB harus masuk hari 16');
    }

    public function test_klik_0030_wib_besoknya_tidak_masuk_hari_itu(): void
    {
        $w = DayWindow::forLocalDate('2026-09-16');

        $klik = CarbonImmutable::parse('2026-09-17 00:30:00', 'Asia/Jakarta');

        self::assertFalse($w->contains($klik),
            '00:30 WIB tanggal 17 adalah hari 17, bukan hari 16 — ini jebakan offset');
    }

    /** Tepat di batas awal: inklusif. Tepat di batas akhir: eksklusif. */
    public function test_batas_awal_inklusif_batas_akhir_eksklusif(): void
    {
        $w = DayWindow::forLocalDate('2026-09-16');

        self::assertTrue($w->contains($w->startUtc), 'batas awal harus inklusif');
        self::assertFalse($w->contains($w->endUtc),
            'batas akhir harus eksklusif, kalau tidak event 17:00 UTC dihitung dua kali');
    }

    /**
     * Bukti bahwa offset DIAMBIL DARI TIMEZONE, bukan angka -7 yang di-hardcode.
     *
     * Cara mengujinya bukan dengan membaca getOffset() pada nilai yang sudah
     * dikonversi ke UTC (selalu 0 — bukan informasi). Cara yang benar: minta
     * rentang untuk zona dengan offset BERBEDA dan pastikan batas UTC-nya
     * ikut bergeser. Kalau seseorang menulis `-7` hardcode, test ini gagal.
     */
    public function test_batas_utc_ikut_berubah_saat_timezone_berbeda(): void
    {
        $jakarta = DayWindow::forLocalDate('2026-09-16', 'Asia/Jakarta');   // UTC+7
        $makassar = DayWindow::forLocalDate('2026-09-16', 'Asia/Makassar'); // UTC+8

        self::assertSame('2026-09-15 17:00:00', $jakarta->startUtc->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-15 16:00:00', $makassar->startUtc->format('Y-m-d H:i:s'),
            'batas UTC tidak bergeser saat zona berubah — offset kemungkinan di-hardcode');
        self::assertSame('Asia/Makassar', $makassar->timezone);
    }

    /** Zona default adalah WIB, dan itu dipakai bila tidak disebutkan. */
    public function test_zona_default_adalah_wib(): void
    {
        self::assertSame('Asia/Jakarta', DayWindow::DEFAULT_TIMEZONE);
        self::assertSame('Asia/Jakarta', DayWindow::forLocalDate('2026-09-16')->timezone);
    }

    public function test_tanggal_lokal_diambil_dari_instan_utc(): void
    {
        // 2026-09-16 16:30 UTC = 23:30 WIB tanggal 16
        $a = CarbonImmutable::parse('2026-09-16 16:30:00', 'UTC');
        self::assertSame('2026-09-16', DayWindow::localDateOf($a));

        // 2026-09-16 17:30 UTC = 00:30 WIB tanggal 17
        $b = CarbonImmutable::parse('2026-09-16 17:30:00', 'UTC');
        self::assertSame('2026-09-17', DayWindow::localDateOf($b));
    }

    public function test_rentang_beberapa_hari_mencakup_tepat_n_hari(): void
    {
        $r = DayWindow::forLocalRange('2026-09-10', '2026-09-16');

        self::assertSame(7, $r->days());
        self::assertSame('2026-09-09 17:00:00', $r->startUtc->format('Y-m-d H:i:s'));
        self::assertSame('2026-09-16 17:00:00', $r->endUtc->format('Y-m-d H:i:s'));
    }

    public function test_rentang_satu_hari_sama_dengan_for_local_date(): void
    {
        $a = DayWindow::forLocalDate('2026-09-16');
        $b = DayWindow::forLocalRange('2026-09-16', '2026-09-16');

        self::assertTrue($a->startUtc->equalTo($b->startUtc));
        self::assertTrue($a->endUtc->equalTo($b->endUtc));
    }

    /** Rentang terbalik harus melempar, bukan diam-diam menghasilkan nol. */
    public function test_rentang_terbalik_melempar(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DayWindow::forLocalRange('2026-09-16', '2026-09-10');
    }

    /** Daftar tanggal untuk rollup berurutan dan inklusif di kedua ujung. */
    public function test_daftar_tanggal_inklusif_dua_ujung(): void
    {
        $dates = DayWindow::datesBetween('2026-09-14', '2026-09-16');

        self::assertSame(['2026-09-14', '2026-09-15', '2026-09-16'], $dates);
    }

    public function test_daftar_tanggal_satu_hari(): void
    {
        self::assertSame(['2026-09-16'], DayWindow::datesBetween('2026-09-16', '2026-09-16'));
    }
}
