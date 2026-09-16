# Runbook — Snipmark

**Untuk siapa:** yang merawat aplikasi ini. Teknis.
**Stack:** Laravel 13.32 · Livewire 4.4 · Fortify 1.39 · MariaDB 12.3 · PHPUnit 12.5 · PHP 8.5

---

## 1. Gambaran arsitektur

```
Pengunjung ──GET /c/{code}──▶ RedirectController
                                  │
                                  ├─ 1. INSERT click_events   (SINKRON — klik tidak bisa dipulihkan)
                                  └─ 2. 302 ke tujuan         (jangan tunggu apa pun selain insert)

Terjadwal ──▶ snipmark:rollup:day ──▶ full-recompute satu hari ──▶ link_daily_rollups
                                              │
                                              └─ idempoten: jalankan 2x = angka sama

Pemilik ──GET /dashboard──▶ Livewire TautanIndex ──▶ baca link_daily_rollups
```

Tiga keputusan yang membentuk bentuk di atas — baca ADR-nya sebelum mengubahnya:

| Keputusan | ADR | Ringkas |
|---|---|---|
| Klik sinkron, rollup via queue | 0003, 0004 | Yang tidak bisa dipulihkan → sinkron. Yang bisa dihitung ulang → queue. |
| Rollup full-recompute | 0003 | Delta merusak angka permanen tanpa gejala. |
| Nonce CSP per-request | 0008, B27 | Bukan formalitas: salah di sini = semua tombol mati. |

---

## 2. Setup lokal (dari mesin bersih)

```bash
git clone <repo> snipmark && cd snipmark
composer install
npm install

cp .env.example .env
php artisan key:generate          # WAJIB — tanpa ini, semua request gagal

# Buat database (dua-duanya: dev dan test)
mysql -u root -e "CREATE DATABASE snipmark CHARACTER SET utf8mb4;"
mysql -u root -e "CREATE DATABASE snipmark_test CHARACTER SET utf8mb4;"

php artisan migrate
npm run build
php artisan serve --port=8899
```

**Jangan pakai SQLite.** `pdo_sqlite` tidak tersedia di lingkungan target, dan
`phpunit.xml` sudah diarahkan ke MariaDB. Mengubahnya ke `:memory:` membuat test
gagal dengan error koneksi yang membingungkan (bug B2).

### Akun demo

```bash
php artisan tinker --execute='
$u = App\Models\User::firstOrCreate(
  ["email" => "demo@snipmark.test"],
  ["name" => "Demo", "password" => Illuminate\Support\Facades\Hash::make("password")]
);
echo $u->email;
'
```

---

## 3. Perintah yang tersedia

| Perintah | Kegunaan | Catatan |
|---|---|---|
| `snipmark:rollup:day {tanggal}` | Hitung ulang rollup satu hari | Idempoten — aman dijalankan berulang |
| `snipmark:rollup:reconcile` | Deteksi drift rollup vs event nyata | **exit 1 bila ada drift** |
| `snipmark:rollup:reconcile --fix` | Perbaiki drift | Laporan memisahkan `drifts` vs `fixed` |
| `snipmark:seed:traffic` | Lalu-lintas sintetis untuk demo/benchmark | — |
| `snipmark:bench:click-logging` | Bandingkan sync / queue / batch | Lihat `docs/benchmarks.md` |

### Menjalankan test

```bash
bash scripts/run-tests.sh          # PHPUnit + ringkasan
npx playwright test                # E2E (8 test)
```

**Exit code adalah satu-satunya sinyal yang dipercaya.** Reporter
`laravel/pao` pernah mencetak `{"result":"passed"}` untuk suite yang exit
code-nya 1 (B7). Karena itu `scripts/tests-summary.py` menolak melaporkan
"hijau" bila exit code ≠ 0. **Jangan** menyimpulkan hasil dari pipe (`| tail`) —
itu pernah menyembunyikan drift nyata (B24).

---

## 4. Deployment

```bash
php artisan down --render="errors::503"

git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build

php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart          # WAJIB: worker lama masih memakai kode lama

php artisan up
```

### Rollback

```bash
php artisan down
git checkout <tag-sebelumnya>
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate:rollback --step=1   # hanya bila migrasi terakhir yang bermasalah
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart
php artisan up
```

---

## 5. Checklist setelah deploy — JANGAN dilewati

Ini bukan formalitas. Tiga bug di proyek ini (B26, B27, dan B19 sebelum itu)
**hanya** terlihat di browser nyata, dan semuanya lolos dari ratusan test unit.

```bash
# 1. Halaman hidup
curl -s -o /dev/null -w "%{http_code}\n" https://<host>/

# 2. Nonce CSP cocok antara header dan HTML — SATU request, bukan dua
curl -s -D - https://<host>/login | grep -i "content-security-policy"   # ambil nonce
curl -s -D - https://<host>/login | grep -oE 'nonce="[^"]+"' | head -1  # harus SAMA

# 3. Login sungguhan, lalu dashboard
#    (jangan hanya cek HTTP 200 — halaman kosong juga 200)
```

Di browser, **buka konsol dan pastikan bersih**:

- ❌ `Refused to execute inline script` → nonce tidak cocok (B19)
- ❌ `EvalError ... unsafe-eval` → bundle Livewire bukan CSP-safe (B27)
- ❌ `Detected multiple instances of Livewire` → Livewire diimpor dua kali
- ✅ Halaman dashboard menampilkan **tabel tautan + tombol "Buat tautan"**
  (kalau hanya judul tanpa isi → layout membuang slot, B26)

---

## 6. Troubleshooting

### Dashboard tampil kosong (sidebar ada, isi tidak)

**Kemungkinan 1 — layout membuang slot komponen.** Livewire membungkus komponen
full-page dengan `@section('slot')` (default `slotOrSection = 'slot'`, **bukan**
`'content'`). Layout harus menyediakan `@yield('slot')`. Lihat B26.

**Kemungkinan 2 — cek HTML mentah dulu:**
```bash
curl -s <host>/dashboard | grep -c 'wire:snapshot'   # harus > 0
```
Bila 0, komponen tidak dirender. Bila > 0 tetapi tampilan kosong, layout-nya.

### Tombol tidak bereaksi sama sekali (halaman tampak normal)

Ini bug #19/#27. Periksa konsol browser, lalu:

```bash
# Apakah bundle yang disajikan CSP-safe?
curl -s <host>/livewire-*/livewire.js | grep -c 'new Function('   # harus 0
```

Pastikan **`csp_safe => true`** di `config/livewire.php`. Jangan menyelesaikannya
dengan menambahkan `'unsafe-eval'` ke CSP — itu membuka XSS, dan masalahnya
bukan CSP-nya terlalu ketat, melainkan bundle yang salah.

Periksa juga: **jangan** `import` Livewire di `resources/js/app.js`. Livewire
menyuntikkan script-nya sendiri; mengimpor lagi menghasilkan dua instance,
`Livewire.all()` jadi 0, dan konsol hanya menampilkan peringatan
"multiple instances".

### `php artisan test` melaporkan hijau tetapi ada masalah

Jangan percaya ringkasannya. Periksa exit code:

```bash
php artisan test; echo "EXIT=$?"     # BUKAN: php artisan test | tail
```

### Drift ditemukan `reconcile`

```bash
php artisan snipmark:rollup:reconcile              # lihat apa yang dilaporkan
php artisan snipmark:rollup:reconcile --fix
php artisan snipmark:rollup:reconcile              # WAJIB: panggilan kedua harus bersih
```

Panggilan kedua itu bagian dari pembuktian, bukan kehati-hatian berlebih: tanpa
itu, "sudah diperbaiki" hanyalah klaim (pelajaran B12).

---

## 7. Batasan yang dinyatakan (bukan disembunyikan)

| Batasan | Sebab | Upgrade path |
|---|---|---|
| **Single-node** | Tanpa Redis, invalidasi cache tidak lintas node | Pasang Redis, ubah `CACHE_STORE` + `QUEUE_CONNECTION` |
| **Unique visitor hanya per hari** | Salt `visitor_hash` dirotasi harian (ADR-0002) | Tidak ada — ini keputusan privasi |
| **Bot dalam shadow mode** | False positive belum terukur | Lihat §8 |
| **Rollup tertunda** | Dijalankan terjadwal, bukan saat klik (ADR-0004) | Jadwalkan lebih sering |

**Bukan bagian dari v1** (upgrade path dicatat, bukan dikerjakan):
API publik, multi-tenant/workspace, A/B redirect, custom domain, tautan
berkata sandi, impor Google Analytics, Geo-IP (butuh lisensi MaxMind),
rollup per jam, ekspor CSV/PDF.

---

## 8. Kriteria promosi: bot shadow mode → enforcement

Bot saat ini **dideteksi dan dicatat, tidak diblokir** (ADR-0007).

**Jangan nyalakan enforcement sebelum:**

1. Terkumpul **≥ 1.000 event**.
2. Rasio false positive ditinjau pada **sampel manual**, bukan dari ringkasan.
3. Angka itu dicatat di dokumen ini **sebelum** perubahan dinyalakan.

Alasannya: salah menandai pengunjung nyata sebagai bot itu kehilangan yang senyap
— tidak ada error, tidak ada keluhan yang bisa ditindaklanjuti, hanya angka yang
lebih rendah dari seharusnya. Shadow mode memberi kemampuan mengukurnya lebih
dulu, dan itu ditukar dengan sedikit beban database. Tukar itu sepadan.

---

## 9. Peta berkas

```
app/
  Http/Middleware/SecurityHeaders.php   ← nonce CSP per-request (B19). Baca komentarnya.
  Http/Controllers/RedirectController.php
  Livewire/                             TautanIndex, AnalitikTautan
  Services/                             ClickRecorder, RollupService, ReconcileReport
  Support/                              BotDetector, UserAgentParser, VisitorHasher
  Support/Analytics/                    DayWindow, RollupAggregator, Sparkline (SVG)
  Console/Commands/                     rollup:day, rollup:reconcile, seed:traffic, bench
docs/
  adr/                                  Keputusan + alternatif yang DITOLAK
  benchmarks.md                         Angka nyata (sync 1009 / queue 96 / batch 52260)
  superpowers/specs/                    Spec desain
e2e/
  global-setup.ts                       Login SEKALI (throttle Fortify: 5/menit/IP)
  smoke.spec.ts                         Test yang menemukan B26/B27
scripts/
  run-tests.sh · tests-summary.py       Gerbang yang memeriksa exit code
  mutation-check.py                     Buktikan test bisa gagal
resources/views/layouts/app.blade.php   ← melayani 3 jalur render. Baca komentarnya.
```
