<?php

namespace Database\Seeders;

use App\Models\Link;
use App\Models\User;
use App\Support\Analytics\DayWindow;
use App\Support\BotDetector;
use App\Support\UserAgentParser;
use App\Support\VisitorHasher;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Membuat lalu-lintas sintetis yang MEYAKINKAN: trafik harian nyata, hari
 * akhir pekan turun, lonjakan kampanye, dan campuran bot yang realistis.
 *
 * Kenapa perlu: mesin rollup tidak bisa dinilai ( maupun dipamerkan ) tanpa
 * data. Selain itu, seeder ini adalah "bank soal" untuk benchmark — angka
 * sync-vs-queue di docs/benchmarks.md berasal dari data yang dibangkitkan di sini,
 * bukan angka hiasan.
 *
 * Dipakai di dev/demo. TIDAK untuk produksi.
 */
class SyntheticTrafficSeeder extends Seeder
{
    /** UA browser dunia nyata yang akan dibagikan di antara klik manusia. */
    private const HUMAN_UAS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15',
        'Mozilla/5.0 (X11; Linux x86_64; rv:121.0) Gecko/20100101 Firefox/121.0',
        'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.6099.230 Mobile Safari/537.36',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (iPad; CPU OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (Linux; Android 13; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/23.0 Chrome/115.0.0.0 Mobile Safari/537.36',
    ];

    private const BOT_UAS = [
        'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
        'curl/8.4.0',
        'python-requests/2.31.0',
        'Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)',
        'Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)',
        'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
        'Mozilla/5.0 (compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)',
    ];

    /** Jumlah link yang dibangkitkan untuk demo. */
    private const TARGET_LINKS = 12;

    private const REFERRERS = [
        null, null, null,                 // direct paling sering
        'google.com', 'bing.com',
        'twitter.com', 't.co', 'facebook.com', 'reddit.com',
        'news.ycombinator.com', 'wa.me', 'linkedin.com',
        'www.instagram.com',
    ];

    private const DESTINATIONS = [
        'https://example.com/produk', 'https://tailwindcss.com/docs',
        'https://laravel.com/docs/13.x', 'https://github.com/laravel/laravel',
        'https://news.ycombinator.com/item?id=12345', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    ];

    /**
     * Catatan konstruktor: `Illuminate\Database\Seeder` TIDAK punya constructor,
     * jadi `parent::__construct()` akan melempar "Cannot call constructor".
     * Nilai dikonfigurasi lewat konstruktor kelas ini dan dijalankan langsung oleh
     * Snipmark seed command (bukan lewat `db:seed`, yang tidak bisa membawa argumen).
     *
     * @param  callable(string):void|null  $log  penerima output, default echoes
     */
    public function __construct(
        private readonly int $days = 90,
        private readonly int $clicksPerLink = 500,
        private $log = null,
    ) {}

    private function log(string $pesan): void
    {
        if ($this->log !== null) {
            ($this->log)($pesan);

            return;
        }

        echo $pesan.PHP_EOL;
    }

    public function run(): void
    {
        $days = max(1, $this->days);
        $perLink = max(1, $this->clicksPerLink);

        [$users, $links] = $this->ensureLinks($perLink);

        $hasher = new VisitorHasher((string) config('app.key'));
        $parser = new UserAgentParser;
        $detector = new BotDetector;
        $tz = config('snipmark.analytics_timezone', DayWindow::DEFAULT_TIMEZONE);

        $totalDibuat = 0;

        DB::transaction(function () use ($links, $days, $perLink, $hasher, $parser, $detector, $tz, &$totalDibuat) {
            $buffer = [];
            $hebat = $links->take(2)->pluck('id')->all();   // dua link "viral"

            foreach ($links as $link) {
                $target = $perLink;
                if (in_array($link->id, $hebat, true)) {
                    $target *= 6;
                }

                for ($i = 0; $i < $target; $i++) {
                    $hariLampu = $this->weightedDay($days);
                    $date = CarbonImmutable::now($tz)->subDays($hariLampu);

                    // Puncak jam kerja WIB, turun di akhir pekan (pola hari nyata).
                    $hour = $this->weightedHour($date->dayOfWeek);
                    $at = $date->setTime($hour, random_int(0, 59), random_int(0, 999));

                    $isBot = random_int(1, 100) <= 18;         // ~18% trafik otomatis
                    $ua = $isBot
                        ? self::BOT_UAS[array_rand(self::BOT_UAS)]
                        : self::HUMAN_UAS[array_rand(self::HUMAN_UAS)];

                    $verdict = $detector->detect($ua, acceptsHtml: true, acceptLanguage: 'id-ID');
                    $parsed = $parser->parse($ua);

                    $buffer[] = [
                        'link_id' => $link->id,
                        'occurred_at' => $at->setTimezone('UTC')->format('Y-m-d H:i:s.v'),
                        'occurred_on' => $at->format('Y-m-d'),
                        'visitor_hash' => $hasher->hash($this->fakeIp(), $at->toDateTime()),
                        'referrer_host' => self::REFERRERS[array_rand(self::REFERRERS)],
                        'device_type' => $parsed->device,
                        'browser_family' => $parsed->browser,
                        'os_family' => $parsed->os,
                        'is_bot' => $verdict->isBot,
                        'bot_name' => $verdict->name,
                        'bot_category' => $verdict->category,
                        'source' => 'web',
                        'user_agent' => Str::substr($ua, 0, 512),
                        'created_at' => now(),
                    ];

                    $totalDibuat++;

                    if (count($buffer) >= 500) {
                        DB::table('click_events')->insert($buffer);
                        $buffer = [];
                    }
                }
            }

            if ($buffer !== []) {
                DB::table('click_events')->insert($buffer);
            }

            // Sinkronkan counter denormalized dari kenyataan (sekali di akhir).
            foreach ($links as $link) {
                DB::table('links')->where('id', $link->id)->update([
                    'total_clicks' => DB::table('click_events')->where('link_id', $link->id)->count(),
                ]);
            }
        });

        $this->log("Selesai: {$totalDibuat} event dibuat untuk {$links->count()} link / {$days} hari.");
    }

    /**
     * @return array{0: Collection, 1: Collection}
     */
    private function ensureLinks(int $perLink): array
    {
        $users = User::query()->get();

        if ($users->isEmpty()) {
            $users = User::factory()->count(2)->create();
            $this->log('Tidak ada user - membuat 2 user contoh.');
        }

        $links = Link::query()->inRandomOrder()->limit(12)->get();

        if ($links->count() < self::TARGET_LINKS) {
            $butuh = self::TARGET_LINKS - $links->count();
            $daftarUser = $users->values();

            for ($i = 0; $i < $butuh; $i++) {
                // Ganti user secara round-robin: satu user boleh punya banyak link.
                Link::createWithUniqueCode([
                    'user_id' => $daftarUser[$i % $daftarUser->count()]->id,
                    'destination' => self::DESTINATIONS[$i % count(self::DESTINATIONS)],
                ]);
            }

            $this->log("Membuat {$butuh} link contoh (total target ".self::TARGET_LINKS.').');
            $links = Link::query()->inRandomOrder()->limit(self::TARGET_LINKS)->get();
        }

        return [$users, $links];
    }

    /**
     * Distribusi hari: baru lebih sering daripada lama (pola pertumbuhan nyata).
     */
    private function weightedDay(int $days): int
    {
        $x = max(1, $days);
        $r = abs($this->bell()) * ($x / 2);

        return min($x - 1, (int) $r);
    }

    /** Puncak jam kerja WIB; akhir lebih panjang & lebih rendah. */
    private function weightedHour(int $dayOfWeek): int
    {
        if ($dayOfWeek === 0 || $dayOfWeek === 6) {
            return random_int(9, 21);
        }

        $jam = [9, 10, 11, 12, 13, 14, 15, 16, 17, 19, 20, 21, 22];

        return $jam[array_rand($jam)];
    }

    private function bell(): float
    {
        return (mt_rand() / mt_getrandmax() + mt_rand() / mt_getrandmax() + mt_rand() / mt_getrandmax()) / 1.5 - 1;
    }

    /** IP acak untuk bahan hash (tidak pernah disimpan mentah). */
    private function fakeIp(): string
    {
        return implode('.', [random_int(1, 223), random_int(0, 255), random_int(0, 255), random_int(1, 254)]);
    }
}
