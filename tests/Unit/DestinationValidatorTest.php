<?php

use App\Support\DestinationValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guard trust boundary pada `destination`.
 *
 * Kenapa ini ada dan bukan cuma `url:` bawaan Laravel: shortener yang membiarkan
 * `javascript:alert(1)` menjadi target adalah vektor XSS, dan yang membiarkan
 * `http://169.254.169.254/` menjadi perancah SSRF (metadata endpoint) — meskipun
 * aplikasi sendiri tidak pernah fetch destination, redirect browser ke sana tetap
 * berbahaya bila ada komponen server-side yang kelak ikut fetching.
 *
 * Kelas murni: aturan saja, tanpa query, tanpa HTTP.
 */
final class DestinationValidatorTest extends TestCase
{
    private DestinationValidator $v;

    protected function setUp(): void
    {
        parent::setUp();
        $this->v = new DestinationValidator;
    }

    #[DataProvider('urlsSah')]
    public function test_menerima_url_sah(string $url): void
    {
        self::assertTrue($this->v->isValid($url), "seharusnya sah: {$url}");
        self::assertSame([], $this->v->errors($url));
    }

    public static function urlsSah(): array
    {
        return [
            'https' => ['https://example.com'],
            'http' => ['http://example.com'],
            'dengan path' => ['https://example.com/a/b?c=1#frag'],
            'subdomain' => ['https://docs.example.co.uk/path'],
            'punya port' => ['https://example.com:8443/x'],
            'IP literal publik' => ['http://8.8.8.8/'],
            'punya kredensial basic boleh' => ['https://user@example.com'],
            'unicode host (punycode) ' => ['https://xn--e1afmkfd.xn--p1i/'],
            'query panjang' => ['https://example.com/?'.str_repeat('a=1&', 100).'z=9'],
        ];
    }

    #[DataProvider('urlsDitolak')]
    public function test_menolak_url_berbahaya(string $url, string $alasan): void
    {
        self::assertFalse($this->v->isValid($url), "seharusnya DITOLAK ({$alasan}): {$url}");
        self::assertNotEmpty($this->v->errors($url));
    }

    public static function urlsDitolak(): array
    {
        return [
            ['javascript:alert(1)', 'XSS via scheme'],
            ['JavaScript:alert(1)', 'case-insensitive scheme'],
            ['  javascript:alert(1)', 'leading whitespace bypass'],
            ["java\nscript:alert(1)", 'newline inside scheme'],
            ['data:text/html;base64,PHNjcmlwdD4=', 'data: URI'],
            ['file:///etc/passwd', 'file:'],
            ['gopher://x/y', 'skema tidak dikenal (SSRF vector)'],
            ['ftp://example.com', 'bukan http/https'],
            ['about:blank', 'not fetchable'],
            ['//example.com', 'protocol-relative'],
            ['/relative/path', 'path relatif'],
            ['example.com/tanpa-scheme', 'scheme hilang'],
            ['mailto:a@b.c', 'mailto'],
        ];
    }

    /** Metadata cloud + loopback + private = SSRF pivot. Harus ditolak. */
    #[DataProvider('privateTargets')]
    public function test_menolak_target_internal(string $url, string $alasan): void
    {
        self::assertFalse($this->v->isValid($url), "harusnya DITOLAK ({$alasan}): {$url}");
    }

    public static function privateTargets(): array
    {
        return [
            ['http://127.0.0.1/admin', 'loopback'],
            ['http://localhost/x', 'hostname loopback'],
            ['http://LOCALHOST:8000', 'case-insensitive hostname'],
            ['http://0.0.0.0/', 'unspecified'],
            ['http://10.0.0.5/', 'RFC1918'],
            ['http://192.168.1.1/', 'RFC1918'],
            ['http://172.16.0.1/', 'RFC1918'],
            ['http://169.254.169.254/latest/meta-data/', 'AWS metadata'],
            ['http://metadata.google.internal/', 'GCP metadata'],
            ['http://[::1]/', 'IPv6 loopback'],
            ['http://[fd00::1]/', 'IPv6 ULA'],
        ];
    }

    public function test_kosong_ditolak(): void
    {
        self::assertFalse($this->v->isValid(''));
        self::assertFalse($this->v->isValid('   '));
        self::assertFalse($this->v->isValid(null));
    }

    /**
     * Panjang maksimum harus ditegakkan karena kolom TEXT bisa menampung lebih
     * dari yang browser/DB mau — bug class: validasi lebih longgar dari skema (bug #14).
     */
    public function test_menolak_url_sangat_panjang(): void
    {
        self::assertFalse($this->v->isValid('https://example.com/'.str_repeat('a', 5000)),
            'URL 3KB harus ditolak (batas MAX_DESTINATION_LENGTH)');

        self::assertTrue($this->v->isValid('https://example.com/'.str_repeat('a', 100)));
    }

    /** Whitespace di dalam scheme adalah bypass klasik; tidak boleh lolos. */
    public function test_membandingkan_scheme_secara_normalisasi(): void
    {
        foreach (['javascript:', 'JavaScript:', 'JAVASCRIPT:', '\tjavascript:'] as $s) {
            self::assertFalse($this->v->isValid($s.'alert(1)'), "bypass lolos: {$s}");
        }
    }

    /** Error harus bisa dipakai oleh FormRequest (key = 'destination'). */
    public function test_error_memakai_key_field(): void
    {
        self::assertArrayHasKey('destination', $this->v->errors('javascript:alert(1)'));
    }
}
