<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Link;

/**
 * Guard trust boundary untuk `destination` (spec §10).
 *
 * Bukan sekadar `url:` bawaan Laravel, karena dua kelas bug nyata:
 *  1. XSS via scheme — `javascript:alert(1)` adalah "URL" yang sah bagi filter
 *     naif, tapi fatal saat dijadikan target redirect.
 *  2. SSRF pivot — `http://169.254.169.254/` (metadata cloud), loopback, RFC1918.
 *     Aplikasi ini memang TIDAK pernah fetch destination, tapi membiarkannya
 *     tersimpan berarti satu komponen server-side di masa depan akan mewarisi bom.
 *
 * Kelas murni: aturan saja, tanpa container, tanpa network, tanpa DB.
 *
 * BATAS yang disengaja: hostname yang RESOLVE ke IP privat via DNS tidak terdeteksi
 * di sini (butuh resolusi DNS, yang membuka DNS-rebinding race). Karena aplikasi
 * tidak pernah melakukan fetch, diterima; kalau kelak ada fetch server-side,
 * pemeriksaan harus pindah ke momen fetch, bukan mengandalkan ini.
 */
final class DestinationValidator
{
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /** hostname yang selalu internal, berapa pun DNS-nya menjawab. */
    private const BLOCKED_HOSTS = [
        'localhost',
        'localhost.localdomain',
        'metadata',
        'metadata.google.internal',
        'metadata.goog',
    ];

    public function isValid(?string $url): bool
    {
        return $this->errors($url) === [];
    }

    /** @return array{destination: string} */
    public function errors(?string $url): array
    {
        return $this->reason($url) === null ? [] : ['destination' => $this->reason($url)];
    }

    /** Pesan alasan, atau null bila sah. */
    public function reason(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return 'Tujuan tidak boleh kosong.';
        }

        // Whitespace/NUL di sepanjang URL adalah teknik bypass scheme klasik.
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return 'Tujuan mengandung karakter kontrol.';
        }

        $candidate = trim($url);

        if (strlen($candidate) > Link::MAX_DESTINATION_LENGTH) {
            return 'Tujuan terlalu panjang (maks '.Link::MAX_DESTINATION_LENGTH.' karakter).';
        }

        if (! preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*:#', $candidate)) {
            return 'Tujuan wajib memakai scheme http:// atau https://.';
        }

        $parts = parse_url($candidate);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return 'Tujuan bukan URL yang sah.';
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            return "Scheme '{$scheme}' tidak diizinkan; gunakan http atau https.";
        }

        $host = strtolower(trim($parts['host'], '[]'));

        if ($host === '') {
            return 'Tujuan harus punya hostname.';
        }

        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            return 'Tujuan mengarah ke alamat internal.';
        }

        if ($this->isInternalIp($host)) {
            return 'Tujuan mengarah ke alamat internal atau metadata cloud.';
        }

        return null;
    }

    /** Host yang berupa IP literal diperiksa terhadap rentang non-public. */
    private function isInternalIp(string $host): bool
    {
        // Dua jalur: (1) bentuk IPv4 apa pun yang dipahami browser (termasuk
        // desimal/hex/oktal/pendek) dinormalisasi dulu; (2) IP literal biasa
        // (IPv6 dsb.) lewat filter_var. Tanpa langkah (1), `http://2130706433/`
        // lolos karena bukan "IP" menurut filter_var — padahal browser
        // membukanya sebagai 127.0.0.1.
        $ip = $this->normalizeIpv4($host)
            ?? (filter_var($host, FILTER_VALIDATE_IP) !== false ? $host : null);

        if ($ip === null) {
            return false;  // hostname biasa -> bukan urusannya di sini
        }

        // FILTER_FLAG_NO_PRIV_RANGE | NO_RES_RANGE menolak loopback, RFC1918,
        // link-local (169.254/16 -> metadata), ULA IPv6, dan range tercadang.
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /**
     * Normalisasi hostname menjadi IPv4 dotted-quad bila browser akan
     * memperlakukannya sebagai IPv4 — mengikuti algoritma IPv4 parser WHATWG.
     *
     * Kenapa perlu: browser menormalkan banyak bentuk menjadi alamat yang sama.
     * `2130706433`, `0x7f000001`, `017700000001`, `127.1`, dan `127.0.0.1`
     * SEMUANYA membuka 127.0.0.1 (dibuktikan dengan `new URL(...).hostname`).
     * `filter_var($h, FILTER_VALIDATE_IP)` menolak semuanya sebagai "bukan IP",
     * sehingga validator naif menganggapnya hostname publik yang aman.
     *
     * Aturan (WHATWG URL §IPv4 parser):
     *   - pecah pada '.', buang titik akhir; maksimum 4 bagian
     *   - tiap bagian: `0x..` heksadesimal, `0..` oktal, selain itu desimal
     *   - semua bagian kecuali terakhir harus <= 255
     *   - bagian terakhir harus < 256^(5 - jumlah_bagian)
     *
     * @return string|null IPv4 dotted-quad, atau null bila bukan IPv4.
     */
    private function normalizeIpv4(string $host): ?string
    {
        $host = rtrim($host, '.');

        if ($host === '') {
            return null;
        }

        $parts = explode('.', $host);

        if (count($parts) > 4) {
            return null;
        }

        $nums = [];

        foreach ($parts as $part) {
            if ($part === '') {
                return null;
            }

            if (preg_match('/^0[xX][0-9a-fA-F]+$/', $part) === 1) {
                $nums[] = hexdec(substr($part, 2));
            } elseif (preg_match('/^0[0-7]+$/', $part) === 1) {
                $nums[] = octdec($part);
            } elseif (preg_match('/^[0-9]+$/', $part) === 1) {
                $nums[] = (int) $part;
            } else {
                // Bukan angka (mis. 'example') -> ini hostname, bukan IPv4.
                return null;
            }
        }

        // Bagian terakhir menampung sisa oktet; sisanya masing-masing satu oktet.
        $last = array_pop($nums);
        $leading = count($nums);

        if ($last >= 256 ** (4 - $leading)) {
            return null;
        }

        foreach ($nums as $n) {
            if ($n > 255) {
                return null;
            }
        }

        $value = $last;

        foreach ($nums as $i => $n) {
            $value += $n * (256 ** (3 - $i));
        }

        return long2ip($value);
    }
}
