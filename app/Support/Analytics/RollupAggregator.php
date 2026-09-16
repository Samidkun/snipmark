<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use App\Models\LinkDailyRollup;

/**
 * Mengubah himpunan baris event menjadi angka agregat. KELAS MURNI.
 *
 * Tanpa DB, tanpa query, tanpa waktu sekarang — menerima array dan mengembalikan
 * angka. Itulah yang membuat properti penting bisa diuji terhadap distribusi yang
 * hasilnya kita ketahui pasti, alih-alih "kira-kira benar".
 *
 * KEPUTUSAN DESAIN YANG DISENGAJA:
 *
 *  - `uniqueVisitors` menghitung distinct `visitor_hash` MANUSIA saja.
 *    Kalau bot ikut, satu scanner dengan 10.000 permintaan akan menggelembungkan
 *    "pengunjung unik" dan tidak akan pernah terlihat sebagai error.
 *
 *  - Rincian dimensi (by_device/by_browser/by_os/by_referrer) juga menghitung
 *    MANUSIA saja. Alasan: tabelnya menjumlah ke `human`, sehingga UI tidak perlu
 *    menebak basis mana yang dipakai. Angka bot sudah tersedia terpisah di `bots`.
 *    Konsekuensi yang diuji: `array_sum(by_*) === human` SELALU.
 *
 *  - Setiap dimensi dipotong ke 25 kunci + '(other)' (spec §4.4). Pemotongan
 *    TIDAK BOLEH menjatuhkan event: sisanya digabung, bukan dibuang.
 *
 * Spec §5.1.
 */
final class RollupAggregator
{
    public const OTHER_KEY = '(other)';

    /**
     * @param  iterable<mixed>  $events  baris dengan kunci: is_bot, visitor_hash,
     *                                   device_type, browser_family, os_family, referrer_host
     */
    public function aggregate(iterable $events): RollupResult
    {
        $total = 0;
        $bots = 0;
        $humanHashes = [];

        /** @var array<string, array<string, int>> $dims */
        $dims = [
            'device' => [],
            'browser' => [],
            'os' => [],
            'referrer' => [],
        ];

        foreach ($events as $event) {
            // Baris rusak diabaikan diam-diam: satu baris cacat tidak boleh
            // menghilangkan seluruh agregasi hari itu.
            if (! is_array($event) && ! is_object($event)) {
                continue;
            }

            $row = is_array($event) ? $event : (array) $event;

            $isBot = (bool) ($row['is_bot'] ?? false);
            $total++;

            if ($isBot) {
                $bots++;

                // Bot TIDAK masuk dimensi maupun unique visitor.
                continue;
            }

            $hash = (string) ($row['visitor_hash'] ?? '');
            if ($hash !== '') {
                $humanHashes[$hash] = true;
            }

            $dims['device'][$this->normaliseKey($row['device_type'] ?? null)] =
                ($dims['device'][$this->normaliseKey($row['device_type'] ?? null)] ?? 0) + 1;

            $browser = $this->normaliseKey($row['browser_family'] ?? null);
            $dims['browser'][$browser] = ($dims['browser'][$browser] ?? 0) + 1;

            $os = $this->normaliseKey($row['os_family'] ?? null);
            $dims['os'][$os] = ($dims['os'][$os] ?? 0) + 1;

            $ref = $this->normaliseReferrer($row['referrer_host'] ?? null);
            $dims['referrer'][$ref] = ($dims['referrer'][$ref] ?? 0) + 1;
        }

        $human = $total - $bots;

        return new RollupResult(
            total: $total,
            human: $human,
            bots: $bots,
            uniqueVisitors: count($humanHashes),
            byDevice: $this->trim($dims['device']),
            byBrowser: $this->trim($dims['browser']),
            byOs: $this->trim($dims['os']),
            byReferrer: $this->trim($dims['referrer']),
        );
    }

    /** Nilai dimensi kosong/whitespace menjadi 'other', bukan kunci kosong. */
    private function normaliseKey(mixed $value): string
    {
        if ($value === null) {
            return 'other';
        }

        $s = strtolower(trim((string) $value));

        return $s === '' ? 'other' : $s;
    }

    /**
     * Referrer: host dinormalisasi (huruf kecil, `www.` dibuang) supaya
     * `www.Google.com` dan `google.com` tidak menjadi dua dimensi.
     * Kosong -> '(direct)'.
     */
    private function normaliseReferrer(mixed $value): string
    {
        if ($value === null) {
            return LinkDailyRollup::DIRECT_REFERRER;
        }

        $s = strtolower(trim((string) $value));

        if ($s === '') {
            return LinkDailyRollup::DIRECT_REFERRER;
        }

        if (str_starts_with($s, 'www.')) {
            $s = substr($s, 4);
        }

        return $s === '' ? LinkDailyRollup::DIRECT_REFERRER : $s;
    }

    /**
     * Urutkan menurun, potong ke 25 kunci, gabungkan sisanya ke '(other)'.
     *
     * Pengurutan menentukan kunci mana yang bertahan, jadi ia harus deterministik
     * (jumlah menurun, lalu nama naik sebagai pemecah seri) — kalau tidak, rollup
     * yang dijalankan ulang bisa menghasilkan himpunan kunci berbeda untuk data
     * yang sama, dan idempotensi §5.1 rusak.
     *
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private function trim(array $counts): array
    {
        if ($counts === []) {
            return [];
        }

        uksort($counts, function (string $a, string $b) use ($counts) {
            return [$counts[$b], $a] <=> [$counts[$a], $b];
        });

        if (count($counts) <= LinkDailyRollup::MAX_DIMENSION_KEYS) {
            return $counts;
        }

        $kept = array_slice($counts, 0, LinkDailyRollup::MAX_DIMENSION_KEYS, true);
        $rest = array_slice($counts, LinkDailyRollup::MAX_DIMENSION_KEYS, preserve_keys: true);

        // '(other)' tidak boleh menelan kunci yang sudah bernama '(other)'.
        $otherTotal = array_sum($rest);
        if (isset($kept[self::OTHER_KEY])) {
            $otherTotal += $kept[self::OTHER_KEY];
            unset($kept[self::OTHER_KEY]);
        }

        $kept[self::OTHER_KEY] = $otherTotal;

        return $kept;
    }
}
