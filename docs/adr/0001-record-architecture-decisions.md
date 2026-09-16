# 1. Catat keputusan arsitektur

- **Status:** accepted
- **Tanggal:** 2026-09-16

## Konteks

Keputusan struktural punya alasan yang jelas saat dibuat, dan alasan itu hilang
dalam hitungan minggu. Yang tersisa hanya kode, dan kode tidak menjelaskan
**mengapa** — sehingga pilihan yang dulu sadar tampak seperti kelalaian, lalu
diubah oleh orang berikutnya (sering kali diri sendiri) tanpa mengetahui
konsekuensinya.

## Keputusan

Setiap keputusan yang signifikan secara arsitektur dicatat sebagai ADR bernomor
di `docs/adr/`. Satu keputusan, satu file. Sekali **accepted**, isinya tidak
diubah — koreksi dibuat sebagai ADR baru yang menggantikannya, supaya sejarah
penalaran tetap utuh.

Format tiap ADR: **Konteks · Keputusan · Alternatif yang ditolak · Konsekuensi**.

## Konsekuensi

- Alternatif yang **ditolak** wajib ditulis, bukan hanya yang dipilih. Itu
  bagian yang paling sering hilang, dan justru yang paling mencegah pengulangan
  kesalahan yang sama.
- Konsekuensi ditulis jujur, termasuk yang merugikan. ADR yang hanya memuji
  keputusannya sendiri tidak berguna sebagai catatan.
- Daftar ADR proyek ini:

| # | Keputusan |
|---|---|
| 0001 | Mencatat keputusan arsitektur (dokumen ini) |
| 0002 | `visitor_hash` berotasi harian, tanpa IP mentah |
| 0003 | Rollup full-recompute, bukan delta |
| 0004 | Klik sinkron, rollup melalui queue |
| 0005 | Rute `/c/{code}`, bukan `/{code}` |
| 0006 | PHPUnit, bukan Pest |
| 0007 | Pertahanan bot shadow mode sebelum enforcement |
| 0008 | Livewire 4 + Blade, bukan Inertia/React |
