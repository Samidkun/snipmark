<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Mengklasifikasi traffic otomatis berdasar user-agent.
 *
 * Kelas MURNI: tanpa DB, tanpa request, tanpa session — supaya bisa diuji lengkap
 * dan dipakai ulang oleh jalur redirect maupun perintah re-parse.
 *
 * PENTING (jangan overstated di dokumentasi): deteksi berbasis UA bukan kontrol
 * keamanan. Ia bisa dipalsukan siapa pun yang mau. Fungsinya adalah MEMBERSIHKAN
 * METRIK dari bot yang tidak berusaha bersembunyi (crawler, scanner, monitor,
 * link-preview), bukan menahan penyerang. Spec §6.2.
 */
final class BotDetector
{
    /**
     * token => [nama_tersimpan, kategori]
     *
     * URUTAN PENTING — entri pertama yang cocok menang. TelegramBot harus sebelum
     * Twitterbot karena UA-nya berisi keduanya: "TelegramBot (like TwitterBot)".
     */
    private const TOKENS = [
        // search engine crawlers
        'googlebot' => ['Googlebot', 'search'],
        'bingbot' => ['Bingbot', 'search'],
        'bingpreview' => ['BingPreview', 'search'],
        'duckduckbot' => ['DuckDuckBot', 'search'],
        'yandexbot' => ['YandexBot', 'search'],
        'baiduspider' => ['Baiduspider', 'search'],
        'applebot' => ['Applebot', 'search'],
        'petalbot' => ['PetalBot', 'search'],

        // SEO crawlers
        'semrushbot' => ['SemrushBot', 'seo'],
        'ahrefsbot' => ['AhrefsBot', 'seo'],
        'ahrefssiteaudit' => ['AhrefsSiteAudit', 'seo'],
        'dotbot' => ['DotBot', 'seo'],
        'mj12bot' => ['MJ12bot', 'seo'],
        'dataprovider' => ['DataProvider', 'seo'],

        // HTTP clients & automation
        'python-requests' => ['python-requests', 'scraper'],
        'python-urllib' => ['Python-urllib', 'scraper'],
        'go-http-client' => ['Go-http-client', 'scraper'],
        'node-fetch' => ['node-fetch', 'scraper'],
        'headlesschrome' => ['HeadlessChrome', 'scraper'],
        'phantomjs' => ['PhantomJS', 'scraper'],
        'facebookexternalhit' => ['facebookexternalhit', 'chat_preview'],
        'okhttp' => ['okhttp', 'scraper'],
        'curl' => ['curl', 'scraper'],
        'wget' => ['Wget', 'scraper'],
        'axios' => ['axios', 'scraper'],
        'java' => ['Java', 'scraper'],
        'nikto' => ['Nikto', 'scraper'],
        'sqlmap' => ['sqlmap', 'scraper'],
        'zgrab' => ['zgrab', 'scraper'],
        'censysinspect' => ['Censys', 'scraper'],

        // uptime monitors
        'uptimerobot' => ['UptimeRobot', 'monitor'],
        'pingdom' => ['Pingdom', 'monitor'],
        'statuscake' => ['StatusCake', 'monitor'],
        'site24x7' => ['Site24x7', 'monitor'],

        // chat / social link previews — URUTAN: telegrambot sebelum twitterbot
        'telegrambot' => ['TelegramBot', 'chat_preview'],
        'twitterbot' => ['Twitterbot', 'chat_preview'],
        'slackbot' => ['Slackbot', 'chat_preview'],
        'discordbot' => ['Discordbot', 'chat_preview'],
        'whatsapp' => ['WhatsApp', 'chat_preview'],
        'linkedinbot' => ['LinkedInBot', 'chat_preview'],

        // feeds
        'feedfetcher' => ['FeedFetcher', 'feed'],
        'rssmix' => ['RSSMix/2.0', 'feed'],
    ];

    /**
     * Cocokkan UA terhadap daftar token, lalu fallback struktural.
     *
     * @param  bool  $acceptsHtml  permintaan menyatakan menerima text/html
     * @param  string|null  $acceptLanguage  header Accept-Language (null = tidak dikirim)
     */
    public function detect(
        ?string $userAgent,
        bool $acceptsHtml = true,
        ?string $acceptLanguage = null,
    ): BotVerdict {
        $ua = strtolower(trim($userAgent ?? ''));

        // Lapisan 1: token. Jalan lebih dulu supaya UA bot yang pendek
        // ("curl/8.4.0" = 10 char) tetap kena sebelum terlanjur masuk jalur struktural.
        foreach (self::TOKENS as $token => [$name, $category]) {
            if ($ua !== '' && $this->contains($ua, $token)) {
                return BotVerdict::bot($name, $category);
            }
        }

        // Lapisan 2: struktural. UA kosong atau terlalu pendek untuk dipercaya.
        if ($ua === '' || strlen($ua) < self::MIN_UA_LENGTH) {
            if ($this->looksAutomated($acceptsHtml, $acceptLanguage)) {
                return BotVerdict::bot('unknown', 'unknown');
            }
        }

        return BotVerdict::human();
    }

    /**
     * Sinyal pendukung: permintaan yang tidak menyatakan menerima HTML, atau
     * tidak mengirim Accept-Language sama sekali. Browser nyata mengirim keduanya.
     */
    private function looksAutomated(bool $acceptsHtml, ?string $acceptLanguage): bool
    {
        if (! $acceptsHtml) {
            return true;
        }

        return $acceptLanguage === null || trim($acceptLanguage) === '';
    }

    /**
     * Token pendek & generik (curl, java, wget) hanya dihitung jika berdiri sendiri,
     * supaya "java" tidak mencocokkan "javascript" dan sejenisnya.
     */
    private function contains(string $haystack, string $needle): bool
    {
        if (strlen($needle) > self::BOUNDARY_MAX_LEN || ! ctype_alnum($needle)) {
            return str_contains($haystack, $needle);
        }

        return preg_match('/(?<![a-z0-9])'.preg_quote($needle, '/').'(?![a-z0-9])/i', $haystack) === 1;
    }

    /** Token sepanjang ini atau kurang dianggap generik dan butuh batas kata. */
    private const BOUNDARY_MAX_LEN = 7;

    /*
     * UA di bawah panjang ini tidak memuat cukup informasi untuk dipakai sebagai
     * bukti browser nyata ("abc", "Mozilla" saja, dsb). Nilainya konservatif: UA
     * browser modern selalu > 60 karakter. Spec §6.1 lapisan 2.
     */
    private const MIN_UA_LENGTH = 15;
}
