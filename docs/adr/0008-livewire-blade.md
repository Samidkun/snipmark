# 8. Livewire 4 + Blade, bukan Inertia/React

- **Status:** accepted
- **Tanggal:** 2026-09-16

## Konteks

Antarmuka yang dibutuhkan: daftar tautan dengan pencarian, modal buat/ubah,
tabel dengan aksi per baris, dan halaman analitik dengan grafik. Interaktif,
tetapi **tidak** memerlukan aplikasi satu-halaman.

Pilihan utamanya Livewire 4 (server-rendered dengan interaksi) atau
Inertia + React/Vue (SPA dengan backend sebagai API).

## Keputusan

**Livewire 4 + Blade**, tanpa build SPA. Grafik dirender sebagai **SVG di sisi
server** (`app/Support/Analytics/Sparkline.php`), bukan pustaka chart JavaScript.

## Alternatif yang ditolak

**Inertia + React.** Menambah lapisan nyata: bundel frontend, dua bahasa untuk
satu fitur, dan — yang paling relevan di proyek ini — **dua sistem versi yang
harus cocok**. Aplikasi ini tidak punya satu pun layar yang butuh state klien
kompleks; membayar biaya SPA untuk itu berarti mengambil kerumitan tanpa
imbalan.

**Blade murni dengan form POST klasik.** Paling sederhana, tetapi setiap aksi
memuat ulang halaman. Untuk tabel dengan aksi per baris, itu membuat pengalaman
terasa rusak — dan pengalaman itu yang dilihat perekrut.

**Livewire + pustaka chart JS.** Ditolak karena tiga hal: (1) menambah dependensi
yang harus diaudit dan diperbarui, (2) chart JS menggambar lewat canvas yang
tidak terbaca pembaca layar — melanggar syarat a11y di spec §9, (3) SVG di
server tidak butuh JavaScript sama sekali untuk tampil, sehingga grafiknya tetap
terlihat bahkan ketika script diblokir.

## Konsekuensi

**Keuntungan yang terbukti:** karena tidak ada SPA, satu sumber kebenaran untuk
render. Pencarian, paginasi, dan modal semuanya server-side.

**Kerumitan yang harus diterima, dan ini nyata:** Livewire membawa asumsinya
sendiri tentang halaman. Dua di antaranya menyebabkan bug yang butuh berhari-hari
untuk ditemukan, dan keduanya hanya terlihat di browser nyata:

1. **CSP.** Livewire menyuntikkan script inline dan (pada bundle standar)
   mengevaluasi ekspresi dengan `new Function()` — diblokir CSP produksi.
   Diperbaiki dengan `csp_safe => true` (ADR ini tidak mengubah keputusan, tetapi
   mencatat biayanya). Lihat B27.
2. **Layout.** Livewire membungkus komponen full-page dengan
   `@section('slot')`, sementara layout aplikasi menyediakan `@yield('content')`.
   Hasilnya halaman kosong **tanpa error apa pun**. Lihat B26.

Keduanya **tidak tertangkap** oleh 349 test PHPUnit, karena test Livewire
memanggil komponen secara langsung dan tidak menyentuh layout maupun browser.
E2E Playwright ditambahkan justru karena ini — dan langsung menemukan keduanya.

**Batas yang dinyatakan:** tanpa Redis, invalidasi cache tidak lintas node
(ADR-0004), sehingga v1 adalah **single-node**. Tercatat di runbook.
