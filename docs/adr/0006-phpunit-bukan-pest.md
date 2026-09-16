# 6. PHPUnit, bukan Pest

- **Status:** accepted
- **Tanggal:** 2026-09-16

## Konteks

Pest adalah pilihan default di banyak proyek Laravel baru. Sintaksnya lebih
ringkas, dan untuk proyek yang memakai Laravel 11+ ia biasanya tanpa biaya.

Proyek ini memakai Laravel 13.32 dengan **`laravel/pao`** (pelaporan hasil test
dalam format terstruktur).

## Keputusan

Pakai **PHPUnit 12.5**, bukan Pest.

## Alasan

**Konflik paket, diverifikasi bukan diasumsikan.** Pemeriksaan dependensi
(`composer create-project` di `/tmp/lp-probe`) menunjukkan `pestphp/pest`
bertabrakan dengan `laravel/pao`. Ini ditemukan sebelum satu baris kode aplikasi
ditulis — jadi memilih Pest berarti memilih konflik paket di hari pertama.

**Pest tidak memberi keuntungan yang sebanding dengan risikonya di sini.**
Keuntungan Pest adalah sintaks. Yang dibutuhkan proyek ini adalah kontrol atas
bagaimana kegagalan dilaporkan — dan itu justru bertabrakan dengan `laravel/pao`.

## Temuan yang mengubah prioritas: `laravel/pao` bisa berbohong

Setelah suite berjalan, ditemukan hal yang lebih penting daripada pilihan
framework test: **reporter `laravel/pao` mencetak `{"result":"passed"}` untuk
suite yang exit code-nya 1** (bug B7).

Bukti:

```
php artisan test          -> exit 1, tetapi JSON melaporkan "passed"
PAO_DISABLE=1 php artisan test -> "1 warning, 147 passed"
```

Penyebabnya: PHPUnit melaporkan warning, dan `failOnWarning` membuat exit code
1 — sementara reporter hanya membaca daftar kegagalan test, bukan exit code.

Ini kelas bug yang sama dengan B10 (runner mutasi melaporkan GREEN untuk suite
MERAH) dan B24 (`reconcile | tail` melaporkan exit 0 padahal drift ada).

## Konsekuensi

- **Exit code adalah satu-satunya sinyal yang tidak bisa berbohong.** Setiap
  gerbang di proyek ini memeriksa exit code proses itu sendiri, tidak pernah
  hasil pipe atau ringkasan yang dicetak.
- `scripts/tests-summary.py` menolak melaporkan "hijau" bila exit code ≠ 0,
  sekalipun semua test dilaporkan lulus.
- Output ringkas < 3.800 karakter (pelajaran B23: satu kegagalan pernah
  menghasilkan 257 KB output dan membanjiri konteks).
