# Panduan Pengguna — Snipmark

**Untuk siapa:** yang memakai aplikasi ini. Tidak perlu latar teknis.

---

## Apa ini

Snipmark memendekkan tautan panjang menjadi pendek, dan **menghitung siapa yang
mengklik** — tanpa mengubah tautan Anda menjadi alat pelacak orang.

Dua hal yang membedakannya dari pemendek tautan lain:

1. **Klik bot dihitung terpisah.** Angka "klik manusia" adalah yang Anda
   percayai. Bot tidak dibuang diam-diam — ia tetap terlihat di "total klik",
   sehingga Anda tahu berapa banyak yang bukan manusia.
2. **Identitas pengunjung tidak dilacak lintas hari.** Anda dapat tahu "12 orang
   mengklik hari ini". Anda **tidak** dapat tahu bahwa orang yang sama mengklik
   kemarin dan hari ini — karena sistemnya memang tidak menyimpan itu.

---

## Mulai

### 1. Buat akun

Buka halaman **Daftar**, isi nama, email, dan kata sandi.

### 2. Buat tautan pendek

Klik **Buat tautan** di dasbor, lalu isi **Tujuan** — alamat lengkap yang ingin
dipendekkan, misalnya `https://toko-saya.com/produk/sepatu-lari-model-baru`.

Harus diawali `http://` atau `https://`. Alamat internal (mis. `http://localhost`)
ditolak — tautan pendek yang menunjuk ke jaringan internal Anda adalah cara
orang lain memakainya untuk memindai jaringan itu.

Setelah disimpan, Anda mendapat kode 7 karakter, misalnya `kxzj6ae`. Tautannya:

```
https://snipmark.example/c/kxzj6ae
```

### 3. Bagikan

Bagikan tautan pendeknya. Analitik mulai bekerja **saat klik pertama masuk** —
tidak ada yang perlu dinyalakan.

---

## Membaca analitik

Klik kode tautan di dasbor untuk membuka halamannya.

| Angka | Artinya |
|---|---|
| **Total klik** | Semua kunjungan, termasuk bot |
| **Klik manusia** | Yang dipercayai. Bot sudah dikeluarkan |
| **Pengunjung unik** | Perkiraan orang berbeda **hari ini** |

Selisih antara "total" dan "manusia" **adalah** jumlah bot. Angka itu sengaja
ditampilkan, bukan disembunyikan.

Ada juga rincian **perangkat, browser, sistem operasi, dan sumber rujukan**.

### Toggle "sertakan bot"

Secara bawaan, bot tidak dihitung. Nyalakan toggle untuk melihat semuanya —
berguna untuk menjawab "apakah lonjakan ini dari manusia?".

---

## Hal yang perlu diketahui

### "Pengunjung unik" hanya bermakna per hari

Sistem mengacak penanda pengunjung **setiap hari**. Artinya:

- ✅ "Hari ini 12 orang mengklik" — benar.
- ❌ "Minggu ini 40 orang unik mengklik" — **tidak benar**. Angka itu penjumlahan
  harian; orang yang mengklik 3 hari dihitung 3 kali.

Ini bukan kekurangan, melainkan cara sistem menghindari melacak orang lintas
hari. Dituliskan di sini supaya Anda tidak salah membacanya.

### Angka bisa sedikit tertinggal

Perhitungan harian berjalan terjadwal, bukan tepat saat klik. Jadi angka hari
ini bisa sedikit tertinggal, lalu menyesuaikan. **Klik tidak pernah hilang** —
yang tertunda hanya peringkasannya.

### Tautan yang dinonaktifkan

Klik **Matikan** untuk membuat tautan berhenti bekerja **tanpa menghapusnya**.
Riwayat analitiknya tetap utuh. Nyalakan kembali kapan saja.

Klik **Hapus** untuk menghapus permanen, termasuk analitiknya. Tautannya
langsung berhenti bekerja.

---

## Tanya umum

**Tautan pendek saya berhenti bekerja.**
Periksa apakah statusnya masih aktif di dasbor. Kalau aktif tetapi tetap tidak
bekerja, tujuan aslinya mungkin sudah tidak ada — Snipmark tidak menyimpan
salinan halaman tujuan.

**Bisakah saya mengubah tujuan tautan yang sudah dibagikan?**
Bisa. Klik **Ubah** — kode pendeknya tetap sama, jadi tautan yang sudah
dibagikan akan menuju alamat baru. Berguna untuk kampanye yang alamatnya berubah.

**Apakah Snipmark menyimpan alamat IP pengunjung saya?**
Tidak. Yang disimpan hanya sidik jari teracak yang berganti setiap hari, dan
tidak bisa dikembalikan menjadi alamat IP. Karena itu "pengunjung unik" hanya
bermakna per hari.

**Apakah angka bot selalu benar?**
Tidak, dan tidak ada yang bisa. Deteksi bot memakai perkiraan, dan saat ini bot
**dicatat tetapi tidak diblokir** — supaya seberapa sering perkiraan itu keliru
dapat diukur lebih dulu sebelum ada tindakan yang merugikan pengunjung nyata.
