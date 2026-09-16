# SOP Progress — Snipmark

**Tier:** T1 · **Stack:** Laravel 13.32 · Livewire 4.4 · Fortify 1.39 · MariaDB 12.3 · PHPUnit 12.5
**Lokasi:** `/mnt/data/01_Projects/Porto/snipmark`
**Spec:** `docs/superpowers/specs/2026-09-16-snipmark-design.md`

| Stage | Status | Catatan |
|---|---|---|
| 0 — Prompt Roast | ✅ | Request digeser: dari "CRUD + chart" → "analytics engine" |
| 1 — Brainstorm | ✅ | Desain disetujui; 2 koreksi user (bot diperkuat, UA mentah disimpan) |
| 1.5 — Factory Bootstrap | ✅ | 19 file. Secret gate **terbukti memblokir** (staged + staged-blob) |
| 2 — Anti-Slop Contract | ✅ | Di spec §9 (token, state, a11y, i18n) |
| 2.5 — UI + Feature Checklist | ✅ | Di spec §9 + §10 |
| 3 — Spec | ✅ | 473 baris, self-review menemukan 5 masalah, diperbaiki |
| 4 — Plan | ⏭️ | **Di-skip dengan persetujuan user** — spec §14 = rencana |
| 5 — Workspace | ✅ | `main`, solo + T1 → tanpa worktree (ruling R4 run sebelumnya) |
| 6 — TDD | ✅ | 349 test / 938 assertions, mutasi terdeteksi |
| 7 — Execute | ✅ | Sesi 1–4: analytics + rollup + redirect + UI |
| 8 — E2E | ✅ | 12 test Playwright hijau (login, CRUD, analytics, CSP, a11y) |
| 9 — Rehearsal Produksi | ✅ | 20 gerbang lulus, APP_DEBUG=false + cache produksi, 3× berturut deterministik |
| 10 — UAT | ✅ | Diverifikasi lewat E2E + curl (lihat §UAT) |
| 11–16 | ⬜ | Sisa: user-guide final pass, tag rilis |

---

## UAT (User Acceptance Test) — hasil

Diuji terhadap **konfigurasi produksi** (`APP_DEBUG=false`, cache aktif),
bukan hanya di dev. Setiap baris punya bukti, bukan klaim.

| # | Skenario | Bukti | Hasil |
|---|---|---|---|
| S1 | Redirect `/c/{code}` → 302 + `total_clicks` +1 | PHPUnit `RedirectTest` + E2E | ✅ |
| S7 | Dashboard menampilkan total, unik, sparkline 7 hari | E2E `smoke.spec.ts` | ✅ |
| S8 | Analytics: breakdown device/browser/os/referrer + toggle bot | `AnalitikTautanTest` + kode terverifikasi | ✅ |
| S10 | Panduan pengguna ada dan akurat | `docs/user-guide/README.md`; fitur dicek ada di kode | ✅ |
| S11 | Runbook ada, mencakup batasan | `docs/runbook/README.md`; 10 file + 4 perintah diverifikasi ada | ✅ |
| S12 | Suite E2E hijau terhadap build produksi | `scripts/rehearsal-produksi.sh` → 9/9 gerbang | ✅ |
| S13 | Benchmark nyata terdokumentasi | `docs/benchmarks.md`: 1009 / 96 / 52260 klik/detik | ✅ |

### Yang TIDAK diuji lewat browser nyata oleh saya

Jujur: pengujian browser dilakukan lewat **Playwright** (headless Chromium), bukan
dengan mata manusia di browser ber-jendela. Playwright menangkap CSP, error
konsol, dan aksesibilitas otomatis — tetapi **tidak** bisa menilai apakah tata
letaknya enak dilihat. Itu memerlukan satu kali pemeriksaan manual.

Juga belum diuji E2E: halaman **analytics** (`/dashboard/{code}`) — baru dashboard
index. Komponennya punya test PHPUnit, tetapi belum pernah dirender di browser
nyata. Ini celah yang diketahui, bukan yang terlewat.

---

## Sesi 1 — Selesai sejauh ini

| Deliverable | Status | Bukti |
|---|---|---|
| Skeleton Laravel 13.32 + Livewire 4.4 + Fortify 1.39 | ✅ | `artisan about` exit 0 di PHP 8.5 |
| `phpunit.xml` → MariaDB (bukan sqlite `:memory:`) | ✅ | 99 test berjalan, bukan error koneksi |
| `.gitignore` menutup SEMUA varian `.env` | ✅ | `git ls-files` → hanya `.env.example` |
| Secret gate terbukti memblokir | ✅ | 2 skenario (staged + staged-blob) DITOLAK |
| `App\Support\BotVerdict` | ✅ | 99 test |
| `App\Support\BotDetector` | ✅ | 38 UA bot + 10 UA manusia |
| `App\Support\ParsedUserAgent` | ✅ | bucket + enum sinkron |
| `App\Support\UserAgentParser` | ✅ | 13 UA nyata + 5 jebakan urutan |
| `App\Support\VisitorHasher` | ✅ | HMAC, rotasi harian, bukan digest polos |
| Migrasi 3 tabel + model | ✅ | `SchemaTest` 10 test |
| `Link` (kode race-safe + isReachable) | ✅ | `LinkCodeGenerationTest` 6 + `LinkBehaviorTest` 14 |
| `ClickEvent` (append-only, UA truncate) | ✅ | mutator + test |
| `LinkDailyRollup` | ✅ | UNIQUE(link,date) teruji |

**Test:** 309 passed / 817 assertions
**Mutation check:** 43/43 terdeteksi (19 + 12 analytics + 12 redirect)
**Gate lokal:** `local-ci.sh --fast` → **ALL GREEN**

---

## Bug yang ditemukan & diperbaiki (sejauh ini)

### B1 — `Fortify::Features` ditulis sebagai `::class`, padahal method
**Dampak:** fatal parse error — config/fortify.php tidak bisa dimuat.
**Penyebab:** saya menulis `Features::Registration::class`. API Fortify memakai
**method** (`Features::registration()`). Class constant `Registration` tidak ada.
**Fix:** `Features::registration()`, `resetPasswords()`, `updateProfileInformation()`,
`updatePasswords()`.
**Pelajaran:** baca API package sebelum mengedit config-nya, jangan menebak.

### B2 — `emailVerification()` diaktifkan padahal `MAIL_MAILER=log`
**Dampak:** setiap user baru **terkunci permanen** di halaman verifikasi — tidak ada
email yang benar-benar terkirim, jadi tidak ada tautan verifikasi.
**Fix:** fitur dimatikan, alasan ditulis di config sebagai komentar.
**Pelajaran:** mengaktifkan fitur auth yang bergantung email di lingkungan tanpa SMTP
bukan "lengkap", itu "rusak".

### B3 — Mutasi bash meng-expand `$ua` jadi string kosong
**Dampak:** mutation check pertama melaporkan 1 mutasi "tetap hijau" — hampir
disimpulkan test-nya lulus-palsu. **Padahal mutasinya tidak pernah diterapkan**:
`sed -i 's|if ($ua === ...|'` dengan kutip ganda mengganti `$ua` jadi kosong.
**Fix:** mutation runner ditulis sebagai skrip Python yang **memverifikasi berkas
benar-benar berubah** (`assert changed`) sebelum menjalankan test.
**Pelajaran:** "test tetap hijau" hanya bermakna jika mutasinya terbukti diterapkan.
Sama kelasnya dengan gate yang memeriksa objek yang salah.

### B7 — ⚠️ REPORTER BAWAAN LARAVEL 13 MELAPORKAN "passed" UNTUK SUITE YANG EXIT CODE-NYA 1
**Dampak:** kalau hanya membaca output `php artisan test`, seluruh suite bisa
dilaporkan hijau sementara gate-nya merah. **Ini kelas kegagalan yang didefinisikan
SOP sendiri: gate yang melaporkan sukses tanpa benar-benar lolos.** Persis bug #24
dari run reference-tracker, tapi dengan mekanisme berbeda.

**Reproduksi (terverifikasi, `scripts/prove-reporter-can-lie.py`):**
```
exit code proses : 1
JSON mengklaim   : passed
PAO_DISABLE=1    : Tests: 1 warning, 147 passed (393 assertions)
```

**Akar masalah:** `laravel/pao` ("Agent-optimized output for PHP testing tools") adalah
dev-dependency BAWAAN skeleton Laravel 13. Ia mendeteksi agent lewat
`AgentDetector::detect()`, mengganti output PHPUnit dengan JSON, dan JSON-nya hanya
menyimpulkan dari jumlah test — bukan dari exit code. Dipicu oleh PHPUnit **warning**:
method test hanya menerima 1 argumen sementara dataset berisi 4
(`test_keluaran_selalu_nilai_enum_yang_sah`). Karena `failOnWarning="true"` (yang saya
tulis di phpunit.xml), warning itu membuat proses keluar 1.

**Fix:**
1. Signature test menampung seluruh argumen dataset — warning hilang (fix akarnya).
2. `scripts/tests-summary.py` menerima exit code proses dan **menolak mengklaim hijau
   bila exit code bukan 0**, walau JSON bilang "passed". Ada test regresinya.
3. `scripts/run-tests.sh` — jalur standar: exit code diambil dari proses test,
   bukan dari `tail` (rangkaian `... | tail` memberi exit code milik `tail`).

**Koreksi diri:** mutasi pertama saya salah mereproduksi bug (body-nya menyentuh
variabel undefined sehingga test GAGAL beneran — itu bukan B7). Reproduksi yang benar
memerlukan body yang **tidak** menyentuh param ekstra, sehingga test tetap lolos
dan hanya warning yang tersisa.

### B11 — Kelas "murni" memanggil `config()` → tidak bisa diuji tanpa boot Laravel
**Dampak:** 11 test gagal dengan `Target class [config] does not exist`.
**Penyebab:** `DayWindow` (yang diklaim murni) memanggil `config()`. Klaim "murni" itu bohong.
**Fix:** konstanta `DayWindow::DEFAULT_TIMEZONE`; pemanggil aplikasi meneruskan nilai
secara eksplisit. Sekarang benar-benar murni dan bisa diuji tanpa framework.

### B12 — `reconcile()` mengembalikan snapshot SEBELUM perbaikan
**Dampak:** perintah melaporkan "masih ada drift" setelah `--fix` berhasil —
**laporan yang berbohong**, dan exit code 1 padahal sudah bersih.
**Fix:** kontrak eksplisit — `drifts` = yang DITEMUKAN, `fixed` = jumlah ditangani,
dan kebersihan dibuktikan dengan pemanggilan KEDUA. Diuji, bukan dijelaskan.

### B13 — `parent::__construct()` pada Seeder
**Dampak:** `Cannot call constructor` — seeder tidak bisa dijalankan sama sekali.
**Penyebab:** `Illuminate\Database\Seeder` TIDAK punya constructor. Saya menulis
`parent::__construct()` karena mengasumsikan ada (kebiasaan dari kelas lain).
**Fix:** constructor sendiri tanpa `parent::`, dan output lewat callable `$log`
(karena `$this->command` juga null bila seeder dipanggil langsung).

### B14 — `getmtmax()` tidak ada di PHP
**Dampak:** fatal error saat seeding. Nama fungsi yang benar `mt_getrandmax()`.
**Penyebab:** saya menulis nama fungsi dari ingatan, bukan dari dokumentasi.
**Kelas yang sama dengan B1 (API Fortify) dan B13 (constructor Seeder):**
**menebak API adalah sumber bug, bukan mengetahuinya.**

### B15 — `firstOrCreate(['code' => 'bench001'])` → kolom `CHAR(7)` ditolak
**Dampak:** benchmark gagal start. `'bench001'` = 8 karakter.
**Penyebab:** nilai contoh yang ditulis tanpa menghitung panjang terhadap skema.
**Fix:** pakai link yang sudah ada / `createWithUniqueCode()`.

### B16 — Mutasi skrip sendiri bikin SYNTAX ERROR, bukan mutasi logika
**Dampak:** 1 mutasi "terdeteksi" karena PHP crash — itu bukan bukti test punya gigi.
**Fix:** mutasi diganti `continue;` (sintaks sah, logika hilang). Sekarang 10/10
mutasi analytics terdeteksi secara SEMANTIK.

### B8 — Mutation check menemukan 2 mutasi lolos: `isReachable()` TANPA TEST SAMA SEKALI
**Dampak:** logika kelayakan-redirect (aktif + belum kedaluwarsa) tidak dilindungi
test apa pun. Link kedaluwarsa bisa tetap membuka — bug yang baru terlihat setelah
tautan dipakai orang.
**Penyebab:** saya menulis `isReachable()` sebagai "metode kecil yang jelas" dan
melewatinya. Itu pelanggaran IRON LAW: kode produksi tanpa test gagal lebih dulu.
**Fix:** `LinkBehaviorTest` (14 test) termasuk batas kedaluwarsa tepat-sekarang.

### B9 — `user_agent` TIDAK dipotong -> redirect 500 untuk UA panjang
**Dampak:** MariaDB menolak insert (`Data too long for column 'user_agent'`) ->
**setiap kunjungan dengan UA > 512 char gagal redirect dengan HTTP 500.**
**Penyebab:** kolom dibatasi 512, tapi tidak ada yang memotong. Ditemukan oleh test
yang saya tulis setelah mencurigai batas kolom — bukan oleh review.
**Fix:** mutator `Attribute` di `ClickEvent`, satu tempat, bukan diserahkan ke pemanggil.

### B10 — Runner mutasi sendiri melaporkan "GREEN" untuk suite yang MERAH
**Dampak:** 3 mutasi dilaporkan "tidak terdeteksi" padahal suite-nya jelas merah —
hampir menyimpulkan test tidak punya gigi.
**Penyebab:** `laravel/pao` hanya mengeluarkan JSON bila ada TTY. Lewat `subprocess`
(tanpa TTY) output-nya human-readable, sehingga parser JSON gagal dan runner
menyimpulkan "hijau".
**Fix:** `scripts/mutation-check.py` — HANYA memakai exit code. Ini pelajaran
ketiga yang sama di satu sesi: `| tail` (exit code tail), pao berbohong (B7),
dan tanpa-TTY (B10). **Exit code adalah satu-satunya sinyal yang tidak bisa bohong.**

### B5 — Test bot-discard di UserAgentParser tidak menguji apa pun
**Dampak:** mutasi "bot tidak lagi dibuang lebih dulu" tetap HIJAU — artinya test itu
tidak melindungi apa pun.
**Penyebab:** test memakai `curl/8.4.0`, yang memang tidak memuat satu pun tanda
browser. Hasilnya 'other' baik bot-discard ada maupun tidak. Test itu menguji nol.
**Fix:** diganti dengan `HeadlessChrome/120` dan `Googlebot` yang UA-nya MEMUAT
"Chrome/", "Safari/", "Windows", "Mozilla/5.0" — bot yang menyamar sebagai browser.
Tanpa bot-discard, traffic ini tercatat "desktop chrome windows" tanpa satu error pun.
**Bukti:** mutasi yang sama sekarang MERAH.

### B6 — Skrip mutasi sendiri punya bug (mutasi tidak diterapkan)
**Dampak:** 1 mutasi dilaporkan SKIP karena pola tidak ditemukan.
**Penyebab:** `"\$this->botDetector"` di dalam string Python — `\$` bukan escape sah,
sehingga polanya tidak pernah cocok.
**Fix:** raw string (`r"..."`). Skrip sekarang MENOLAK mengklaim hasil bila mutasi
tidak terbukti mengubah berkas.

### B4 — 2 lubang cakupan NYATA ditemukan oleh mutation check
| Mutasi | Hasil | Lubang yang terbuka |
|---|---|---|
| `BOUNDARY_MAX_LEN = 7` → `3` | HIJAU (lubang) | Tidak ada test untuk batas kata token generik |
| `Accept-Language` `''` → tidak dianggap sinyal | HIJAU (lubang) | Hanya `null` yang diuji, `''` tidak pernah |
**Fix:** 4 test ditambahkan (JavaScriptCore bukan bot, token generik tetap cocok,
`''` dianggap sinyal, UA tak dikenal tapi manusiawi bukan bot).
**Bukti:** mutation check ulang → **4/4 MERAH**. Test sekarang punya gigi.

---

## Ruling

- **R1** Tier T1 (user).
- **R2** MariaDB, bukan SQLite — `pdo_sqlite` tidak terpasang (bug #2 run sebelumnya).
- **R3** PHPUnit 12.5, **bukan Pest** — `laravel/pao` bawaan skeleton konflik dengan
  `pestphp/pest` (dry-run exit 2, terverifikasi).
- **R4** Fortify, **bukan Breeze** — `breeze:install` destruktif (bug #3 run sebelumnya).
- **R5** Livewire + Blade (user), dengan catatan risiko CSP script inline = bug #19.
- **R6** 2FA & passkeys **dimatikan** — di luar scope v1 (spec §12); migrasinya dihapus
  agar tidak ada tabel yatim.
- **R7** `emailVerification` **dimatikan** — `MAIL_MAILER=log` (B2).
- **R8** UA mentah **disimpan** (`user_agent VARCHAR(512)`) — keputusan user; membuat
  rollup dapat diparse ulang setelah parser diperbaiki. IP tetap tidak disimpan.
- **R9** Pertahanan bot **shadow mode** — deteksi dicatat, tidak memblokir (spec §6.3).
- **R10** Stage 4 (writing-plans) di-skip dengan persetujuan user — spec §14 = rencana.
- **R11** Sesi 1 di `main`, tanpa worktree (solo + T1).

---

## Sesi 2 — Benchmark NYATA (S13)

```
php artisan snipmark:bench:click-logging --iterations=1000 --rounds=3

sync  (1 INSERT/klik)              median  991,4 ms  →  1.009 klik/detik
queue (database, via worker)       median 10389,5 ms →     96 klik/detik
batch (insertAll)                  median    19,1 ms → 52.260 klik/detik
```

**Hipotesis terbukti, besaran lebih besar dari dugaan:** queue **10,5× LEBIH LAMBAT**
daripada sync pada satu node tanpa Redis (dua INSERT + serialisasi + polling untuk
pekerjaan milidetik). Batch 52× lebih cepat — menunjukkan biaya sebenarnya adalah
perjalanan bolak-balik per pernyataan, bukan "INSERT itu mahal".
Ditulis lengkap di `docs/benchmarks.md`, termasuk batas yang TIDAK diukur.

## Sesi 3 — Jalur redirect

| Deliverable | Bukti |
|---|---|
| `GET /c/{code}` → 302 | diuji; **302 bukan 301** (ADR-0006) |
| Counter atomik | diuji lewat **SQL yang dieksekusi** (`DB::listen`), bukan `DB::increment()` langsung |
| Cache + invalidation | hit = nol query DB; `saved/deleted/restored` membersihkan |
| `DestinationValidator` | javascript:/data:/file:, loopback, RFC1918, metadata cloud, IPv6 ULA |
| `CidrMatcher` | IPv4+IPv6, IP tidak sah SELALU non-cocok |
| Shadow-mode bot | rate limit + CIDR + token UA; dicatat, **tidak** memblokir |
| 404 / 410 | kode tak dikenal vs link kedaluwarsa |

**Verifikasi end-to-end di server nyata** (`php artisan serve`, bukan hanya test):
```
klik browser  -> 302 ke destination, referrer google.com, desktop/chrome, hash 64 char
klik curl     -> 302, ditandai is_bot=1 bot_name=curl
kode ngawur   -> 404
rollup        -> 12 baris, by_browser {"safari":34,...}, reconcile -> BERSIH
```

### B22 — `reconcile --fix` tidak pernah bisa bersih (self-healing palsu)
**Dampak:** perintah melaporkan "MASIH ada drift setelah --fix" selamanya.
**Penyebab:** `rollupDay()` adalah fungsi dari `click_events`, tetapi baris rollup
untuk link yang event-nya sudah hilang tidak pernah dihapus. `reconcile`
membandingkan rollup vs event nyata → selisihnya dilaporkan sebagai drift yang sama,
terus-menerus.
**Perbaikan pertama saya SALAH** (hanya menangani "hari kosong total"). Pada data
nyata tanggal itu masih punya event untuk link LAIN, jadi jalur purge tidak pernah
jalan. Perbaikan benar: purge di SETIAP `rollupDay` dengan `whereNotIn` link yang
punya event.
**Ditemukan lewat:** pemakaian nyata (seeder dijalankan ulang dengan rentang berbeda)
— **bukan** oleh test, walaupun saat itu ada 229 test hijau.
**Bukti:** sebelum `fix exit=1` → "MASIH ada drift"; sesudah `fix exit=0` →
verifikasi ulang `exit=0` → "Bersih — tidak ada drift."
**Pelajaran:** command yang JUJUR melaporkan kegagalannya adalah yang membuat bug ini
ketemu. Kalau `reconcile` menelan drift dan keluar 0, data rusak akan terlihat sehat.

### B23 — Output test meledak 257 KB
**Dampak:** satu kegagalan membawa stack trace 60+ frame; output 257 KB membanjiri
konteks sampai kerja terhenti.
**Fix:** `tests-summary.py` membatasi keluaran keras < 3800 karakter.

### B24 — Saya mengulang kesalahan `| tail` (ketiga kalinya)
**Dampak:** `reconcile --last-7-days | tail` melaporkan `exit=0` padahal drift ada.
**Bukti:** dijalankan tanpa pipe → `exit code SEBENARNYA = 1`.
**Pelajaran:** sudah tiga kali di sesi ini (`| tail`, pao, subprocess tanpa TTY).
Aturannya satu: **exit code dari proses itu sendiri, tidak pernah dari pipe.**

### B25 — CSP nonce berbeda antara header dan HTML (bug #19, terulang)
**Dampak:** halaman tetap HTTP 200, HTML tampak benar, TANPA error di layar — tetapi
browser memblokir setiap `<script>` inline. Livewire tidak pernah boot: **semua tombol
mati**, form tidak submit, filter tidak jalan. Ini kegagalan paling menipu yang ada,
karena tidak ada satu sinyal pun di UI yang menunjuk ke sana.
**Penyebab:** nonce dibuat di `AuthViewServiceProvider::boot()`. `boot()` berjalan
**SEKALI per aplikasi**, bukan per request — jadi nilai nonce dibuat pada waktu yang
berbeda dari saat HTML dirender. Header CSP membaca nilai terakhir, HTML memakai nilai
lain.
**Gejala yang menyesatkan:** membandingkan `curl -sI` (HEAD) dengan `curl -s` (GET)
memberi dua nonce berbeda — dan itu **sah**, karena dua request berbeda. Kesimpulan
"nonce beda" dari perbandingan itu **palsu**; alat ukurnya yang salah, bukan kodenya.
**Fix:** nonce dibuat di `SecurityHeaders` — sekali per request, disimpan ke container +
`Vite::useCspNonce()` **sebelum** `$next()`, sehingga view dan header membaca nilai yang
sama. Pembuatan nonce dihapus dari provider (tempat salah).
**Bukti terverifikasi di halaman Livewire nyata** (`/dashboard`, satu request):
```
nonce header CSP   : N0HwFg8ZXLFtGQJyIelrniw9
script Vite        : nonce="N0HwFg8ZXLFtGQJyIelrniw9"  ✅
script Livewire    : nonce="N0HwFg8ZXLFtGQJyIelrniw9"  ✅
2 script ber-nonce · 0 tanpa nonce
```
**Dibuktikan bisa gagal:** menyuntikkan kembali bug → 2 test MERAH dengan pesan yang
menunjuk bug #19 eksplisit. Test `SecurityHeadersTest` membandingkan nonce header vs
SETIAP tag `<script>` pada response yang **sama** (6 test, 21 assertions).
**Pelajaran:** (1) nonce CSP itu per-request, bukan per-aplikasi — provider adalah
tempat yang salah. (2) Alat ukur harus diuji sebelum kesimpulannya dipercaya; dua
request berbeda memang menghasilkan nonce berbeda.

### B26 — Layout membuang isi komponen Livewire (halaman kosong tanpa error)
**Dampak:** `/dashboard` mengembalikan HTTP 200, layout lengkap (sidebar, judul),
tetapi **isi komponen hilang seluruhnya**. Tidak ada error, tidak ada peringatan.
**Penyebab:** Livewire membungkus komponen full-page dengan
`@section($slotOrSection)`, dan **nilai default `slotOrSection` adalah `'slot'`** —
bukan `'content'`. Layout hanya menyediakan `@yield('content')`, jadi hasil render
komponen dibuang.
**Kenapa 349 test PHPUnit tidak melihatnya:** test Livewire memakai
`Livewire::test(Komponen::class)`, yang memanggil komponen secara langsung dan
**tidak pernah menyentuh layout**. Seluruh test hijau sementara halaman produksi
kosong.
**Fix:** layout melayani ketiga jalur — `@yield('content')` (view auth),
`$slot` (komponen Blade), dan `@yield('slot')` (Livewire full-page).
**Ditemukan lewat:** E2E di browser nyata. Ini alasan E2E ada.

### B27 — CSP memblokir evaluator Livewire (semua tombol mati)
**Dampak:** `window.Livewire` ada, tetapi `Livewire.all()` berisi **0 komponen**.
Setiap klik tidak menghasilkan request. Tidak ada error di UI.
**Gejala di konsol:** `EvalError: Evaluating a string as JavaScript violates CSP
because 'unsafe-eval' is not an allowed source` — di `normalRawEvaluator`
(livewire.js:1606), dipanggil dari `x-on:click`.
**Penyebab:** bundle Livewire standar menerjemahkan ekspresi Alpine dengan
`new Function()` (= eval). CSP produksi aplikasi ini tidak mengizinkan
`'unsafe-eval'` — dan memang tidak boleh, itu membuka XSS.
**Fix:** `csp_safe => true` di `config/livewire.php` → Livewire menyajikan
`livewire.csp.js` (evaluator berbasis parser). **BUKAN** menambahkan
`'unsafe-eval'`.
**Bukti:** bundle yang disajikan 722.303 byte = `livewire.csp.js`, 0 kemunculan
`new Function()`. `Livewire.all()` = 1 (sebelumnya 0).

### B28 — Tombol aksi dibuang karena `@yield` tidak bisa diisi komponen
**Dampak:** tombol "+ Buat" tidak pernah muncul. Pengguna **tidak punya cara
membuat tautan sama sekali** — aplikasinya tidak berguna.
**Penyebab:** judul dan tombol didefinisikan lewat `@section('actions')` di
layout, padahal `@yield` hanya menerima isi dari `@section` milik view yang
`@extends`. Komponen Livewire tidak bisa mengisi `@yield`. Yang tampil hanya
**nilai default** `@yield('heading', 'Tautan')` — judul yang tidak pernah diminta
komponennya.
**Fix:** header dan tombol dipindah KE DALAM komponen. Tombol dibuat SELALU ada,
bukan hanya di empty-state (sebelumnya pengguna yang sudah punya tautan tidak
punya jalan menambah lagi).

### B29 — Dua gerbang palsu di E2E (dua-duanya mengukur alat, bukan aplikasi)
**Gerbang palsu #1:** membandingkan nonce lewat `curl -sI` (HEAD) dengan
`curl -s` (GET). Dua request berbeda **memang** menghasilkan nonce berbeda —
perbandingan itu selalu "beda" dan menyimpulkan bug yang tidak ada. Harus SATU
response yang sama (`curl -D -`).
**Gerbang palsu #2:** memeriksa CSP lewat `new Function()` di dalam
`page.evaluate()`. **Terukur:** `new Function('return 1')()` mengembalikan 1
bahkan saat CSP aktif, karena `page.evaluate` berjalan di konteks yang
di-inject DevTools dan tidak tunduk CSP halaman. Test itu **selalu hijau** —
tidak mengukur apa pun. Diganti: cek script inline TANPA nonce benar-benar
ditolak browser (itu bukti CSP ditegakkan), dan uji AKIBATNYA (aksi harus sampai
ke server).
**Gerbang palsu #3:** skrip cek bundle mencetak "✅ CSP-SAFE" padahal body JS
**0 byte** (fetch gagal) — `grep -c` pada string kosong mengembalikan 0, identik
dengan "tidak ada evaluasi string". Ketiadaan bukti diperlakukan sebagai bukti
ketiadaan. Fix: tolak hijau bila body < 1000 byte.
**Pelajaran:** sebelum mempercayai sebuah gate, buktikan gate itu **bisa gagal**.
Ini kelas bug yang sama dengan B7 dan B24.

### B30 — Test paralel menabrak throttle login Fortify (HTTP 429)
**Dampak:** 7 test paralel, masing-masing login sendiri → percobaan ke-6 dan
seterusnya menerima 429. Kegagalannya menyesatkan: test gagal di
`waitForURL(/dashboard/)` seolah-olah login rusak, padahal aplikasi benar.
**Diukur:** `POST /login` berturut-turut → 1..5 = 302, 6..8 = 429.
**Fix:** login SEKALI di `globalSetup` (berjalan sebelum worker mana pun),
simpan state sesi, setiap test memuatnya. Login di dalam fixture **tidak bisa**
dipakai — beberapa worker memeriksa "file sesi belum ada" bersamaan, semuanya
login, dan throttle tetap kena.
**Pelajaran:** jalur auth tetap harus diuji sungguhan; yang salah adalah
melakukannya berkali-kali di lingkungan ber-throttle.

### B31 — Gate rehearsal non-deterministik: SIGPIPE + `pipefail`
**Dampak:** Gerbang `dashboard: komponen ter-render` memberi hasil **BERBEDA
pada kode yang sama** — kadang hijau, kadang merah. Inilah sebab rehearsal
"LULUS" di run pertama lalu "GAGAL" di run berikutnya tanpa ada perubahan kode.
**Diukur:** pola `printf '%s' "$DASH" | grep -q 'wire:snapshot'` di bawah
`set -o pipefail` → **14/30 gagal palsu** pada HTML asli (55.837 byte), dan
**30/30 gagal** pada data 300 KB. `PIPESTATUS = 141 0` → 141 = 128+13 = SIGPIPE.
**Akar masalah:** `grep -q` keluar **begitu** menemukan match. `printf` masih
menulis sisa data → pipe tutup → SIGPIPE → tahap pertama exit 141 → `pipefail`
membuat pipeline dianggap gagal **walau grep sukses**. Jadi hasilnya balapan:
tergantung apakah `printf` selesai sebelum buffer pipe (64 KB) penuh. Dashboard
55 KB ada tepat di ambang → nondeterministik. `Buat tautan` (di akhir HTML)
selalu lolos, `wire:snapshot` (di awal) sering gagal — itu yang membingungkan.
**Fix:** simpan body ke file (`mktemp`), lalu `grep` pada file. grep file tidak
kena SIGPIPE dan exit code-nya jujur. Diterapkan ke 6 pola (snapshot, tombol,
URL bundle, nonce H/TAGS/OK, token CSRF, deteksi halaman login).
**Verifikasi:** 30/30 deterministik; rehearsal **3× berturut = LULUS 20✅/0❌**;
dan gerbang terbukti **bisa gagal** (merah pada HTML kosong, halaman login, dan
dashboard tanpa tombol).
**Pelajaran:** gate yang kadang berbohong **lebih buruk daripada tidak ada gate**
— orang belajar mengabaikannya. Sebelum mempercayai gate, uji dua hal:
(1) deterministik? (2) bisa gagal? `grep -q` di dalam pipeline ber-`pipefail`
adalah jebakan klasik; `grep -q` pada file aman.

## Yang belum dikerjakan (jujur)

1. ~~`UserAgentParser` dan `VisitorHasher`~~ → **selesai** (Sesi 1)
2. ~~Migrasi 3 tabel~~ → **selesai** (Sesi 2)
3. ~~Sesi 2–4: rollup engine, redirect path, UI~~ → **selesai**
4. ~~E2E~~ → **selesai**: 12 test hijau (8 smoke + 4 analytics), terbukti bisa gagal
5. ~~Sesi 5~~ → **selesai**: 8 ADR, runbook, user-guide, UAT, rehearsal produksi
6. Manual steps factory: belum ada git remote (CI GitHub diam) → `scripts/local-ci.sh`
7. `local-ci.sh` **full** (e2e/a11y/perf) belum pernah dijalankan sekaligus
8. ~~Halaman analytics belum diuji E2E~~ → **selesai** (`e2e/analytics.spec.ts`, 4 test)
