<?php

return [
    /*
     * Zona waktu untuk "satu hari" pada analytics (spec §4.4 / ADR-0004).
     * `occurred_at` disimpan UTC; batas hari dihitung di zona ini.
     * Mengubah nilai ini mengubah arti seluruh angka historis — jangan diubah
     * setelah ada data produksi.
     */
    'analytics_timezone' => env('SNIPMARK_TIMEZONE', 'Asia/Jakarta'),

    /*
     * Batas laju jalur redirect, per visitor_hash (spec §6.3).
     * v1 SHADOW MODE: kelebihan tidak diblokir, hanya ditandai sebagai bot.
     */
    'redirect_rate_limit' => (int) env('SNIPMARK_REDIRECT_RATE_LIMIT', 60),

    /*
     * Batas laju PEMBUATAN tautan, per pengguna terautentikasi (per menit).
     * Berbeda dari redirect_rate_limit: yang ini MENEGAKKAN (bukan shadow mode),
     * karena tanpa batas satu akun bisa membanjiri tabel `links` tanpa henti.
     * Hanya berlaku untuk pembuatan — mengubah tautan tidak menambah baris dan
     * tidak dihitung. Percobaan yang ditolak validasi juga tidak memakan kuota.
     */
    'link_create_rate_limit' => (int) env('SNIPMARK_LINK_CREATE_RATE_LIMIT', 60),

    /*
     * Enforcement bot. Default false (spec §6.3): deteksi dicatat, tidak memblokir.
     * Alasan: memblokir berdasarkan CIDR pusat data bisa memblokir penguji sendiri,
     * kantor ber-NAT, dan pengguna VPN sah — pada aplikasi demo itu mengosongkan
     * analytics dan tampak seperti kerusakan rollup.
     */
    'bot_enforce' => (bool) env('SNIPMARK_BOT_ENFORCE', false),

    /* TTL cache pemetaan kode -> destination pada jalur redirect (detik). */
    'redirect_cache_ttl' => (int) env('SNIPMARK_CACHE_TTL', 3600),

    /*
     * CIDR pusat data / penyedia cloud (heuristik, BUKAN lookup ASN — spec §6.3).
     * Daftar ini adalah PROXY, bukan pemetaan ASN sungguhan: pemetaan IP->ASN
     * butuh basis data berlisensi (MaxMind) yang tidak dipakai di v1.
     * Sumber: rentang publik AWS/GCP/Azure/DigitalOcean/Hetzner/OVH.
     * JANGAN klaim ini "deteksi ASN" di dokumentasi.
     */
    'datacenter_cidrs' => [
        // AWS
        '3.0.0.0/8', '13.32.0.0/15', '15.177.0.0/18', '18.128.0.0/9', '52.0.0.0/8',
        '54.0.0.0/8', '99.77.0.0/16', '204.236.128.0/17',
        // GCP
        '8.34.208.0/20', '34.0.0.0/9', '35.184.0.0/13', '104.154.0.0/15',
        // Azure
        '13.64.0.0/11', '20.0.0.0/8', '40.64.0.0/10', '51.104.0.0/13', '104.40.0.0/13',
        // DigitalOcean
        '104.131.0.0/16', '138.68.0.0/16', '159.65.0.0/16', '165.227.0.0/16', '167.99.0.0/16',
        // Hetzner
        '5.9.0.0/16', '88.99.0.0/16', '116.202.0.0/16', '138.201.0.0/16',
        // OVH
        '51.38.0.0/16', '51.68.0.0/16', '54.36.0.0/15', '137.74.0.0/16',
        // Googlebot / crawler infra yang tidak menyembunyikan diri
        '66.249.64.0/19',
    ],
];
