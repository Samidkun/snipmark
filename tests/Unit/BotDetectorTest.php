<?php

use App\Support\BotDetector;
use App\Support\BotVerdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kelas murni: tanpa DB, tanpa network, tanpa session.
 * S6: ≥ 30 user-agent nyata harus terklasifikasi benar.
 */
final class BotDetectorTest extends TestCase
{
    private BotDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new BotDetector;
    }

    /** UA penuh dari dunia nyata => [nama, kategori] */
    public static function knownBots(): array
    {
        return [
            'googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html', 'Googlebot', 'search'],
            'bingbot' => ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)', 'Bingbot', 'search'],
            'duckduckbot' => ['DuckDuckBot/1.1; (+http://duckduckgo.com/duckduckbot.html)', 'DuckDuckBot', 'search'],
            'yandexbot' => ['Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)', 'YandexBot', 'search'],
            'baiduspider' => ['Mozilla/5.0 (compatible; Baiduspider/2.0; +http://www.baidu.com/search/spider.html)', 'Baiduspider', 'search'],
            'applebot' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/11.0 Safari/605.1.15 (Applebot/0.1; +http://www.apple.com/go/applebot)', 'Applebot', 'search'],
            'petalbot' => ['Mozilla/5.0 (compatible; PetalBot; +https://apis-prod-aspen.petal-bot.com)', 'PetalBot', 'search'],
            'semrushbot' => ['Mozilla/5.0 (compatible; SemrushBot/7~bl; +http://www.semrush.com/bot.html)', 'SemrushBot', 'seo'],
            'ahrefsbot' => ['Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)', 'AhrefsBot', 'seo'],
            'dotbot' => ['Mozilla/5.0 (compatible; DotBot/1.2; +https://opensiteexplorer.org/dotbot)', 'DotBot', 'seo'],
            'mj12bot' => ['Mozilla/5.0 (compatible; MJ12bot/v1.4.8; http://mj12bot.com/)', 'MJ12bot', 'seo'],
            'curl' => ['curl/8.4.0', 'curl', 'scraper'],
            'wget' => ['Wget/1.21.3', 'Wget', 'scraper'],
            'python-requests' => ['python-requests/2.31.0', 'python-requests', 'scraper'],
            'python-urllib' => ['Python-urllib/3.11', 'Python-urllib', 'scraper'],
            'go-http-client' => ['Go-http-client/1.1', 'Go-http-client', 'scraper'],
            'okhttp' => ['okhttp/4.12.0', 'okhttp', 'scraper'],
            'node-fetch' => ['node-fetch/1.0 (+https://github.com/bitinn/node-fetch)', 'node-fetch', 'scraper'],
            'axios' => ['axios/1.6.2', 'axios', 'scraper'],
            'java' => ['Java/17.0.2', 'Java', 'scraper'],
            'headlesschrome' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/120.0.0.0 Safari/537.36', 'HeadlessChrome', 'scraper'],
            'phantomjs' => ['Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 (KHTML, like Gecko) PhantomJS/2.1.1 Safari/537.36', 'PhantomJS', 'scraper'],
            'uptimerobot' => ['Mozilla/5.0 (compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)', 'UptimeRobot', 'monitor'],
            'pingdom' => ['Pingdom.com_bot_version_1.4_(http://www.pingdom.com/)', 'Pingdom', 'monitor'],
            'statuscake' => ['https://www.statuscake.com', 'StatusCake', 'monitor'],
            'slackbot' => ['Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)', 'Slackbot', 'chat_preview'],
            'telegrambot' => ['TelegramBot (like TwitterBot)', 'TelegramBot', 'chat_preview'],
            'discordbot' => ['Mozilla/5.0 (compatible; Discordbot/2.0; +https://disco.org)', 'Discordbot', 'chat_preview'],
            'whatsapp' => ['WhatsApp/2.23.17.72', 'WhatsApp', 'chat_preview'],
            'facebookexternalhit' => ['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)', 'facebookexternalhit', 'chat_preview'],
            'twitterbot' => ['Twitterbot/1.0', 'Twitterbot', 'chat_preview'],
            'linkedinbot' => ['Mozilla/5.0 (Windows NT 5.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2272.101 Safari/537.36 LinkedInBot/1.0', 'LinkedInBot', 'chat_preview'],
            'feedfetcher' => ['FeedFetcher-Google; (+http://www.google.com/feedfetcher.html)', 'FeedFetcher', 'feed'],
            'rssmix' => ['RSSMix/2.0 - http://rssmix.com/users/88797', 'RSSMix/2.0', 'feed'],
            'nikto' => ['Mozilla/5.00 (Nikto/2.1.6) (Evasions:None) (Test:portmap)', 'Nikto', 'scraper'],
            'sqlmap' => ['sqlmap/1.7#stable (https://sqlmap.org)', 'sqlmap', 'scraper'],
            'zgrab' => ['zgrab/0.x', 'zgrab', 'scraper'],
            'censys' => ['Mozilla/5.0 (compatible; CensysInspect/1.1; +https://about.censys.io/)', 'Censys', 'scraper'],
        ];
    }

    #[DataProvider('knownBots')]
    public function test_mengenal_bot_dari_token(string $ua, string $name, string $category): void
    {
        $verdict = $this->detector->detect($ua);

        self::assertTrue($verdict->isBot, "Seharusnya bot: {$ua}");
        self::assertSame($name, $verdict->name, "Nama salah untuk: {$ua}");
        self::assertSame($category, $verdict->category, "Kategori salah untuk: {$ua}");
    }

    #[DataProvider('knownBots')]
    public function test_pembandingan_token_tidak_peka_huruf(string $ua, string $name, string $category): void
    {
        self::assertTrue($this->detector->detect(strtoupper($ua))->isBot);
        self::assertTrue($this->detector->detect(strtolower($ua))->isBot);
    }

    /** @return array<string, array{0:string}> UA manusia yang tidak boleh dibilang bot */
    public static function realHumans(): array
    {
        return [
            'chrome windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'],
            'edge windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0'],
            'firefox windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:121.0) Gecko/20100101 Firefox/121.0'],
            'safari macos' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Safari/605.1.15'],
            'chrome linux' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'],
            'opera windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 OPR/106.0.0.0'],
            'chrome android' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.6099.230 Mobile Safari/537.36'],
            'safari iphone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1'],
            'firefox android' => ['Mozilla/5.0 (Android 14; Mobile; rv:121.0) Gecko/121.0 Firefox/121.0'],
            'samsung browser' => ['Mozilla/5.0 (Linux; Android 13; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/23.0 Chrome/115.0.0.0 Mobile Safari/537.36'],
        ];
    }

    #[DataProvider('realHumans')]
    public function test_tidak_menyalahkan_browser_manusia(string $ua): void
    {
        $verdict = $this->detector->detect($ua, acceptsHtml: true, acceptLanguage: 'id-ID,id;q=0.9');

        self::assertFalse($verdict->isBot, "Seharusnya manusia: {$ua}");
        self::assertNull($verdict->name);
        self::assertNull($verdict->category);
    }

    /**
     * Fallback struktural: UA aneh dianggap bot HANYA jika ada sinyal pendukung,
     * sesuai §6.1. Ini mencegah browser aneh-but-sah kena hitung sebagai bot.
     */
    public function test_ua_kosong_dengan_sinyal_pendukung_dianggap_bot(): void
    {
        $verdict = $this->detector->detect('', acceptsHtml: false, acceptLanguage: null);

        self::assertTrue($verdict->isBot);
        self::assertSame('unknown', $verdict->name);
        self::assertSame('unknown', $verdict->category);
    }

    public function test_ua_kosong_tanpa_sinyal_pendukung_bukan_bot(): void
    {
        // Browser yang benar-benar tidak mengirim UA tapi menerima HTML dan
        // mengirim Accept-Language: jangan langsung dibuang dari metrik manusia.
        $verdict = $this->detector->detect('', acceptsHtml: true, acceptLanguage: 'en-US');

        self::assertFalse($verdict->isBot);
    }

    public function test_ua_sangat_pendek_tanpa_sinyal_pendukung_bukan_bot(): void
    {
        $verdict = $this->detector->detect('abc', acceptsHtml: true, acceptLanguage: 'en-US');

        self::assertFalse($verdict->isBot);
    }

    public function test_ua_sangat_pendek_dengan_sinyal_pendukung_dianggap_bot(): void
    {
        $verdict = $this->detector->detect('abc', acceptsHtml: false, acceptLanguage: null);

        self::assertTrue($verdict->isBot);
        self::assertSame('unknown', $verdict->name);
    }

    public function test_null_user_agent_dilayani_tanpa_error(): void
    {
        $verdict = $this->detector->detect(null, acceptsHtml: false, acceptLanguage: null);

        self::assertTrue($verdict->isBot);
        self::assertSame('unknown', $verdict->name);
    }

    /**
     * Regresi penting: token yang merupakan SUBSTRING dari kata biasa tidak boleh
     * menimbulkan positive palsu. "java" ada di dalam "JavaScript"; "bot" ada di
     * dalam "Chromebot"... cek yang paling mungkin salah.
     */
    public function test_token_bukan_substring_dari_ua_manusia(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

        self::assertFalse($this->detector->detect($ua, true, 'id')->isBot);
    }

    /**
     * LUBANG YANG DITEMUKAN MUTATION CHECK (2026-09-16):
     * BOUNDARY_MAX_LEN dinaikkan/turunkan tidak terdeteksi test mana pun, artinya
     * logika batas kata tidak diuji. Kasus nyata: UA yang memuat "JavaScriptCore"
     * mengandung "java" — token generik TIDAK boleh cocok di dalam kata lain.
     */
    public function test_ua_yang_memuat_kata_javascript_bukan_bot(): void
    {
        $ua = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) JavaScriptCore/537.36 Safari/537.36';

        self::assertFalse($this->detector->detect($ua, true, 'en-US')->isBot,
            '"java" tidak boleh cocok di dalam "javascript"');
    }

    /**
     * Token generik HARUS tetap cocok saat berdiri sendiri, dibatasi pemisah
     * bukan huruf/angka (mis. "curl/8.4.0" -> token "curl").
     */
    public function test_token_generik_masih_cocok_saat_dibatasi_pemisah(): void
    {
        foreach (['curl/8.4.0', 'Wget/1.21', 'Java/17.0.2', 'zgrab/0.x'] as $ua) {
            self::assertTrue($this->detector->detect($ua)->isBot, "harus bot: {$ua}");
        }
    }

    /**
     * LUBANG KEDUA dari mutation check: Accept-Language sebagai string KOSONG
     * (bukan null) belum pernah diuji. Request tanpa Accept-Language yang berarti
     * adalah sinyal bot, dan itu harus berlaku untuk '' maupun null.
     */
    public function test_accept_language_string_kosong_juga_dianggap_sinyal_bot(): void
    {
        $verdict = $this->detector->detect('', acceptsHtml: true, acceptLanguage: '   ');

        self::assertTrue($verdict->isBot, 'Accept-Language kosong/whitespace = sinyal bot');
        self::assertSame('unknown', $verdict->name);
    }

    /**
     * UA panjang & manusiawi yang menerima HTML dan mengirim Accept-Language
     * tidak boleh dituduh bot hanya karena tidak dikenali token.
     */
    public function test_ua_tidak_dikenal_tapi_manusiawi_bukan_bot(): void
    {
        $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) SomeBrandNewBrowser/1.0';

        self::assertFalse($this->detector->detect($ua, true, 'id-ID,id;q=0.9')->isBot);
    }

    public function test_verdict_manusia_kosong_semua_bagian(): void
    {
        $verdict = BotVerdict::human();

        self::assertFalse($verdict->isBot);
        self::assertNull($verdict->name);
        self::assertNull($verdict->category);
    }

    public function test_kategory_yang_dihasilkan_selalu_ada_di_daftar_skema(): void
    {
        // enum() di MySQL/MariaDB akan MENOLAK nilai di luar daftar -> data rusak
        // diam-diam kalau konstanta PHP dan skema tidak sinkron.
        $allowed = ['search', 'scraper', 'seo', 'monitor', 'chat_preview', 'feed', 'unknown'];

        $seen = [(new BotDetector)->detect('', acceptsHtml: false, acceptLanguage: null)->category];
        foreach (self::knownBots() as [$ua, , $category]) {
            $seen[] = $category;
            $verdict = (new BotDetector)->detect($ua);
            self::assertContains($verdict->category, $allowed, "kategori {$verdict->category} tidak ada di enum");
        }

        // Bandingkan sebagai HIMPUNAN, bukan multiset: yang diuji adalah "tidak ada
        // kategori asing" + "tidak ada enum mati", bukan berapa kali tiap kategori muncul.
        self::assertEqualsCanonicalizing($allowed, array_values(array_unique($seen)),
            'enum dan detektor tidak sinkron');
    }
}
