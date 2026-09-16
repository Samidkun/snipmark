<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Mencocokkan alamat IP terhadap daftar blok CIDR.
 *
 * KELAS MURNI: tanpa DB, tanpa network, tanpa container. Ini yang membuat
 * heuristik "pusat data" bisa diuji tuntas, termasuk kasus yang paling berbahaya:
 * IP yang tidak sah malah dianggap COCOK (sehingga trafik user biasa ditandai bot).
 * Aturan di sini: IP yang tidak bisa diparsing SELALU non-cocok.
 *
 * NAMANYA DIPILIH HATI-HATI (spec §6.3): yang dibangun adalah pencocokan CIDR,
 * BUKAN pencarian ASN. Pemetaan IP->ASN butuh basis data berlisensi (MaxMind) yang
 * tidak dipakai di v1. Klaim di dokumentasi harus mengikuti kenyataan ini.
 *
 * Implementasi memakai `inet_pton` + perbandingan bit per bit, sehingga IPv4 dan
 * IPv6 ditangani jalur yang sama dan tidak perlu aritmetika integer rawan overflow.
 */
final class CidrMatcher
{
    /** @var list<array{0: string, 1: int}> binary-prefix => panjang prefix bit */
    private array $blocks = [];

    /**
     * @param  list<string>  $cidrs
     *
     * @throws InvalidArgumentException bila ada CIDR tidak sah — gagal di awal
     *                                  lebih baik daripada diam-diam tidak cocok
     */
    public function __construct(array $cidrs)
    {
        foreach ($cidrs as $cidr) {
            [$network, $bits] = $this->parseCidr($cidr);
            $this->blocks[] = [$network, $bits];
        }
    }

    /**
     * True bila IP berada di salah satu blok.
     * IP tidak sah -> false (tidak pernah cocok).
     */
    public function matches(?string $ip): bool
    {
        if ($ip === null) {
            return false;
        }

        $binary = @inet_pton(trim($ip));

        if ($binary === false) {
            return false;
        }

        foreach ($this->blocks as [$network, $bits]) {
            // Keluarga alamat berbeda (v4 vs v6) tidak pernah cocok.
            if (strlen($network) !== strlen($binary)) {
                continue;
            }

            if ($this->samePrefix($binary, $network, $bits)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: string, 1: int} prefix biner dan panjang bit
     */
    private function parseCidr(string $cidr): array
    {
        $cidr = trim($cidr);

        if (! str_contains($cidr, '/')) {
            throw new InvalidArgumentException("CIDR tanpa prefix: {$cidr}");
        }

        [$address, $prefix] = explode('/', $cidr, 2);

        $binary = @inet_pton($address);

        if ($binary === false) {
            throw new InvalidArgumentException("Alamat dalam CIDR tidak sah: {$cidr}");
        }

        if (! ctype_digit($prefix)) {
            throw new InvalidArgumentException("Prefix bukan angka: {$cidr}");
        }

        $bits = (int) $prefix;
        $max = strlen($binary) * 8;

        if ($bits < 0 || $bits > $max) {
            throw new InvalidArgumentException("Prefix di luar rentang ({$max} bit): {$cidr}");
        }

        return [$this->maskBits($binary, $bits), $bits];
    }

    /** Menolkan bit di luar prefix, supaya perbandingan bisa dilakukan langsung. */
    private function maskBits(string $binary, int $bits): string
    {
        $out = '';
        $fullBytes = intdiv($bits, 8);
        $restBits = $bits % 8;

        $out .= substr($binary, 0, $fullBytes);

        if ($restBits > 0) {
            $mask = 0xFF << (8 - $restBits) & 0xFF;
            $out .= chr(ord($binary[$fullBytes]) & $mask);
        }

        return str_pad($out, strlen($binary), "\0");
    }

    private function samePrefix(string $a, string $b, int $bits): bool
    {
        $fullBytes = intdiv($bits, 8);
        $restBits = $bits % 8;

        if ($fullBytes > 0 && substr($a, 0, $fullBytes) !== substr($b, 0, $fullBytes)) {
            return false;
        }

        if ($restBits === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $restBits) & 0xFF;

        return (ord($a[$fullBytes]) & $mask) === (ord($b[$fullBytes]) & $mask);
    }
}
