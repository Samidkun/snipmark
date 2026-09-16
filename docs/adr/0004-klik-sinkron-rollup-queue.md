# 4. Pencatatan klik sinkron, rollup melalui queue

- **Status:** accepted
- **Tanggal:** 2026-09-16

## Konteks

Jalur redirect (`GET /c/{code}`) harus mengembalikan 302 **secepat mungkin**:
pengunjung sedang menunggu, dan setiap milidetik di sini langsung terasa.
Tetapi setiap klik juga harus tercatat.

Dua hal ini menarik ke arah berlawanan: mencatat butuh menulis ke database,
dan menunggu penulisan berarti pengunjung menunggu.

## Keputusan

**Klik dicatat SINKRON** (satu insert) sebelum redirect dikirim. **Rollup
dijalankan melalui queue**, terpisah dari jalur redirect.

Alasannya bukan selera: klik yang tidak tercatat **hilang selamanya**. Tidak ada
cara memulihkannya, karena sumbernya sudah pergi (pengunjung sudah pindah).
Sementara rollup yang tertunda **tidak kehilangan apa pun** — event-nya masih ada
di tabel, dan perhitungan ulang akan menghasilkan angka yang benar.

Jadi: yang tidak bisa dipulihkan → sinkron. Yang bisa dihitung ulang → queue.

## Alternatif yang ditolak

**Semua melalui queue.** Terukur: pencatatan lewat queue hanya **96 klik/detik**,
sementara sinkron **1.009 klik/detik** — 10,5× lebih lambat, bukan lebih cepat
(`docs/benchmarks.md`). Penyebabnya: queue menambah round-trip tanpa menghilangkan
penulisan itu sendiri. Yang lebih penting, kegagalan job queue berarti klik
hilang permanen, dan tidak ada rekonsiliasi yang bisa mengembalikannya.

**Semua sinkron (termasuk rollup).** Rollup full-recompute atas satu hari penuh
berjalan di jalur redirect akan membuat waktu respons bergantung pada volume
data — persis yang harus dihindari pada jalur terpanas aplikasi.

**Tanpa Redis, queue database.** Yang dipakai di v1. Batasannya nyata dan
dinyatakan di runbook: **tanpa Redis, invalidasi cache tidak lintas node**.
Aplikasi ini karenanya dinyatakan sebagai **single-node** untuk v1, dan itu
tercatat, bukan disembunyikan.

## Konsekuensi

- Jalur redirect tidak boleh menunggu apa pun selain satu insert.
- `user_agent` **wajib dipotong** sebelum disimpan (kolom 512 karakter). Ini
  bukan teori: UA yang lebih panjang pernah membuat redirect mengembalikan
  HTTP 500 (bug B9).
- Rollup tertunda adalah keadaan normal, bukan kerusakan. Dashboard harus tetap
  menampilkan angka terakhir yang diketahui, dan `reconcile` yang menutup selisih.
