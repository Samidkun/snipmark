<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Tiga bucket kasar hasil parsing user-agent.
 *
 * PANGKAS SENGAJA: tidak ada nomor versi. Versi presisi adalah jebakan klasik —
 * pustaka UA-parsing yang dirawat komunitas pun sering salah, dan produk ini tidak
 * punya kasususe untuk "Chrome 120 vs 121". Spec §6.4.
 * `ponytail:` batas ini = tidak bisa membedakan versi. Upgrade path = pustaka
 * which-browser, TIDAK dipakai di v1.
 */
final readonly class ParsedUserAgent
{
    public const DEVICES = ['mobile', 'tablet', 'desktop', 'other'];

    public const BROWSERS = ['chrome', 'safari', 'firefox', 'edge', 'opera', 'samsung', 'other'];

    public const OSES = ['windows', 'macos', 'linux', 'android', 'ios', 'other'];

    public function __construct(
        public string $device,
        public string $browser,
        public string $os,
    ) {}

    public static function other(): self
    {
        return new self('other', 'other', 'other');
    }
}
