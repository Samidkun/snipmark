# Benchmark — Penulisan Klik (sync vs queue vs batch)

**Keputusan yang didasari dokumen ini:** spec §8 — jalur penulisan klik berjalan
**sinkron**, rollup berjalan di **queue**.

Angka di bawah adalah hasil pengukuran nyata di environment project ini, bukan
perkiraan. Perintahnya reproducible dan tertulis di akhir dokumen.

---

## Environment

| | |
|---|---|
| PHP | 8.5.10 (NTS, OPcache) |
| MariaDB | 12.3.3, host `127.0.0.1:3306` |
| `QUEUE_CONNECTION` | `database` (tidak ada Redis di mesin ini) |
| `CACHE_STORE` | `file` |
| Zona analitik | `Asia/Jakarta` |
| Dataset | 12 link, 8.800 `click_events`, 465 baris rollup (seeder sintetis) |

## Metode

`php artisan snipmark:bench:click-logging --iterations=1000 --rounds=3`

- 1000 klik per mode, **3 ronde**, tabel yang dilaporkan adalah **median**
  (bukan satu run — satu run mudah terdistorsi oleh IO/cache acak).
- Tabel `click_events` dan `jobs` dibersihkan di antara mode.
- Mode queue diukur **sampai event benar-benar tertulis**, bukan hanya sampai
  `dispatch()` kembali. Mengukur hanya dispatch akan menipu: pekerjaannya masih
  di depan.

## Hasil

| Mode | Median | Terbaik | Terburuk | Klik/detik |
|---|---:|---:|---:|---:|
| **sync** (1 INSERT/klik) | 991,4 ms | 862,3 ms | 1.009,8 ms | **1.009** |
| **queue** (`database`, via worker) | 10.389,5 ms | 3.659,5 ms | 16.338,5 ms | **96** |
| **batch** (`insertAll`) | 19,1 ms | 19,0 ms | 24,9 ms | **52.260** |

## Analisis

**Queue 10,5× lebih lambat daripada sync** pada setup ini (96 vs 1.009 klik/detik).

Penyebabnya struktural, bukan kebetulan: pada queue `database` **setiap** klik
menghasilkan **dua** operasi tulis — satu INSERT ke `jobs`, satu INSERT ke
`click_events` — ditambah biaya serialisasi payload, polling worker, dan update
status job. Untuk pekerjaan berdurasi milidetik, seluruh biaya itu lebih besar
daripada pekerjaan yang dipindahkannya.

Sementara itu jalur sync hanya melakukan satu INSERT, dan itu memang pekerjaan
yang dibutuhkan. Tidak ada yang dihemat.

**Batch 52× lebih cepat daripada sync.** Ini menunjukkan bahwa biaya sebenarnya
bukan "INSERT itu mahal", melainkan **perjalanan bolak-balik per pernyataan**.
Implikasi desain: kalau throughput perlu dinaikkan drastis, jalan yang benar adalah
**menggabungkan tulis**, bukan memindahkannya ke queue.

## Keputusan

1. **v1: penulisan klik sinkron.** Satu INSERT per klik, di jalur redirect.
   Pada 1.009 klik/detik, satu node ini sanggup melayani jauh lebih banyak
   daripada yang akan dilihat aplikasi portofolio.
2. **Queue tetap dipakai untuk rollup** — pekerjaan berat, boleh gagal dan dicoba
   ulang, dan kegagalannya tidak boleh memperlambat redirect.
3. **Batas yang diketahui:** angka ini dari satu node tanpa Redis. Bila worker
   berada di proses/mesin terpisah (sehingga tulis ke DB tidak lagi berada di
   jalur kritis permintaan), kesimpulannya bisa berbeda — itulah **upgrade path**
   yang dicatat, bukan asumsi yang dipegang sekarang.

## Peringatan kejujuran

Angka ini **tidak boleh** disajikan sebagai "benchmark produksi". Ini satu mesin,
satu disk, tanpa konkurensi, tanpa beban baca paralel. Yang disimpulkan hanyalah
**perbandingan relatif antar jalur pada setup ini** — dan itu sudah cukup untuk
memutuskan jalur mana yang dipakai v1.

Yang **tidak** diukur: latensi p99, throughput dengan konkurensi, perilaku saat
`click_events` sudah puluhan juta baris, dan efek index pada kecepatan INSERT
saat tabel membesar. Semuanya dicatat sebagai batas, bukan diabaikan.

## Reproduksi

```bash
php artisan snipmark:seed:traffic --days=90 --clicks=400 --fresh --rollup
php artisan snipmark:bench:click-logging --iterations=1000 --rounds=3
```
