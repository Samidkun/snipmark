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
| 6 — TDD | ✅ | 229 test / 627 assertions, 29/29 mutasi |
| 7 — Execute | ✅ | Sesi 1–3: analytics + rollup + redirect |
| 8–16 | ⬜ | UI (Sesi 4), docs+E2E (Sesi 5) |

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

## Yang belum dikerjakan (jujur)

1. `UserAgentParser` dan `VisitorHasher` — sisa Sesi 1
2. Migrasi 3 tabel (`links`, `click_events`, `link_daily_rollups`)
3. Sesi 2–5: rollup engine, redirect path, UI, docs + E2E + rehearsal
4. Manual steps factory: belum ada git remote (CI diam) → `scripts/local-ci.sh` dipakai
5. ~~`local-ci.sh` belum dijalankan~~ → **sudah: ALL GREEN (--fast)**
6. `local-ci.sh` **full** (dengan e2e/a11y/perf) belum pernah dijalankan
7. E2E belum ada satupun test yang berjalan (baru scaffold)
