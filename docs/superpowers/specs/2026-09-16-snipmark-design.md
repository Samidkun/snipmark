# Snipmark — Design Spec

**Tier:** T1 — Client (full pipeline: docs + Playwright E2E + UAT)
**Tanggal:** 2026-09-16
**Status:** Menunggu review user sebelum Stage 1.5
**Klasifikasi:** Architectural (project baru)

---

## 1. Tujuan & Kriteria Sukses

Snipmark adalah URL shortener dengan **analytics engine** sebagai fitur unggulan.
Ini bukan "shortener + chart"; yang dinilai adalah kualitas **agregasi, idempotency,
dan reconciliation**-nya.

**Kriteria sukses (biner, diuji di Stage 11/13):**

| # | Kriteria | Cara dibuktikan |
|---|----------|-----------------|
| S1 | Redirect `/c/{code}` mengembalikan 302 ke destination dan menambah `total_clicks` tepat 1 | PHPUnit feature test + E2E |
| S2 | Klik tercatat sebagai baris di `click_events` dengan metadata (device/browser/os/referrer) | PHPUnit |
| S3 | `snipmark:rollup:day {date}` menghasilkan baris `link_daily_rollups` yang benar | PHPUnit (unit + feature) |
| S4 | Perintah rollup **idempoten**: dijalankan 3× pada hari yang sama → hasil identik, tanpa baris ganda | PHPUnit (assertion count + checksum) |
| S5 | `snipmark:rollup:reconcile` mendeteksi drift dan melaporkannya dengan tepat | PHPUnit (sengaja rusak data → deteksi) |
| S6 | Bot terklasifikasi benar untuk ≥ 30 UA nyata | PHPUnit data provider |
| S7 | Dashboard menampilkan total, unique visitor, dan sparkline 7 hari dari rollup | E2E |
| S8 | Halaman analytics menampilkan breakdown device/browser/os/referrer dengan toggle "sertakan bot" | E2E |
| S9 | `visitor_hash` tidak pernah menyimpan IP mentah (tidak ada kolom IP di skema) | Schema assertion test |
| S10 | Aplikasi boot dan **semua halaman bisa dipakai** dengan `APP_ENV=production`, HTTP **dan** HTTPS | `scripts/rehearse-production.sh` |
| S11 | CSP produksi tidak memblokir satu pun script inline Livewire | Rehearsal: console bersih (0 CSP violation) |
| S12 | Suite E2E hijau terhadap build produksi | Rehearsal |
| S13 | Benchmark sync-vs-queue terdokumentasi dengan angka nyata | `docs/benchmarks.md` + perintah reproducible |
| S14 | `user_agent` mentah tersimpan untuk setiap event non-bot | PHPUnit (assert kolom terisi, dipotong 512) |

**Definisi "selesai":** S1–S13 terbukti dengan output yang dibaca ulang, bukan diklaim.

---

## 2. Batasan Environment (terverifikasi 2026-09-16)

```
PHP        8.5.10  (NTS, OPcache)          ✅
Composer   2.10.3                          ✅
Node       26.8.2 / npm 12.0.2             ✅
MariaDB    12.3.3  (127.0.0.1:3306, root tanpa password)  ✅ LIVE
PostgreSQL 14 (client+server terpasang)    tersedia, tidak dipakai
pdo_sqlite TIDAK ADA  ⚠️                   → SQLite mustahil
bcmath     TIDAK ADA  ⚠️                   → hindari kalkulasi desimal bcmath
redis      TIDAK ADA  ⚠️                   → cache=file, queue=database, Horizon mustahil
docker     daemon TIDAK jalan              → tidak dipakai
gitleaks   tidak ada                       → pakai secret_gate.py bawaan
```

**Konsekuensi wajib:**
- `phpunit.xml` default (`DB_CONNECTION=sqlite`, `:memory:`) **HARUS** diganti ke MariaDB.
  (Ini bug #2 yang sudah pernah terjadi di `reference-tracker`.)
- Tidak ada `laravel/horizon`. Queue monitoring = perintah artisan + tabel `failed_jobs`.

---

## 3. Stack & Versi (hasil probe nyata, bukan asumsi)

| Komponen | Versi | Catatan |
|----------|-------|---------|
| `laravel/framework` | 13.32.0 | `php artisan about` exit 0 di PHP 8.5 — tidak ada fatal deprecation |
| `livewire/livewire` | 4.4.5 | `composer require --dry-run` → exit 0 ✅ |
| `laravel/fortify` | 1.39.0 | backend auth saja, tidak menyentuh aset frontend |
| `laravel/pint` | 1.32.1 | bawaan skeleton |
| `spatie/laravel-permission` | — | **TIDAK dipakai.** Desain ini tidak mengenal role; setiap user hanya melihat link miliknya via `LinkPolicy`. Dipasang = dependensi tanpa konsumen (YAGNI). |
| `phpunit/phpunit` | 12.5.35 | bawaan skeleton |
| `tailwindcss` + `@tailwindcss/vite` | ^4.0.0 | bawaan skeleton |
| `vite` | ^8.0.0 | bawaan skeleton |
| MariaDB | 12.3.3 | dev + test DB terpisah |

**DITOLAK — `pestphp/pest` v5.** `composer require --dry-run` **exit 2**:
`laravel/pao v1.1.5 conflicts with pestphp/pest` (dan semua versi v4.2–v4.6).
`laravel/pao` adalah dev-dependency bawaan skeleton Laravel 13.
Keputusan: PHPUnit 12.5. Tidak ada dependency baru, tidak ada konflik.

**Installer yang DILARANG:** `php artisan breeze:install`.
Bug #3 di `SOP_PROGRESS.md` mencatat: menimpa `routes/web.php` (semua route fitur hilang),
downgrade Tailwind 4→3, duplikasi entri `package.json` (react v18+v19), dan melaporkan
"successfully" sementara `npm error` lewat. Fortify tidak punya kelas risiko ini
karena tidak menyentuh aset frontend.

---

## 4. Skema Data

### 4.1 `links`
```
id              BIGINT UNSIGNED PK
user_id         BIGINT UNSIGNED FK → users.id (cascade delete)
code            CHAR(7)  UNIQUE INDEX    -- base62, 7 char (~3,5×10^12 ruang kunci)
destination     TEXT NOT NULL            -- divalidasi sebagai URL http/https, max 2048
is_active       BOOLEAN DEFAULT true
total_clicks    BIGINT UNSIGNED DEFAULT 0  -- denormalized counter, atomic increment
expires_at      DATETIME NULL
created_at, updated_at, deleted_at (soft delete)
```
Index: `UNIQUE(code)`, `INDEX(user_id, created_at)`.

**Pembangkitan kode & keamanan-konkurensi.**
Kode dibuat acak (base62, 7 char). Tabrakan diselesaikan dengan
**unique index + retry**, bukan "SELECT dulu baru INSERT" — pola cek-lalu-tulis itu
balah terhadap konkurensi (dua request membaca "kosong" bersamaan, satu menang,
satu menabrak index). Algoritma:

```
for attempt in 1..5:
    kode = random_base62(7)
    try: INSERT; return kode
    catch QueryException (23000 duplicate): continue
throw TooManyCollisionsException
```

Unik index adalah penjaga sebenarnya; loop retry hanya menyerap tabrakan acak.
Diuji dengan test yang menyuntik kode duplikat dan membuktikan retry-nya bekerja.

### 4.2 `click_events` (append-only; tidak pernah di-UPDATE)
```
id              BIGINT UNSIGNED PK
link_id         BIGINT UNSIGNED FK → links.id (cascade)
occurred_at     DATETIME(3)      -- UTC, milidetik
visitor_hash    CHAR(64)         -- HMAC-SHA256(ip|Y-m-d, APP_KEY) — BUKAN IP
referrer_host   VARCHAR(255) NULL
user_agent      VARCHAR(512) NULL     -- UA mentah, dipotong 512 char (lihat catatan)
device_type     ENUM('mobile','tablet','desktop','other') NOT NULL
browser_family  VARCHAR(32) NOT NULL
os_family       VARCHAR(32) NOT NULL
is_bot          BOOLEAN NOT NULL
bot_name        VARCHAR(32) NULL
bot_category    ENUM('search','scraper','seo','monitor','chat_preview','feed','unknown') NULL
source          ENUM('web','curl','sdk','api') NOT NULL DEFAULT 'web'
INDEX(link_id, occurred_at)
INDEX(occurred_at)              -- untuk rollup per-hari lintas link
```
**Tidak ada kolom `ip`.** Namun `user_agent` **mentah disimpan** (dipotong 512 char).

**Alasan menyimpan UA mentah (keputusan user, 2026-09-16).** Hasil parsing
(`device_type`, `browser_family`, `os_family`) adalah *turunan*. Jika parser punya bug,
turunan itu salah di sumbernya, dan rollup §5 — yang idempoten terhadap `click_events` —
hanya bisa menghitung ulang dari data yang sudah salah. Menyimpan UA mentah membuat
seluruh riwayat **dapat diparse ulang** setelah parser diperbaiki
(`snipmark:reparse-user-agents`). Ini yang membuat mesin rollup benar-benar dapat
dipercaya, bukan hanya secara teori.

**Konsekuensi privasi yang diterima secara sadar:** UA bukan pengenal pribadi
(tidak ada IP, tidak ada cookie, tidak ada fingerprint gabungan), tetapi ia dapat
mengungkap perangkat/bahasa/versi OS. Kebijakan: kolom ini **tidak pernah** ditampilkan
di UI, tidak diekspor, dan dibatasi 512 karakter. `visitor_hash` tetap berotasi harian
dan IP tetap tidak disimpan. Catat di ADR-0002.

**Upgrade path:** `snipmark:reparse-user-agents` (recompute turunan dari UA mentah)
tidak dibangun di v1; yang dibangun adalah kolomnya + dokumentasi bahwa ia ada untuk
tujuan ini.

### 4.3 `link_daily_rollups`
```
id              BIGINT UNSIGNED PK
link_id         BIGINT UNSIGNED FK → links.id (cascade)
date            DATE NOT NULL
total           INT UNSIGNED NOT NULL      -- semua klik termasuk bot
human           INT UNSIGNED NOT NULL
bots            INT UNSIGNED NOT NULL
unique_visitors INT UNSIGNED NOT NULL      -- distinct visitor_hash (human saja)
by_device       JSON NOT NULL              -- {"mobile":10,"desktop":5,...} top 25
by_browser      JSON NOT NULL
by_os           JSON NOT NULL
by_referrer     JSON NOT NULL              -- {"(direct)":20,"google.com":3,...}
computed_at     DATETIME NOT NULL
UNIQUE(link_id, date)
INDEX(date)
```
Ukuran tabel = O(link × hari), bukan O(klik). Inilah yang menjaga dashboard tetap
sub-100ms saat `click_events` menembus jutaan baris.

**Konvensi zona waktu (WAJIB, jangan ditebak).**
`occurred_at` disimpan **UTC**. "Satu hari" untuk rollup dihitung dalam
`APP_TIMEZONE = Asia/Jakarta` (WIB), **bukan** UTC. Keduanya beda 7 jam, dan
salah pilih membuat angka "klik 90 hari terakhir" melenceng satu hari penuh di
setiap ujung rentang. Aturan konversi: hari rollup `D` memuat event dengan
`occurred_at` dalam `[D 00:00 WIB, D+1 00:00 WIB)` = `[D-1 17:00 UTC, D 17:00 UTC)`.
Konstanta offset dihitung lewat `timezone()`, bukan angka `-7` yang di-hardcode,
agar tetap benar jika TZ diubah. Dikunci oleh test pada jam 23:30 dan 00:30 WIB.

**Konvensi JSON dimensi:** objek dengan `count` menurun; maksimum 25 kunci;
sisa digabung ke kunci `"(other)"`. Referrer kosong/null → kunci `"(direct)"`.

---

## 5. Mesin Rollup (mahakarya)

### 5.1 Idempotent by full recompute
`php artisan snipmark:rollup:day {date} [--link=ID]`

Algoritma per (link, tanggal):
1. `SELECT` agregat dari `click_events WHERE link_id=? AND DATE(occurred_at)=?`
2. Bangun struktur hasil di memori.
3. `upsert` ke `link_daily_rollups` berdasarkan `UNIQUE(link_id, date)`.

**Menghitung ulang penuh dari raw, bukan `+= delta`.** Alasan: operasi delta rapuh —
satu job gagal di tengah membuat angka rusak permanen dan tanpa gejala. Full recompute
menjadikan kesalahan operasional tidak berbahaya: jalankan ulang, hasilnya benar.
Biaya: satu hari sibuk (mis. 1 juta klik) memakan beberapa detik. Dapat diterima
karena rollup berjalan untuk **hari yang sudah lewat**, bukan realtime.

**Batasan eksplisit:** `--link` sempit, tanpa itu perintah memproses semua link
yang punya klik pada tanggal tersebut.

### 5.2 Reconciliation = self-healing
`php artisan snipmark:rollup:reconcile [--from=DATE] [--to=DATE] [--fix]`

- Membandingkan `links.total_clicks` dengan `COUNT(*) click_events` per link.
- Membandingkan `SUM(rollups.total)` dengan `COUNT(*) click_events` per link per rentang.
- Melaporkan **drift** sebagai tabel: link, nilai tercatat, nilai sebenarnya, selisih.
- Dengan `--fix`: recompute rollup hari yang melenceng + perbaiki `total_clicks`.
- Exit code 0 = bersih, 1 = ada drift (ditemukan atau diperbaiki).

Ini bukti bahwa penulis memahami bahaya denormalized counter, bukan sekadar memakainya.

### 5.3 Strategi pembacaan hybrid
- **Hari ini** → query langsung `click_events` (data masih segar; rollup belum ada).
- **Kemarin dan sebelumnya** → baca `link_daily_rollups`.
- Rentang 7/30/90 hari = `UNION` kedua sumber, dijumlahkan di aplikasi.

Konsekuensi yang didokumentasikan: angka "hari ini" bisa berbeda tipis dari nilai
rollup setelah hari berjalan (karena rollup adalah snapshot akhir hari). Ini by design.

### 5.4 Penjadwalan
`scheduler` Laravel: `snipmark:rollup:day` untuk `yesterday` dijalankan tiap jam 01:10
timezone aplikasi, diikuti `reconcile --fix` jam 02:00 (jaring pengaman).
Tanpa supervisor/cron eksternal, ini berjalan saat `php artisan schedule:work` aktif;
dokumentasi runbook menyebutkan hal ini eksplisit.

---

## 6. Deteksi Bot & Metadata Request

### 6.1 `App\Support\BotDetector` (kelas murni)
Input: string user-agent (+ sinyal opsional). Output: `BotVerdict { is_bot, name, category }`.
Tanpa DB, tanpa network, tanpa session → 100% unit-testable.

Dua lapisan:
1. **Token list** (~60 tanda, presisi tinggi): `googlebot`, `bingbot`, `duckduckbot`,
   `yandexbot`, `baiduspider`, `curl`, `wget`, `python-requests`, `python-urllib`,
   `go-http-client`, `java/`, `okhttp`, `axios`, `node-fetch`, `headlesschrome`,
   `phantomjs`, `puppeteer`, `semrush`, `ahrefs`, `mj12bot`, `dotbot`, `petalbot`,
   `uptimerobot`, `pingdom`, `statuscake`, `slackbot`, `telegrambot`, `discordbot`,
   `whatsapp`, `facebookexternalhit`, `twitterbot`, `linkedinbot`, `applebot`,
   `bingpreview`, `feedfetcher`, `ahrefssiteaudit`, `dataprovider`, `zgrab`, `masscan`,
   `nmap`, `nikto`, `sqlmap`, `censys`, `shodan`, `expanse`, dsb.
2. **Fallback struktural**: UA kosong, atau < 15 karakter, atau tidak cocok dengan
   satu pun pola browser utama → ditandai `unknown` dan diperlakukan sebagai bot
   **hanya jika** ada sinyal pendukung (rute bukan HTML, atau tidak ada `Accept-Language`).

### 6.2 Klaim yang diizinkan (penting untuk wawancara)
Deteksi berbasis UA **dapat dipalsukan oleh siapa pun yang mau**. Ini **bukan kontrol
keamanan**; ini **pembersih metrik**. Klaim yang ditulis di README:
> "Menyaring sebagian besar lalu lintas otomatis (scanner, crawler, monitor) sehingga
> metrik manusia tidak tercemar. Tidak dimaksudkan sebagai pertahanan terhadap penyerang."

Dilarang menulis "anti-bot" atau "tidak bisa dilewati".

### 6.3 Pertahanan berlapis (diminta user) — **SHADOW MODE dulu**
Tiga mekanisme, semuanya **mencatat keputusan tanpa memblokir** pada v1:

| Mekanisme | Perilaku v1 | Sinyal yang disimpan |
|-----------|-------------|----------------------|
| Rate limit per `visitor_hash` | 60 req/menit; kelebihan → **tetap dilayani 302**, dihitung ulang sebagai bot | `is_bot=1`, `bot_category='unknown'`, `bot_name='rate_limited'` |
| Heuristik CIDR datacenter | Dicatat, **tidak diblokir** | `is_bot=1`, `bot_category='scraper'`, `bot_name='datacenter_cidr'` |
| UA token list | Klasifikasi kategori; tidak mengubah respons | `bot_name`/`bot_category` milik token |

**Kejujuran penamaan (F2).** Yang kita bangun adalah **daftar CIDR datacenter**,
bukan lookup **ASN** sesungguhnya. Pemetaan IP→ASN butuh database komersial/berlisensi
(MaxMind GeoLite2 ASN, yang butuh akun + kunci) dan tidak tersedia di lingkungan ini.
CIDR yang dipublikasikan AWS/GCP/Azure/DigitalOcean/Hetzner/OVH adalah **proxy** yang
cukup akurat untuk memisahkan trafik datacenter dari trafik rumahan, dan sumbernya
tercantum di `config/datacenter_cidrs.php`. Klaim yang boleh ditulis: "heuristik CIDR
pusat data". Yang **tidak** boleh ditulis: "deteksi ASN".

Ketiga sinyal di atas **tidak menambah kolom apa pun** ke `click_events` —
semuanya bermuara ke kolom klasifikasi yang sudah ada di §4.2 (`is_bot`,
`bot_name`, `bot_category`). Tidak ada kolom bayangan.

`bot_name` diberi nilai **satu** alasan dengan prioritas: `rate_limited` >
token UA yang cocok > `datacenter_cidr` > `unknown`. Alasan pertama yang menang,
sehingga satu sumber kebenaran untuk "kenapa ini dianggap bot". Lihat ADR-0007.

**Alasan shadow mode (risiko nyata):** memblokir berdasarkan ASN berarti memblokir
seluruh jaringan. Termasuk penguji sendiri (curl lokal via proxy), kantor dengan NAT
satu IP, dan pengguna VPN/cloud PC yang sah. Pada aplikasi demo dengan trafik hampir
seluruhnya dari satu orang, enforcement dapat mengosongkan analytics dan menyamar
sebagai kerusakan rollup.

**Kriteria promosi ke enforcement** (dicatat di runbook): setelah ≥ 1000 event
terkumpul, tinjau daftar `bot_name`/ASN yang tertandai; jika rasio false-positive
< 1% menurut sampel manual, aktifkan enforcement melalui env `SNIPMARK_BOT_ENFORCE=true`.
Default tetap `false`.

**Daftar CIDR datacenter disimpan sebagai file data** (`config/datacenter_cidrs.php`),
bukan hardcode di kelas, agar bisa diperbarui tanpa mengubah logika.

### 6.4 `App\Support\UserAgentParser` (kelas murni)
Output bucket kasar:
- device: `mobile | tablet | desktop | other`
- browser: `chrome | safari | firefox | edge | opera | samsung | other`
- os: `windows | macos | linux | android | ios | other`

**Tidak ada parsing versi.** Versi presisi adalah jebakan klasik; bahkan pustaka
komunitas yang dirawat pun sering salah. Kebutuhan produk adalah bucket, bukan nomor versi.
`ponytail:` batas ini = tidak bisa membedakan "Chrome 120" vs "Chrome 121".
Upgrade path = pustaka `which-browser`; **tidak dipakai di v1**.

### 6.5 `App\Support\VisitorHasher` (kelas murni)
`hash_hmac('sha256', $ip.'|'.$date, config('app.key'))`.
Rotasi harian → identitas pengunjung tidak bisa dilacak lintas hari, dan IP mentah
tidak pernah disimpan. Ini keputusan privasi, dicatat di ADR-0002.

---

## 7. Redirect Path

```
GET /c/{code}
  1. Rate limiter per visitor_hash (60/menit)
  2. Cache::get("snip:url:{code}")            → hit: 0 query DB
     miss: SELECT by unique index, Cache::put(ttl 1 jam)
  3. Link tidak ada / tidak aktif / kedaluwarsa → 404 (halaman kustom) atau 410
  4. DB::table('links')->where('id',?)->increment('total_clicks')   -- atomic
  5. InsertClickEvent (SYNC — satu insert murah, lihat §8)
  6. return redirect()->away($destination, 302)
```

- **302, bukan 301.** 301 akan di-cache browser secara permanen, sehingga rotasi
  destination dan pembersihan bot menjadi mustahil. Konsekuensi: sedikit lebih banyak
  request. Diterima secara sadar, dicatat di ADR.
- `increment()` menghasilkan `UPDATE ... SET total_clicks = total_clicks + 1`, aman
  terhadap konkurensi. Dilarang `read-then-write`.
- Invalidation cache: `Cache::forget` pada update/delete link. Karena tidak ada Redis,
  invalidation hanya berlaku untuk node ini → **batasan multi-node**, dicatat di runbook.

**Rute:** `/c/{code}` (bukan `/{code}`). Alasannya: `/` bebas untuk landing page, dan
tidak ada slug rute yang bisa bertabrakan dengan kode link. Ini menghilangkan seluruh
kelas bug reserved-word (mis. kode `login` menutup halaman login).

---

## 8. Queue vs Sync (dengan bukti, bukan tren)

Keputusan: **click logging berjalan sinkron**; **rollup berjalan di queue**.

Alasan: pada satu node tanpa Redis (`QUEUE_CONNECTION=database`), satu klik lewat queue
berarti **dua** insert (tabel `jobs` lalu `click_events`) plus polling worker, sementara
sinkron berarti satu insert. Untuk pekerjaan berdurasi milidetik, queue menambah biaya
tanpa manfaat.

Bukti yang harus ada: `docs/benchmarks.md` berisi tabel hasil
`php artisan snipmark:bench:click-logging --iterations=1000` untuk kedua mode,
dijalankan di environment ini, dengan angka nyata. Keputusan ditinjau ulang jika
worker terpisah atau Redis tersedia (upgrade path dicatat).

Queue tetap dipakai untuk rollup: pekerjaan lebih berat, dapat dicoba ulang, dan
kegagalannya tidak boleh memperlambat redirect.

---

## 9. Antarmuka (Anti-Slop Design Contract)

**Token desain** (Tailwind 4 `@theme`, dark-first):
- Latar: `#0B0F14` (bukan `#000`), permukaan `#131A22`, garis `#1F2937`
- Teks: utama `#E6EDF3`, sekunder `#93A1B0`
- Aksen: teal `#2DD4BF`; peringatan amber `#F59E0B`; bahaya `#F87171`
- Skala spasi 4px; radius 8px (kartu) / 6px (kontrol)
- Tipografi: satu keluarga sans (system stack) + satu monospace untuk kode link
- **Dilarang:** `#000` pekat, tombol ikon emoji tanpa label, grid kartu seragam
  hasil salin-tempel, teks placeholder lorem/"Hello World", spasi di luar skala.

**Halaman & state wajib:**

| Halaman | empty | loading | error | success | edge case |
|---------|:-----:|:-------:|:-----:|:-------:|-----------|
| Landing | n/a | n/a | n/a | n/a | akun demo ditampilkan |
| Dashboard (daftar link) | "Belum ada link" + CTA | skeleton baris | toast gagal muat | toast tersimpan | 10.000 baris → paginasi 25 |
| Buat/Edit link (Livewire modal) | n/a | tombol disabled | pesan validasi inline | toast sukses | URL > 2048, non-http |
| Detail analytics | "Belum ada klik" | skeleton chart | toast gagal muat | n/a | rentang tanpa data; bot toggle |
| Auth (Fortify views) | n/a | tombol disabled | pesan error | redirect | rate limit login |
| 404 / 410 / 500 | — | — | halaman kustom | — | 410 untuk link kedaluwarsa |

**Chart:** SVG buatan sendiri (polyline + area fill + gridline + label sumbu).
Tanpa pustaka chart — nol dependency, dan ini menunjukkan kemampuan menyajikan data.
Tooltip interaktif = upgrade path, bukan v1.

**Aksesibilitas:** target WCAG 2.2 AA; kontras ≥ 4.5:1; fokus terlihat; navigasi
keyboard; `prefers-reduced-motion` dihormati; satu lintasan manual screen reader.
`axe-core` di CI. Semua kontrol ≥ 44px di mobile. Tanpa scroll horizontal.

**i18n:** antarmuka berbahasa Indonesia, semua string lewat `__()` sehingga siap
dilokalkan. Tidak ada string user-facing yang di-hardcode di Blade.

**Identitas & SEO:** favicon + `apple-touch-icon` + manifest; OG tags + `og:image`;
`robots.txt`; `sitemap.xml`; canonical URL.

**Tema:** hanya gelap di v1. Keputusan eksplisit (bukan kelalaian); toggle terang
adalah upgrade path.

---

## 10. Keamanan

| Item | Keputusan |
|------|-----------|
| Ownership | Ditegakkan **hanya** di `LinkPolicy`. `user_id` **tidak pernah** diambil dari request |
| Validasi | Setiap trust boundary: form, import, parameter rute, query string non-skalar |
| SSRF | Destination divalidasi `http`/`https`; skema lain ditolak. Aplikasi **tidak** melakukan fetch ke destination |
| Open redirect | `/c/{code}` hanya redirect ke URL yang tersimpan, tidak pernah ke parameter user |
| Kolom `destination` | Ditolak `javascript:`, `data:`, `file:`, dan URL tanpa host |
| CSP | Nonce per-request + `Vite::useCspNonce()`; `script-src 'self' 'nonce-...'`; `style-src 'unsafe-inline'` (konsesi terbatas, didokumentasikan) |
| Header | HSTS (hanya saat request HTTPS), `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin` |
| Rate limit | Redirect 60/menit per `visitor_hash`; login dikunci setelah 5 percobaan gagal |
| Secret | `secret_gate.py` (mode staged blob) + `--history` sebelum publikasi; hanya `.env.example` yang ter-track |
| Rotasi | `APP_KEY` asli dan acak; tidak pernah disalin ke berkas backup |

**Kueri string non-skalar:** seluruh scope filter menerima `mixed`; nilai non-skalar
diperlakukan sebagai "tanpa filter". Ini menutup kelas bug #22 (`?q[]=x` → 500 +
932 KB stack trace).

---

## 11. Testing

- **Framework:** PHPUnit 12.5 (bukan Pest; lihat §3).
- **Database test:** MariaDB `snipmark_test`, `RefreshDatabase`. Bukan SQLite.
- **Anotasi:** PHPUnit 12 mengabaikan `@dataProvider`; gunakan atribut
  `#[DataProvider('method')]` (koreksi dari run sebelumnya).
- **Kelas murni diuji tanpa DB:** `BotDetector`, `UserAgentParser`, `VisitorHasher`,
  agregator rollup.
- **Setiap test baru harus dibuktikan bisa gagal** (revert perbaikan → spec merah).
  Test yang tetap hijau setelah perbaikan dilepas lebih buruk daripada tidak ada test.
- **Smoke E2E dijalankan begitu halaman pertama render**, bukan ditunda.
- **Selector E2E:** berdasarkan peran + nama aksesibel, bukan atribut presentasional.
- **Target cakupan:** setiap kriteria S1–S13 punya test; jalur gagal diuji
  (akses ditolak, validasi gagal, data kosong).

---

## 12. Yang TIDAK Dibangun (YAGNI — dinyatakan, bukan terlupa)

API publik · multi-tenant/workspace · A/B redirect · custom domain · password-protected
link · impor Google Analytics · Geo-IP (butuh lisensi MaxMind) · rollup per jam ·
ekspor CSV/PDF · toggle tema terang · tooltip chart interaktif · endpoint health-check
publik · SSO/OAuth · 2FA.

Masing-masing punya upgrade path yang dicatat di runbook.

---

## 13. Risiko Teridentifikasi

| # | Risiko | Mitigasi |
|---|--------|----------|
| R1 | CSP produksi memblokir script inline Livewire (kelas bug #19) | Nonce per-request; rehearsal produksi di browser nyata, HTTP + HTTPS; console harus bersih |
| R2 | `phpunit.xml` default SQLite, `pdo_sqlite` tidak ada (kelas bug #2) | Ganti ke MariaDB di commit pertama; assertion env di test |
| R3 | `breeze:install` menimpa rute & menurunkan Tailwind (bug #3) | Fortify; `breeze:install` dilarang |
| R4 | Blokir ASN menimbulkan false positive massal | Shadow mode; promosi berdasarkan data ≥ 1000 event dan tinjauan manual |
| R5 | Rollup hari sibuk lambat | Full recompute hanya untuk hari lampau; `--link` untuk memperkecil; benchmark terdokumentasi |
| R6 | Angka "hari ini" tidak cocok dengan rollup | Hybrid read didokumentasikan sebagai perilaku by design |
| R7 | Tanpa Redis, invalidation cache tidak lintas node | Dinyatakan sebagai batasan single-node di runbook |
| R8 | `visitor_hash` rotasi harian mengurangi akurasi unique lintas hari | Diterima; unique dihitung per hari (definisi yang dipakai dashboard) |

---

## 14. Urutan Kerja (5 sesi)

| Sesi | Isi | Keluaran terverifikasi |
|------|-----|------------------------|
| 1 | Factory T1, skema + migrasi, `BotDetector`, `UserAgentParser`, `VisitorHasher` | Unit test hijau; secret gate terbukti memblokir |
| 2 | Mesin rollup: `rollup:day`, `reconcile`, agregator | S3–S5 hijau, termasuk idempotency & deteksi drift |
| 3 | Redirect path, cache, penyisipan klik, rate limit, shadow-mode bot | S1, S2, S9 hijau |
| 4 | Dashboard + halaman analytics Livewire + SVG chart + seluruh state | S7, S8 hijau + smoke E2E |
| 5 | Docs (End-User Guide + Runbook) + E2E penuh + UAT + rehearsal produksi + benchmark | S10–S13 hijau; tag rilis |

---

## 15. ADR yang Menyertai

- **ADR-0001** — Pemilihan Livewire 4 + Blade (bukan Inertia/React)
- **ADR-0002** — Penyimpanan `visitor_hash` berotasi harian, tanpa IP mentah
- **ADR-0003** — Rollup full-recompute (bukan delta), dengan reconciliation
- **ADR-0004** — Click logging sinkron, rollup melalui queue
- **ADR-0005** — Rute `/c/{code}`, bukan `/{code}`
- **ADR-0006** — PHPUnit, bukan Pest (konflik `laravel/pao`)
- **ADR-0007** — Pertahanan bot shadow mode sebelum enforcement
