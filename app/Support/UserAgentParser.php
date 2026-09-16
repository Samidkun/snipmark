<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Mengubah user-agent menjadi bucket kasar: device / browser / os.
 *
 * Kelas murni, tanpa DB/network. Dua hal yang membuatnya benar dan bukan kira-kira:
 *
 * 1. URUTAN PEMERIKSAAN. UA browser nyata saling menumpuk:
 *    - Edge & Samsung SAMA-SAMA memuat "Chrome" -> cek keduanya lebih dulu,
 *      kalau tidak, seluruh Edge/Samsung tercatat sebagai Chrome.
 *    - Android memuat "Linux" -> cek Android lebih dulu.
 *    - iPhone memuat "Mac OS X" -> cek iOS lebih dulu.
 *    Setiap jebakan itu punya test khusus; urutan yang salah tidak menimbulkan
 *    error, hanya laporan yang salah diam-diam.
 *
 * 2. BOT DIBUANG LEBIH DULU. UA bot tidak boleh jadi "desktop chrome" — itu akan
 *    mengotori laporan manusia. Bot selalu menghasilkan 'other' di tiga dimensi.
 */
final class UserAgentParser
{
    public function __construct(
        private readonly BotDetector $botDetector = new BotDetector,
    ) {}

    public function parse(?string $userAgent): ParsedUserAgent
    {
        $ua = $userAgent ?? '';

        if ($this->botDetector->detect($ua, acceptsHtml: true, acceptLanguage: 'en')->isBot) {
            return ParsedUserAgent::other();
        }

        $lower = strtolower($ua);

        if (trim($lower) === '') {
            return ParsedUserAgent::other();
        }

        return new ParsedUserAgent(
            device: $this->device($lower),
            browser: $this->browser($lower),
            os: $this->os($lower),
        );
    }

    private function device(string $ua): string
    {
        if (str_contains($ua, 'ipad') || str_contains($ua, 'tablet')) {
            return 'tablet';
        }

        // Android tanpa token "Mobile" adalah tablet (mis. SM-X710).
        if (str_contains($ua, 'android')) {
            return str_contains($ua, 'mobile') ? 'mobile' : 'tablet';
        }

        foreach (['mobile', 'iphone', 'ipod', 'windows phone', 'opera mini'] as $needle) {
            if (str_contains($ua, $needle)) {
                return 'mobile';
            }
        }

        foreach (['windows', 'macintosh', 'mac os x', 'linux', 'x11', 'cros'] as $needle) {
            if (str_contains($ua, $needle)) {
                return 'desktop';
            }
        }

        return 'other';
    }

    private function browser(string $ua): string
    {
        // URUTAN PENTING — lihat docblock kelas.
        $rules = [
            'edge' => ['edg/', 'edge/', 'edgios', 'edga'],
            'samsung' => ['samsungbrowser'],
            'opera' => ['opr/', 'opera'],
            'chrome' => ['crios', 'chrome/', 'chromium'],
            'firefox' => ['fxios', 'firefox/'],
            'safari' => ['safari/'],
        ];

        foreach ($rules as $bucket => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($ua, $needle)) {
                    return $bucket;
                }
            }
        }

        return 'other';
    }

    private function os(string $ua): string
    {
        // URUTAN PENTING: android sebelum linux; ios sebelum macos.
        if (str_contains($ua, 'android')) {
            return 'android';
        }

        foreach (['iphone', 'ipad', 'ipod', 'ios'] as $needle) {
            if (str_contains($ua, $needle)) {
                return 'ios';
            }
        }

        if (str_contains($ua, 'windows')) {
            return 'windows';
        }

        if (str_contains($ua, 'mac os x') || str_contains($ua, 'macintosh')) {
            return 'macos';
        }

        if (str_contains($ua, 'linux') || str_contains($ua, 'x11') || str_contains($ua, 'cros')) {
            return 'linux';
        }

        return 'other';
    }
}
