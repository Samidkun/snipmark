# 2. Simpan `visitor_hash` berotasi harian, tanpa IP mentah

- **Status:** accepted
- **Tanggal:** 2026-09-16

## Konteks

Analitik tautan butuh membedakan "satu orang mengklik 50 kali" dari "50 orang
mengklik sekali". Itu memerlukan semacam identitas pengunjung yang stabil dalam
satu hari — tetapi pengunjung tidak login, dan menyimpan penanda yang stabil
lintas-hari berarti membangun profil orang tanpa persetujuan mereka.

Kebutuhan nyata hanya: **unik per hari**. Setelah hari berganti, tidak ada satu
pun fitur yang butuh tahu bahwa klik kemarin dan klik hari ini berasal dari
orang yang sama.

## Keputusan

Simpan `visitor_hash` = HMAC dari (IP + User-Agent + salt), dengan **salt yang
di-rotasi setiap hari** (zona WIB). IP mentah **tidak pernah** disimpan. Yang
disimpan hanya hash 64 karakter dan `user_agent` mentah (dibatasi 512 karakter).

Konsekuensi teknis yang diterima dengan sadar:

- Dua hari yang berbeda menghasilkan hash yang **tidak dapat dihubungkan** —
  itu tujuannya, bukan efek samping.
- "Unique visitor" hanya bermakna **per hari**. Angka lintas-hari adalah
  penjumlahan, bukan orang unik. Ini dinyatakan di UI, bukan disembunyikan.
- Rotasi salt berarti data hari lama tidak bisa di-rehash. Bila parser UA
  diperbaiki, `user_agent` mentah masih bisa diparse ulang (itulah alasan kolom
  itu disimpan), tetapi identitas pengunjung tetap tidak bisa dipulihkan.

## Alternatif yang ditolak

**Simpan IP mentah.** Paling sederhana dan paling akurat untuk deteksi bot.
Ditolak: itu data pribadi, dan menyimpannya "untuk berjaga-jaga" berarti
memikul kewajiban perlindungan data untuk fitur yang tidak ada.

**Simpan IP terenkripsi.** Terlihat seperti kompromi, tetapi tidak: kunci
dekripsi pasti ada di server yang sama, sehingga perlindungannya hanya
menambah lapisan yang bisa dilewati siapa pun yang sudah punya akses server.
Lebih buruk dari tidak menyimpan, karena memberi rasa aman yang palsu.

**Salt tetap (tidak berotasi).** Lebih mudah diimplementasikan dan membuat
analitik lintas-hari mungkin. Ditolak: itu tepatnya membangun profil jangka
panjang pengunjung anonim.

## Konsekuensi

- Deteksi bot berbasis riwayat lintas-hari menjadi tidak mungkin. Diterima:
  heuristik bot di ADR-0007 bekerja dalam satu request.
- `VisitorHasher` butuh `$secret`, sehingga tidak bisa memanggil `config()`
  sendiri (pelajaran B11). Nilainya disuplai lewat binding container.
