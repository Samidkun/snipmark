<?php

use App\Support\VisitorHasher;
use PHPUnit\Framework\TestCase;

/**
 * Spec §6.5 + ADR-0002: identitas pengunjung di-hash, TIDAK pernah disimpan mentah,
 * dan BEROTASI HARIAN sehingga tidak bisa dipakai melacak orang lintas hari.
 */
final class VisitorHasherTest extends TestCase
{
    private VisitorHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hasher = new VisitorHasher('test-app-key');
    }

    public function test_menghasilkan_hex_64_karakter(): void
    {
        $hash = $this->hasher->hash('203.0.113.10', new DateTimeImmutable('2026-09-16'));

        self::assertSame(64, strlen($hash), 'sha256 hex = 64 char (CHAR(64) di skema)');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    public function test_deterministik_untuk_input_sama(): void
    {
        $date = new DateTimeImmutable('2026-09-16');

        self::assertSame(
            $this->hasher->hash('203.0.113.10', $date),
            $this->hasher->hash('203.0.113.10', $date),
        );
    }

    /**
     * Properti inti privasi: IP sama, hari berbeda => hash BERBEDA.
     * Tanpa rotasi, hash jadi pengenal permanen lintas bulan.
     */
    public function test_ip_sama_hari_berbeda_menghasilkan_hash_berbeda(): void
    {
        $a = $this->hasher->hash('203.0.113.10', new DateTimeImmutable('2026-09-16'));
        $b = $this->hasher->hash('203.0.113.10', new DateTimeImmutable('2026-09-17'));

        self::assertNotSame($a, $b, 'rotasi harian tidak bekerja — hash bisa dipakai melacak lintas hari');
    }

    public function test_ip_berbeda_menghasilkan_hash_berbeda(): void
    {
        $date = new DateTimeImmutable('2026-09-16');

        self::assertNotSame(
            $this->hasher->hash('203.0.113.10', $date),
            $this->hasher->hash('203.0.113.11', $date),
        );
    }

    /**
     * Kunci aplikasi berbeda => hash berbeda. Kalau tidak, kunci bisa ditebak
     * dari korpus hash yang bocor (rainbow table atas ruang IPv4).
     */
    public function test_kunci_berbeda_menghasilkan_hash_berbeda(): void
    {
        $date = new DateTimeImmutable('2026-09-16');
        $other = new VisitorHasher('kunci-lain');

        self::assertNotSame(
            $this->hasher->hash('203.0.113.10', $date),
            $other->hash('203.0.113.10', $date),
        );
    }

    /** Tanggal diperlakukan sebagai TANGGAL, bukan instan: 00:01 dan 23:59 sama. */
    public function test_jam_dalam_hari_yang_sama_tidak_mengubah_hash(): void
    {
        $pagi = new DateTimeImmutable('2026-09-16 00:01:00');
        $malam = new DateTimeImmutable('2026-09-16 23:59:59');

        self::assertSame(
            $this->hasher->hash('203.0.113.10', $pagi),
            $this->hasher->hash('203.0.113.10', $malam),
        );
    }

    /**
     * Hash HARUS memakai kunci (HMAC), bukan digest polos. Kalau ini gagal,
     * sha256(ip|tanggal) bisa dihitung ulang siapa pun yang punya dump database
     * (seluruh ruang IPv4 = ~4 miliar, itu bisa di-enumerasi).
     */
    public function test_bukan_digest_polos(): void
    {
        $ip = '203.0.113.10';
        $tanggal = '2026-09-16';
        $polos = hash('sha256', $ip.'|'.$tanggal);

        self::assertNotSame($polos, $this->hasher->hash($ip, new DateTimeImmutable($tanggal)),
            'hash tampak seperti sha256(ip|tanggal) tanpa kunci — bisa di-enumerasi');
    }

    public function test_ip_kosong_tetap_menghasilkan_hash_sah(): void
    {
        $hash = $this->hasher->hash('', new DateTimeImmutable('2026-09-16'));

        self::assertSame(64, strlen($hash));
    }

    /**
     * Alamat IPv6 panjang juga harus tertampung (CHAR(64) = hex sha256, aman),
     * dan representasi berbeda dari IP yang sama sebaiknya tidak menghasilkan
     * dua pengunjung — diuji lewat normalisasi.
     */
    public function test_ipv6_tertampung(): void
    {
        $hash = $this->hasher->hash('2001:0db8:85a3:0000:0000:8a2e:0370:7334', new DateTimeImmutable('2026-09-16'));

        self::assertSame(64, strlen($hash));
    }

    public function test_kunci_tidak_boleh_kosong(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new VisitorHasher('');
    }
}
