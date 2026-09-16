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
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : null;

        if ($ip === null) {
            return false;  // hostname biasa -> bukan urusannya di sini
        }

        // FILTER_FLAG_NO_PRIV_RANGE | NO_RES_RANGE menolak loopback, RFC1918,
        // link-local (169.254/16 -> metadata), ULA IPv6, dan range tercadang.
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
