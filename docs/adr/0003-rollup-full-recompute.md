# 3. Rollup full-recompute, bukan delta, dengan reconciliation

- **Status:** accepted
- **Tanggal:** 2026-09-16

## Konteks

Dashboard menampilkan angka harian per tautan (klik, pengunjung unik, breakdown
device/browser/OS/referrer). Menghitungnya dari tabel `click_events` setiap kali
halaman dibuka tidak layak: tabel itu tumbuh tanpa batas, dan satu kunjungan
dashboard akan memicu agregasi atas seluruh riwayat.

Perlu tabel agregat (`link_daily_rollups`). Pertanyaan sebenarnya bukan "perlu
agregat?" tetapi **bagaimana agregat itu dijaga benar**.

## Keputusan

Rollup harian dihitung ulang **dari nol** untuk satu hari penuh, setiap kali
dijalankan — **full-recompute idempoten**, bukan penambahan delta.

Sifat yang dijamin: menjalankan rollup untuk hari yang sama **dua kali**
menghasilkan angka yang sama persis. Termasuk ketika data sumber berubah:
`rollupDay()` menghapus baris rollup milik link yang sudah tidak punya event di
hari itu, sehingga baris basi tidak tertinggal.

Ditambah `snipmark:rollup:reconcile`, yang membandingkan rollup dengan event
nyata dan melaporkan selisih. `--fix` memperbaikinya. Kontraknya memisahkan
`drifts` (ditemukan) dari `fixed` (ditangani), supaya laporan tidak bisa
mengklaim bersih hanya karena ia baru saja memperbaiki sesuatu.

## Alternatif yang ditolak

**Delta / increment.** Lebih murah: hanya menambah 1 ke baris hari ini saat
klik masuk. Ditolak karena **satu kegagalan merusak angka secara permanen dan
tanpa gejala**. Job yang mati di tengah, retry yang jalan dua kali, atau event
yang dihapus — semuanya meninggalkan angka yang salah, dan tidak ada cara
mengetahuinya tanpa menghitung ulang dari sumber. Full-recompute membuat
kesalahan tidak mungkin bertahan melewati satu kali jalankan.

**Materialized view / trigger database.** Menyebar logika bisnis ke skema, dan
membuatnya sulit diuji maupun di-migrasi. Selain itu `link_daily_rollups`
menyimpan hasil parsing UA yang bisa berubah ketika parser diperbaiki —
materialized view tidak bisa "diparse ulang".

**Cache saja, tanpa tabel.** Tidak menyelesaikan apa pun: biaya komputasinya
sama, hanya dipindah, dan hilang setiap restart.

## Konsekuensi

- Rollup harian jauh lebih mahal daripada menambah 1 baris. Terukur: sync
  1.009 klik/detik vs batch 52.260 klik/detik (`docs/benchmarks.md`). Karena
  itu rollup dijalankan terjadwal, bukan pada jalur redirect.
- Idempotensi bisa **diuji**, dan memang diuji: jalankan dua kali, bandingkan.
- `reconcile` menjadi alat diagnosis yang jujur. Bug B22 (perbaikan pertama yang
  hanya menangani "hari kosong total") ketemu justru karena command itu
  melaporkan kegagalannya sendiri lewat exit code 1.
