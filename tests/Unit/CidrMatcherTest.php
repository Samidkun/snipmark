<?php

use App\Support\CidrMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * mencocokkan IP ke daftar CIDR — heuristik pusat data (spec §6.3).
 *
 * Kelas murni: tanpa DB/network. Ini yang membuat "block ASN" versi gw
 * (yaitu daftar CIDR pusat data) bisa diuji tuntas, termasuk case IPv6
 * dan IP rusak yang harusnya DITOLAK, bukan dianggap cocok.
 */
final class CidrMatcherTest extends TestCase
{
    public function test_cocok_dalam_range(): void
    {
        $m = new CidrMatcher(['8.8.8.0/24']);

        self::assertTrue($m->matches('8.8.8.1'));
        self::assertTrue($m->matches('8.8.8.255'));
        self::assertFalse($m->matches('8.8.9.1'));
    }

    public function test_boundary_cu_bersih(): void
    {
        $m = new CidrMatcher(['10.0.0.0/8']);

        self::assertTrue($m->matches('10.0.0.0'));
        self::assertTrue($m->matches('10.255.255.255'));
        self::assertFalse($m->matches('11.0.0.0'));
        self::assertFalse($m->matches('9.255.255.255'));
    }

    public function test_prefix_tidak_sempurna_tetel_cocok(): void
    {
        $m = new CidrMatcher(['192.168.0.0/16']);

        self::assertTrue($m->matches('192.168.50.10'));
        self::assertFalse($m->matches('192.169.0.1'));
    }

    /**
     * Kesalahan paling mahal di code ini: IP tidak sah malah dianggap COCOK,
     * sehingga trafik user biasa ditandai bot. IP rusak harus SELALU non-cocok.
     */
    #[DataProvider('ipRusak')]
    public function test_ip_rusak_tidak_pernah_cocok(string $ip): void
    {
        $m = new CidrMatcher(self::cidrsDariConfig());

        self::assertFalse($m->matches($ip), "IP tidak sah dianggap cocok: {$ip}");
    }

    public static function ipRusak(): array
    {
        return [
            'kosong' => [''],
            'spasi doang' => ['   '],
            'bukan ip' => ['hello'],
            'kepanjangan' => ['999.1.1.1'],
            'octet kurang' => ['10.0'],
            'octet berlebih' => ['10.0.0.0.0'],
            'negative' => ['-1.0.0.0'],
            'injection percobaan' => ['8.8.8.8; DROP TABLE links'],
        ];
    }

    public function test_cidr_invalid_ditola_saat_konstruksi_bukan_diam(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CidrMatcher(['8.8.8.0/notduabelas']);
    }

    public function test_daftar_kosong_tidak_pernah_cocok(): void
    {
        self::assertFalse((new CidrMatcher([]))->matches('8.8.8.8'));
    }

    public function test_ipv6_cocok_dan_ipv4_tidak_saling_tumpang_tindih(): void
    {
        $m = new CidrMatcher(['2001:db8::/32']);

        self::assertTrue($m->matches('2001:db8:0:1::5'));
        self::assertFalse($m->matches('2001:db9::1'));
        // IP v4 tidak boleh dianggap cocok oleh blok v6
        self::assertFalse($m->matches('8.8.8.8'));
    }

    /** Multi-CIDR: cukup salah satu cocok. */
    public function test_beberapa_cidr(): void
    {
        $m = new CidrMatcher(['1.1.1.0/24', '9.9.9.0/24']);

        self::assertTrue($m->matches('9.9.9.9'));
        self::assertFalse($m->matches('5.5.5.5'));
    }

    /**
     * CIDR dari FILE config, bukan via `config()` — kelas ini PHPUnit murni dan
     * tidak punya container (pelajaran B11: jangan panggil helper framework dari sini).
     *
     * @return list<string>
     */
    private static function cidrsDariConfig(): array
    {
        $cfg = require __DIR__.'/../../config/snipmark.php';

        return array_values($cfg['datacenter_cidrs'] ?? []);
    }

    /** CIDR yang dipakai project ini harus masuk akal (guard dari config typo). */
    public function test_cidr_bawaan_project_valid_semuanya(): void
    {
        $cidrs = self::cidrsDariConfig();

        self::assertNotEmpty($cidrs, 'config datacenter_cidrs kosong');

        // Konstruktor MELEMPAR bila ada CIDR tidak sah, jadi assertion-nya adalah
        // "tidak ada exception" — lebih kuat daripada mencocokkan pola regex sendiri.
        new CidrMatcher($cidrs);

        foreach ($cidrs as $c) {
            self::assertMatchesRegularExpression(
                '#^[0-9a-f:.]+/\d{1,3}$#i', (string) $c, "format CIDR tidak sah: {$c}"
            );
        }
    }
}
