<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * Identitas pengunjung yang dapat dipercaya tanpa menyimpan alamat IP mentah.
 *
 * HmacSha256(ip | tanggal, secret). Tiga properti yang dijamin:
 *
 * - BUKAN digest polos. Kalau hanya sha256(ip|tanggal), siapa pun yang memegang
 *   dump database bisa menghitung ulang identitas untuk SELURUH ruang IPv4 secara
 *   offline (~4 miliar kemungkinan) dan mencocokkan kembali ke setiap pengunjung.
 *   Dengan kunci, ia berhenti jadi hash dan jadi pengenal yang bisa dirotasi.
 *
 * - BEROTASI HARIAN. IP yang sama pada hari berbeda menghasilkan nilai berbeda,
 *   jadi hash tidak bisa dipakai menelusuri aktivitas seseorang lintas hari.
 *   Konsekuensi yang diterima (R8): unique visitor lintas-hari tidak bisa dihitung
 *   presisi; "uniq" adalah agregat harian, bukan angka orang-sepanjang-waktu.
 *
 * - TANGGAL, bukan instan. 00:01 dan 23:59 di hari yang sama = orang yang sama.
 *   Ini yang membuat `unique_visitors` harian konsisten (spec §4.4).
 *
 * Spec §6.5 + ADR-0002. Kelas murni: tanpa DB, tanpa request.
 */
final class VisitorHasher
{
    /**
     * @param  string  $secret  RAHASIA. Jangan pernah pakai nilai default, dan jangan
     *                          pernah di-commit. Di aplikasi, sumbernya APP_KEY.
     */
    public function __construct(private readonly string $secret)
    {
        if (trim($secret) === '') {
            throw new InvalidArgumentException(
                'VisitorHasher butuh secret non-kosong. Tanpa kunci, hash bisa di-enumerasi offline.'
            );
        }
    }

    public function hash(string $ip, DateTimeInterface $at): string
    {
        // Normalisasi: buang spasi liar dan huruf pada IPv6 — representasi berbeda
        // dari alamat yang sama harus menghasilkan pengunjung yang sama, bukan dua.
        $normalized = trim($ip);

        if (str_contains($normalized, ':')) {
            $normalized = strtolower($normalized);
        }

        return hash_hmac('sha256', $normalized.'|'.$at->format('Y-m-d'), $this->secret);
    }
}
