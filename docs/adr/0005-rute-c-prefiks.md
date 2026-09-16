# 5. Rute `/c/{code}`, bukan `/{code}`

- **Status:** accepted
- **Tanggal:** 2026-09-16

## Konteks

URL pendek harus pendek. Rute `/{code}` memberi URL paling ringkas, dan itu
menggoda: pemendek tautan yang URL-nya panjang kehilangan sebagian alasan
keberadaannya.

Tetapi `/{code}` berarti **setiap kemungkinan path satu-segmen** menjadi milik
pemendek tautan: `/login`, `/register`, `/dashboard`, `/favicon.svg`, `/robots.txt`,
`/up` (health check), dan setiap rute yang akan ditambahkan nanti.

## Keputusan

Rute redirect adalah **`/c/{code}`**. Prefiks `/c` dicadangkan untuk redirect.

Kode tautan tetap pendek (7 karakter, alfabet base62 tanpa karakter ambigu),
sehingga URL-nya tetap ringkas: `/c/kxzj6ae`.

## Alternatif yang ditolak

**`/{code}` tanpa prefiks.** URL paling pendek, dan ruang nama rute jadi
**bergantung pada panjang kode**. Artinya: setiap kali ada rute baru bernama
satu kata, ia harus diperiksa terhadap seluruh kode yang mungkin ada. Lebih
buruk lagi, kegagalannya tidak terlihat saat itu juga — ia muncul sebagai
"tautan pendek tertentu tiba-tiba membuka halaman login", dan hanya untuk
pengguna yang kebetulan punya kode itu. Bug yang tidak bisa direproduksi dengan
mudah adalah bug yang mahal.

**Subdomain (`s.example.com/{code}`).** Benar secara arsitektur, tetapi butuh
konfigurasi DNS, sertifikat wildcard, dan penanganan cookie lintas-subdomain.
Biaya operasionalnya tidak sepadan untuk v1.

**`/r/{code}` atau `/{code}` dengan daftar cadangan.** Daftar cadangan kata
terlarang selalu ketinggalan dari rute yang benar-benar ada. Sumber kebenaran
yang harus disinkronkan manual adalah utang.

## Konsekuensi

- Satu karakter lebih panjang. Diterima dengan sadar — itu harga dari ruang nama
  yang tidak bisa bertabrakan.
- Rute lain bebas ditambahkan kapan saja tanpa memeriksa kode tautan.
- `code` tetap `CHAR(7)` di database dengan UNIQUE constraint, sebagai penjaga
  sebenarnya terhadap tabrakan (pelajaran B15: kode 8 karakter ditolak kolom).
