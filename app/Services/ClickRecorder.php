<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ClickEvent;
use App\Models\Link;
use App\Support\Analytics\DayWindow;
use App\Support\BotDetector;
use App\Support\BotVerdict;
use App\Support\CidrMatcher;
use App\Support\UserAgentParser;
use App\Support\VisitorHasher;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Mencatat satu klik pada jalur redirect (spec §7 langkah 5).
 *
 * Semua keputusan klasifikasi berkumpul di SATU tempat ini, supaya jalur
 * pencatatan lain (mis. re-parse, benchmark, atau impor data demo) tidak
 * membuat salinan aturan yang menyimpang.
 *
 * PRIORITAS ALASAN BOT (spec §6.3) — satu sumber kebenaran "kenapa ini bot":
 *   rate_limited > token UA > datacenter_cidr > unknown
 * Alasan pertama yang cocok menang, dan tidak ada kolom tambahan: ketiganya
 * bermuara ke is_bot / bot_name / bot_category yang sudah ada di skema.
 *
 * TIDAK MEMBLOKIR. Di v1 (SNIPMARK_BOT_ENFORCE=false) hasil klasifikasi hanya
 * dicatat; pengunjung selalu tetap dilayani. Lihat runbook untuk cara promosi ke
 * enforcement setelah data cukup.
 */
final class ClickRecorder
{
    public const CACHE_PREFIX = 'snip:rl:';

    private readonly CidrMatcher $datacenters;

    public function __construct(
        private readonly VisitorHasher $hasher,
        private readonly UserAgentParser $parser,
        private readonly BotDetector $detector,
        array $datacenterCidrs,
    ) {
        $this->datacenters = new CidrMatcher(array_values($datacenterCidrs));
    }

    /**
     * Satu baris event untuk $link, terisi dari data request.
     */
    public function record(Request $request, Link $link): ClickEvent
    {
        $now = CarbonImmutable::now('UTC');
        $ip = (string) $request->ip();

        $ua = $request->userAgent();
        $verdict = $this->detector->detect(
            $ua,
            acceptsHtml: $request->acceptsHtml(),
            acceptLanguage: $request->headers->get('Accept-Language'),
        );

        $hash = $this->hasher->hash($ip, $now);   // CarbonImmutable implements DateTimeInterface

        $alasan = $this->alasanBot($request, $verdict, $hash, $ip);

        $parsed = $this->parser->parse($ua);

        return ClickEvent::create([
            'link_id' => $link->id,
            'occurred_at' => $now->format('Y-m-d H:i:s.v'),
            'occurred_on' => DayWindow::localDateOf($now),
            'visitor_hash' => $hash,
            'referrer_host' => $this->referrerHost($request),
            'device_type' => $parsed->device,
            'browser_family' => $parsed->browser,
            'os_family' => $parsed->os,
            'is_bot' => $alasan !== null,
            'bot_name' => $alasan['name'] ?? null,
            'bot_category' => $alasan['category'] ?? null,
            'source' => $this->source($request),
            'user_agent' => $ua,
            'created_at' => $now,
        ]);
    }

    /**
     * Menentukan (dan sekaligus menaikkan) jendela laju per pengunjung.
     *
     * Mengembalikan null bila dalam batas. Batas di atas ini TIDAK memblokir
     * di v1 — ia hanya menandai, sesuai shadow mode.
     */
    private function alasanBot(Request $request, BotVerdict $verdict, string $hash, string $ip): ?array
    {
        if ($this->melebihiLaju($hash)) {
            return ['name' => 'rate_limited', 'category' => 'unknown'];
        }

        if ($verdict->isBot) {
            return ['name' => $verdict->name, 'category' => $verdict->category];
        }

        if ($this->datacenters->matches($ip)) {
            return ['name' => 'datacenter_cidr', 'category' => 'scraper'];
        }

        return null;
    }

    /**
     * Fixed-window counter dengan key (hash, menit-berjalan).
     *
     * `Cache::add` dipakai supaya atomik: dua proses yang sama-sama menambah
     * jendela pertama tidak bisa saling menimpa.
     */
    private function melebihiLaju(string $hash): bool
    {
        $limit = max(1, (int) config('snipmark.redirect_rate_limit', 60));
        $jendela = now()->format('YmdHi');
        $key = self::CACHE_PREFIX.$hash.':'.$jendela;

        $ada = Cache::add($key, 1, now()->addMinute());

        if ($ada) {
            return false;
        }

        $jumlah = Cache::increment($key) ?: 2;

        return $jumlah > $limit;
    }

    /**
     * Referrer: host saja (tanpa path), dinormalisasi, tanpa www.
     *
     * Path referrer SENGAJA dibuang: ia bisa memuat query string berisi token
     * sesi, dan menyimpannya bocor ke riwayat orang lain tanpa nilai analitik.
     */
    private function referrerHost(Request $request): ?string
    {
        $raw = $request->headers->get('referer');

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $host = parse_url($raw, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower($host);

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    private function source(Request $request): string
    {
        $ua = strtolower($request->userAgent() ?? '');

        return match (true) {
            str_contains($ua, 'curl') || str_contains($ua, 'wget') => 'curl',
            str_contains($ua, 'http') || str_contains($ua, 'client') => 'sdk',
            default => 'web',
        };
    }
}
