<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Batas hari dalam zona analitik, dinyatakan sebagai rentang UTC setengah-terbuka
 * `[startUtc, endUtc)`.
 *
 * KENAPA KELAS INI ADA
 * `occurred_at` disimpan UTC. Tetapi "hari" bagi manusia Indonesia adalah hari WIB.
 * WIB = UTC+7, jadi hari WIB `D` membentang dari **17:00 UTC hari sebelumnya**
 * sampai **17:00 UTC hari `D`**. Salah satu ujung saja keliru, dan setiap angka
 * "klik 7/30/90 hari terakhir" melenceng 7 jam di dua ujung — tanpa satu pun error,
 * tanpa satu pun test gagal, dan tanpa cara mudah menyadarinya.
 *
 * Offset diambil dari timezone (Carbon), BUKAN angka `-7` yang di-hardcode, supaya
 * benar bila zona dikonfigurasi berbeda.
 *
 * Rentang setengah-terbuka (akhir EKSKLUSIF) menghindari peristiwa yang dihitung
 * dua kali pada batas hari.
 *
 * Spec §4.4 / ADR-0004.
 */
final readonly class DayWindow
{
    /**
     * Zona default. Kelas ini SENGAJA tidak memanggil config() supaya benar-benar
     * murni dan bisa diuji tanpa boot Laravel. Pemanggil aplikasi wajib meneruskan
     * nilai `config('snipmark.analytics_timezone')` secara eksplisit.
     */
    public const DEFAULT_TIMEZONE = 'Asia/Jakarta';

    public function __construct(
        public CarbonImmutable $startUtc,
        public CarbonImmutable $endUtc,
        public string $timezone,
    ) {
        if (! $this->endUtc->greaterThan($this->startUtc)) {
            throw new InvalidArgumentException(
                'Rentang hari harus memiliki akhir setelah awal.'
            );
        }
    }

    /**
     * Rentang untuk SATU tanggal lokal.
     *
     * @param  string  $localDate  format Y-m-d pada zona analitik
     */
    public static function forLocalDate(string $localDate, ?string $timezone = null): self
    {
        return self::forLocalRange($localDate, $localDate, $timezone);
    }

    /**
     * Rentang yang mencakup tanggal lokal `from` sampai `to`, INKLUSIF di kedua ujung.
     */
    public static function forLocalRange(
        string $fromLocalDate,
        string $toLocalDate,
        ?string $timezone = null,
    ): self {
        $tz = $timezone ?? self::DEFAULT_TIMEZONE;

        $from = CarbonImmutable::createFromFormat('!Y-m-d', $fromLocalDate, $tz);
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $toLocalDate, $tz);

        if ($from === false || $to === false) {
            throw new InvalidArgumentException(
                "Tanggal tidak sah: {$fromLocalDate} .. {$toLocalDate}"
            );
        }

        if ($from->greaterThan($to)) {
            throw new InvalidArgumentException(
                "Rentang terbalik: {$fromLocalDate} .. {$toLocalDate}"
            );
        }

        return new self(
            startUtc: $from->utc(),
            // Akhir eksklusif: awal hari SETELAH `to`.
            endUtc: $to->addDay()->utc(),
            timezone: $tz,
        );
    }

    /** Apakah satu instan (UTC) termasuk dalam hari ini? */
    public function contains(DateTimeInterface $instant): bool
    {
        $at = CarbonImmutable::instance($instant)->utc();

        return $at->greaterThanOrEqualTo($this->startUtc)
            && $at->lessThan($this->endUtc);
    }

    /**
     * Tanggal LOKAL dari sebuah instan UTC — nilai yang disimpan di `occurred_on`.
     *
     * Inilah fungsi yang harus dipakai saat menulis event: 16:30 UTC tanggal 16
     * adalah tanggal 16 WIB, tetapi 17:30 UTC tanggal 16 sudah tanggal 17 WIB.
     */
    public static function localDateOf(DateTimeInterface $instant, ?string $timezone = null): string
    {
        $tz = $timezone ?? self::DEFAULT_TIMEZONE;

        return CarbonImmutable::instance($instant)->setTimezone($tz)->format('Y-m-d');
    }

    /** Jumlah hari yang dicakup (akhir eksklusif). */
    public function days(): int
    {
        return (int) $this->startUtc->diffInDays($this->endUtc);
    }

    /**
     * Daftar tanggal lokal dalam rentang, inklusif di kedua ujung.
     * Dipakai perintah rollup untuk menentukan hari mana yang diproses.
     *
     * @return list<string>
     */
    public static function datesBetween(
        string $fromLocalDate,
        string $toLocalDate,
        ?string $timezone = null,
    ): array {
        $w = self::forLocalRange($fromLocalDate, $toLocalDate, $timezone);
        $tz = $w->timezone;

        $cursor = CarbonImmutable::instance($w->startUtc)->setTimezone($tz)->startOfDay();
        $last = CarbonImmutable::instance($w->endUtc)->setTimezone($tz)->startOfDay();

        $out = [];
        while ($cursor->lessThan($last)) {
            $out[] = $cursor->format('Y-m-d');
            $cursor = $cursor->addDay();
        }

        return $out;
    }

    /** Hari ini menurut zona analitik (bukan menurut UTC). */
    public static function today(?string $timezone = null): string
    {
        $tz = $timezone ?? self::DEFAULT_TIMEZONE;

        return CarbonImmutable::now($tz)->format('Y-m-d');
    }

    /** Kemarin menurut zona analitik. */
    public static function yesterday(?string $timezone = null): string
    {
        $tz = $timezone ?? self::DEFAULT_TIMEZONE;

        return CarbonImmutable::now($tz)->subDay()->format('Y-m-d');
    }
}
