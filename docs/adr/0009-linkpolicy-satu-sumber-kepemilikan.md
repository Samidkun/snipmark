# 9. LinkPolicy sebagai satu sumber kebenaran kepemilikan

- **Status:** accepted
- **Tanggal:** 2026-09-16

## Konteks

Spec §10 menyatakan: *"Ownership ditegakkan **hanya** di `LinkPolicy`."*

Kenyataannya `LinkPolicy` **tidak ada**. Aturan kepemilikan ditulis ulang di
setiap tempat yang menyentuh tautan:

```php
// TautanIndex::tautanMilikSaya()
if ($link === null || $link->user_id !== auth()->id()) { abort(403); }

// AnalitikTautan::mount()
if ($link->user_id !== auth()->id()) { abort(403); }
```

Keduanya **benar**, dan IDOR-nya memang tertutup (dibuktikan: user A meminta
`/dashboard/{link-B}` → 403). Jadi ini bukan bug yang sedang aktif — ini **utang
yang belum jatuh tempo**.

Masalahnya adalah bentuk duplikasi itu, bukan hasilnya sekarang. Aturan otorisasi
yang disalin ke N tempat punya sifat: **komponen ke-N+1 bisa lupa menyalinnya**,
dan ketika itu terjadi tidak ada satu pun test yang gagal — karena test-nya juga
menyalin asumsi yang sama. IDOR baru terlihat saat ada orang menebak id.

Audit menemukan celah ini nyata: **tidak ada satu pun test otorisasi untuk halaman
analytics**, padahal halaman itu menerima id dari URL dan justru paling mudah
diserang (ganti kode di address bar).

## Keputusan

**`app/Policies/LinkPolicy.php`** — aturan kepemilikan hidup di satu kelas:

| Aksi | Aturan |
|---|---|
| `view` | pemilik saja |
| `update` | pemilik saja |
| `delete` | pemilik saja |
| `create` | `true` (membuat tautan hanya butuh login; tidak ada kepemilikan untuk diperiksa) |

Kedua komponen memakai `Gate::authorize()`:

```php
// TautanIndex::tautanMilikSaya()
$link = Link::query()->whereKey($id)->firstOrFail();
Gate::authorize('update', $link);

// AnalitikTautan::mount()
Gate::authorize('view', $link);
```

Tidak ada registrasi manual: Laravel 11+ menemukan `App\Policies\LinkPolicy` untuk
`App\Models\Link` lewat konvensi. Konvensi itu diuji secara eksplisit
(`test_laravel_menemukan_policy_untuk_model_link`), supaya rename atau penghapusan
policy **gagal di test**, bukan gagal di produksi (di mana gejalanya adalah
*setiap orang* — termasuk pemilik — ditolak 403).

## Alternatif yang ditolak

**Biarkan cek manual di tiap komponen.** Ini status quo. Ditolak karena duplikasi
aturan otorisasi adalah kelas bug yang tumbuh seiring jumlah komponen, dan tidak
ada mekanisme yang menahannya. Menguji tiap komponen secara terpisah juga tidak
menolong: itu menguji salinan, bukan aturannya.

**`spatie/laravel-permission`.** Ditolak di spec §3 (YAGNI): aplikasi ini tidak
mengenal role. Setiap user hanya melihat tautannya sendiri. Memasang paket
permission untuk aturan satu baris adalah dependensi tanpa konsumen.

**`Gate::define()` di service provider, bukan kelas Policy.** Berfungsi, tetapi
kehilangan auto-discovery per model dan memindahkan aturan ke tempat yang tidak
ditemukan pembaca kode saat ia melihat `Link`. Kelas Policy adalah konvensi
Laravel yang sudah dipahami.

**Middleware otorisasi di rute.** Tidak cukup: `TautanIndex` adalah satu komponen
yang menangani banyak aksi (ubah/nonaktif/hapus) dengan id dari parameter aksi,
bukan dari URL. Middleware rute tidak bisa memeriksa kepemilikan per baris.

## Konsekuensi

**Keuntungan yang terbukti:** aturan kepemilikan kini diuji **sendiri**
(`tests/Unit/LinkPolicyTest.php`), bukan lewat komponen. Mutation check
memperlihatkan kekuatannya: mengubah policy menjadi `return true` menyalakan
**7 test**; mengubahnya menjadi `return false` menyalakan **6 test + 2 error**.
Melepas `Gate::authorize()` dari `TautanIndex` menyalakan **3 test**; dari
`AnalitikTautan`, **2 test**. Sebelumnya, tidak ada satu pun test yang bisa
mendeteksi pelepasan otorisasi di halaman analytics.

**Biaya yang diterima:** satu lapisan tambahan (`Gate`) antara komponen dan
aturan. Diterima sadar — lapisan itu yang membuat aturannya bisa diuji dan
berubah di satu tempat.

**Perubahan perilaku yang didokumentasikan:** `tautanMilikSaya()` sebelumnya
memberi **403** untuk id yang tidak ada; sekarang **404** lewat `firstOrFail()`.
Alasan: untuk id yang tidak ada, tidak ada kepemilikan yang bisa dilanggar, dan
404 adalah jawaban yang jujur. Untuk id yang ada tetapi milik orang lain, tetap
**403** — percobaan menyentuh data orang lain harus terlihat sebagai kesalahan,
bukan sunyi yang menyembunyikan bug otorisasi.
