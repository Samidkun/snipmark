<?php

use App\Support\ParsedUserAgent;
use App\Support\UserAgentParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kelas murni. Mengubah user-agent jadi BUCKET KASAR — bukan nomor versi.
 *
 * Spec §6.4: versi presisi adalah jebakan klasik (pustaka komunitas pun sering salah).
 * Yang dibutuhkan produk adalah bucket, jadi itulah yang diuji — dan ketiadaan
 * parsing versi itu SENGAJA.
 */
final class UserAgentParserTest extends TestCase
{
    private UserAgentParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new UserAgentParser;
    }

    /**
     * UA nyata => [device, browser, os]
     *
     * @return array<string, array{0:string,1:string,2:string,3:string}>
     */
    public static function realUserAgents(): array
    {
        return [
            'chrome windows' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'desktop', 'chrome', 'windows',
            ],
            'edge windows' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0',
                'desktop', 'edge', 'windows',
            ],
            'firefox windows' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0',
                'desktop', 'firefox', 'windows',
            ],
            'safari macos' => [
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15',
                'desktop', 'safari', 'macos',
            ],
            'chrome linux' => [
                'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'desktop', 'chrome', 'linux',
            ],
            'opera windows' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 OPR/106.0.0.0',
                'desktop', 'opera', 'windows',
            ],
            'chrome android' => [
                'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.6099.230 Mobile Safari/537.36',
                'mobile', 'chrome', 'android',
            ],
            'safari iphone' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1',
                'mobile', 'safari', 'ios',
            ],
            'chrome ios' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/120.0.6099.119 Mobile/15E148 Safari/604.1',
                'mobile', 'chrome', 'ios',
            ],
            'firefox android' => [
                'Mozilla/5.0 (Android 14; Mobile; rv:121.0) Gecko/121.0 Firefox/121.0',
                'mobile', 'firefox', 'android',
            ],
            'samsung browser' => [
                'Mozilla/5.0 (Linux; Android 13; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/23.0 Chrome/115.0.0.0 Mobile Safari/537.36',
                'mobile', 'samsung', 'android',
            ],
            'ipad safari' => [
                'Mozilla/5.0 (iPad; CPU OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1',
                'tablet', 'safari', 'ios',
            ],
            'android tablet (no mobile token)' => [
                'Mozilla/5.0 (Linux; Android 13; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'tablet', 'chrome', 'android',
            ],
        ];
    }

    #[DataProvider('realUserAgents')]
    public function test_klasifikasi_bucket_kasar(string $ua, string $device, string $browser, string $os): void
    {
        $p = $this->parser->parse($ua);

        self::assertSame($device, $p->device, "device salah untuk: {$ua}");
        self::assertSame($browser, $p->browser, "browser salah untuk: {$ua}");
        self::assertSame($os, $p->os, "os salah untuk: {$ua}");
    }

    /** Semua keluaran harus ada di enum skema — nilai asing akan ditolak MariaDB. */
    /**
     * Menjaga DUA hal sekaligus, karena enum MariaDB menolak nilai asing DI LUAR
     * test: kegagalannya baru terlihat saat insert, yaitu di produksi.
     *
     * (a) keluaran parser selalu anggota enum yang sah
     * (b) dataset uji itu sendiri selalu anggota enum yang sah
     *
     * Signature WAJIB menampung seluruh argumen dataset. Kalau tidak, PHPUnit 12
     * mengeluarkan warning, dan `failOnWarning="true"` membuat suite keluar dengan
     * exit 1 — sementara reporter bawaan mencetak "passed". (Bug B7.)
     */
    #[DataProvider('realUserAgents')]
    public function test_keluaran_selalu_nilai_enum_yang_sah(
        string $ua,
        string $expectedDevice,
        string $expectedBrowser,
        string $expectedOs,
    ): void {
        $p = $this->parser->parse($ua);

        self::assertContains($p->device, ParsedUserAgent::DEVICES, "device tak dikenal: {$p->device}");
        self::assertContains($p->browser, ParsedUserAgent::BROWSERS, "browser tak dikenal: {$p->browser}");
        self::assertContains($p->os, ParsedUserAgent::OSES, "os tak dikenal: {$p->os}");

        self::assertContains($expectedDevice, ParsedUserAgent::DEVICES);
        self::assertContains($expectedBrowser, ParsedUserAgent::BROWSERS);
        self::assertContains($expectedOs, ParsedUserAgent::OSES);
    }

    /**
     * Urutan deteksi penting: UA Edge dan Samsung SAMA-SAMA memuat "chrome".
     * Kalau urutannya salah, semua Edge dan Samsung tercatat sebagai Chrome —
     * dan itu merusak laporan analytics tanpa error apa pun.
     */
    public function test_edge_tidak_tercatat_sebagai_chrome(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0';

        self::assertSame('edge', $this->parser->parse($ua)->browser);
    }

    public function test_samsung_tidak_tercatat_sebagai_chrome(): void
    {
        $ua = 'Mozilla/5.0 (Linux; Android 13; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/23.0 Chrome/115.0.0.0 Mobile Safari/537.36';

        self::assertSame('samsung', $this->parser->parse($ua)->browser);
    }

    /** UA Android memuat "linux" — urutan salah membuat semua Android jadi Linux. */
    public function test_android_tidak_tercatat_sebagai_linux(): void
    {
        $ua = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.6099.230 Mobile Safari/537.36';

        self::assertSame('android', $this->parser->parse($ua)->os);
    }

    /** UA iOS memuat "mac os x" — urutan salah membuat iPhone jadi macOS. */
    public function test_iphone_tidak_tercatat_sebagai_macos(): void
    {
        $ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1';

        self::assertSame('ios', $this->parser->parse($ua)->os);
    }

    /** UA tidak dikenal tetap menghasilkan bucket 'other', bukan error. */
    public function test_ua_tidak_dikenal_menghasilkan_other(): void
    {
        $p = $this->parser->parse('SomethingEntirelyUnknown/9.9');

        self::assertSame('other', $p->device);
        self::assertSame('other', $p->browser);
        self::assertSame('other', $p->os);
    }

    public function test_ua_null_menghasilkan_other_tanpa_error(): void
    {
        $p = $this->parser->parse(null);

        self::assertSame('other', $p->device);
        self::assertSame('other', $p->browser);
        self::assertSame('other', $p->os);
    }

    public function test_ua_kosong_menghasilkan_other(): void
    {
        $p = $this->parser->parse('');

        self::assertSame('other', $p->device);
        self::assertSame('other', $p->browser);
        self::assertSame('other', $p->os);
    }

    /**
     * Bot tidak boleh dianggap "desktop chrome" — kalau dipanggil untuk bot,
     * hasilnya harus 'other' di ketiga dimensi supaya tidak mencemari laporan.
     */
    public function test_ua_bot_menghasilkan_other(): void
    {
        $p = $this->parser->parse('curl/8.4.0');

        self::assertSame('other', $p->device);
        self::assertSame('other', $p->browser);
        self::assertSame('other', $p->os);
    }

    /**
     * LUBANG YANG DITEMUKAN MUTATION CHECK (2026-09-16):
     * Test sebelumnya hanya memakai `curl/8.4.0`, yang toh tidak memuat tanda
     * browser apa pun — jadi hasilnya 'other' baik bot-discard ada maupun tidak.
     * Test itu tidak menguji apa pun.
     *
     * Kasus yang benar-benar membedakan: bot yang UA-nya MENYAMAR sebagai browser
     * nyata (HeadlessChrome memuat "Chrome/", "Safari/", "Windows", "Mozilla/5.0").
     * Tanpa bot-discard di awal parse(), traffic otomatis ini akan tercatat sebagai
     * "desktop chrome windows" dan mengotori laporan manusia tanpa satu error pun.
     */
    public function test_bot_yang_menyamar_sebagai_browser_tetap_other(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/120.0.0.0 Safari/537.36';

        $p = $this->parser->parse($ua);

        self::assertSame('other', $p->device, 'bot menyamar jadi desktop');
        self::assertSame('other', $p->browser, 'bot menyamar jadi chrome');
        self::assertSame('other', $p->os, 'bot menyamar jadi windows');
    }

    public function test_googlebot_yang_memuat_mozilla_tetap_other(): void
    {
        $ua = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

        self::assertSame('other', $this->parser->parse($ua)->device);
        self::assertSame('other', $this->parser->parse($ua)->browser);
    }

    /** TIDAK ADA parsing versi — spec §6.4. */
    public function test_tidak_ada_kolom_versi_di_hasil(): void
    {
        $p = $this->parser->parse('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

        $props = array_keys(get_object_vars($p));
        sort($props);

        self::assertSame(['browser', 'device', 'os'], $props,
            'Hasil parser harus hanya 3 bucket — versi presisi sengaja tidak diparse');
    }
}
