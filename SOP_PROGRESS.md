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
| 6 — TDD | 🔄 **sedang jalan** | Sesi 1 |
| 7–16 | ⬜ | |

---

## Sesi 1 — Selesai sejauh ini

| Deliverable | Status | Bukti |
|---|---|---|
| Skeleton Laravel 13.32 + Livewire 4.4 + Fortify 1.39 | ✅ | `artisan about` exit 0 di PHP 8.5 |
| `phpunit.xml` → MariaDB (bukan sqlite `:memory:`) | ✅ | 99 test berjalan, bukan error koneksi |
| `.gitignore` menutup SEMUA varian `.env` | ✅ | `git ls-files` → hanya `.env.example` |
| Secret gate terbukti memblokir | ✅ | 2 skenario (staged + staged-blob) DITOLAK |
| `App\Support\BotVerdict` | ✅ | 99 test |
| `App\Support\BotDetector` | ✅ | 95 test: 38 UA bot + 10 UA manusia |

**Test:** 99 passed / 281 assertions / ~8ms

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

## Yang belum dikerjakan (jujur)

1. `UserAgentParser` dan `VisitorHasher` — sisa Sesi 1
2. Migrasi 3 tabel (`links`, `click_events`, `link_daily_rollups`)
3. Sesi 2–5: rollup engine, redirect path, UI, docs + E2E + rehearsal
4. Manual steps factory: belum ada git remote (CI diam) → `scripts/local-ci.sh` dipakai
5. `local-ci.sh` belum dijalankan sekali pun
