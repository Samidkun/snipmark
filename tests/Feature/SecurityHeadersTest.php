<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test REGRESI untuk bug #19 — CSP nonce.
 *
 * Kenapa test ini ada, dan kenapa ia berbentuk begini:
 *
 * Bug #19 adalah bug yang SUNYI. Halaman tetap HTTP 200, HTML tampak benar,
 * tidak ada error di layar — tetapi browser memblokir setiap <script> inline
 * karena nonce di header CSP berbeda dengan nonce di atribut HTML. Akibatnya
 * Livewire tidak pernah boot dan SELURUH interaksi mati (tombol, form, filter).
 *
 * Kegagalan seperti ini tidak tertangkap oleh test yang hanya memeriksa status
 * 200 atau keberadaan teks. Ia hanya tertangkap bila kita membandingkan DUA
 * NILAI yang harus identik: nonce di header dan nonce di setiap tag <script>.
 *
 * Penyebab aslinya: nonce dibuat di ServiceProvider::boot() — berjalan SEKALI
 * per aplikasi, bukan per request — sementara header dibaca pada waktu yang
 * berbeda dari render HTML. Test di bawah akan MERAH bila nonce kembali dibuat
 * di dua tempat atau dua waktu.
 *
 * Catatan: test harus memakai SATU request yang sama untuk header dan body.
 * Dua request terpisah (mis. HEAD lalu GET) menghasilkan dua nonce berbeda
 * secara sah, dan perbandingan semacam itu akan memberi kesimpulan palsu.
 */
final class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    /** Ambil nilai nonce pertama dari string apa pun. */
    private function nonceDari(string $teks): ?string
    {
        return preg_match('/nonce-([A-Za-z0-9]+)/', $teks, $m) ? $m[1] : null;
    }

    public function test_halaman_landing_mengirim_header_keamanan(): void
    {
        $res = $this->get('/');

        $res->assertOk();
        $res->assertHeader('X-Content-Type-Options', 'nosniff');
        $res->assertHeader('X-Frame-Options', 'DENY');
        $res->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString(
            "frame-ancestors 'none'",
            (string) $res->headers->get('Content-Security-Policy')
        );
    }

    /**
     * Inti bug #19: nonce di header CSP harus SAMA dengan nonce di setiap
     * <script> inline yang dikirim server pada response yang sama.
     */
    public function test_nonce_di_header_sama_dengan_nonce_di_script_inline(): void
    {
        $res = $this->get('/');

        $csp = (string) $res->headers->get('Content-Security-Policy');
        $nonceHeader = $this->nonceDari($csp);

        $this->assertNotNull($nonceHeader, 'Header CSP tidak memuat nonce sama sekali.');
        $this->assertStringContainsString(
            "script-src 'self' 'nonce-{$nonceHeader}'",
            $csp,
            'script-src tidak memakai nonce yang sama dengan yang dikirim.'
        );

        $html = $res->getContent();
        preg_match_all('/<script\b[^>]*>/i', $html, $m);
        $tags = $m[0] ?? [];

        $this->assertNotEmpty($tags, 'Tidak ada tag <script> sama sekali — render mencurigakan.');

        $tanpaNonce = [];
        foreach ($tags as $tag) {
            // Hanya <script> yang TIDAK punya src eksternal? Bukan: baik eksternal
            // maupun inline sama-sama butuh nonce di bawah CSP 'self' + nonce.
            if (! str_contains($tag, "nonce=\"{$nonceHeader}\"")) {
                $tanpaNonce[] = $tag;
            }
        }

        $this->assertSame(
            [],
            $tanpaNonce,
            "Ada <script> yang nonce-nya BEDA dari header CSP (bug #19):\n".implode("\n", $tanpaNonce)
        );
    }

    /**
     * Nonce harus UNIK per request. Kalau tidak, CSP-nya bisa di-replay dan
     * sekaligus menandakan nonce dibuat di provider (sekali per aplikasi).
     */
    public function test_nonce_berbeda_antar_request(): void
    {
        $a = $this->nonceDari((string) $this->get('/')->headers->get('Content-Security-Policy'));
        $b = $this->nonceDari((string) $this->get('/')->headers->get('Content-Security-Policy'));

        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertNotSame($a, $b, 'Nonce sama pada dua request — CSP dapat di-replay.');
    }

    /** HSTS hanya pada HTTPS; mengirimnya lewat HTTP mengunci pengembangan lokal. */
    public function test_hsts_tidak_dikirim_pada_http(): void
    {
        $this->assertNull($this->get('/')->headers->get('Strict-Transport-Security'));
    }

    public function test_hsts_dikirim_pada_https(): void
    {
        $res = $this->get('https://localhost/');

        $this->assertNotNull($res->headers->get('Strict-Transport-Security'));
        $this->assertStringContainsString(
            'max-age=31536000',
            (string) $res->headers->get('Strict-Transport-Security')
        );
    }

    /**
     * Halaman auth juga harus ber-nonce konsisten. Bug #19 hanya muncul pada
     * halaman yang merender script; memeriksa satu halaman saja tidak cukup.
     */
    public function test_halaman_login_juga_konsisten(): void
    {
        $res = $this->get('/login');

        $res->assertOk();
        $csp = (string) $res->headers->get('Content-Security-Policy');
        $nonce = $this->nonceDari($csp);
        $this->assertNotNull($nonce);

        $html = $res->getContent();
        preg_match_all('/<script\b[^>]*>/i', $html, $m);
        foreach ($m[0] ?? [] as $tag) {
            $this->assertStringContainsString(
                "nonce=\"{$nonce}\"",
                $tag,
                "Script di /login memakai nonce berbeda dari header (bug #19): {$tag}"
            );
        }
    }
}
