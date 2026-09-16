// Snipmark — entrypoint JS.
//
// TIDAK ada `import` Livewire di sini, dan itu disengaja.
//
// Livewire 4 menyuntikkan script-nya SENDIRI ke halaman (lihat tag
// `<script src="/livewire-.../livewire.js">` pada HTML). Mengimpor Livewire
// lagi dari app.js membuat DUA instance berjalan berdampingan. Gejalanya sangat
// menyesatkan:
//
//   [warning] Detected multiple instances of Livewire running
//   [warning] Detected multiple instances of Alpine running
//   window.Livewire -> object, tetapi Livewire.all() -> 0
//
// Halaman tampak sempurna (HTML lengkap, tabel dan tombol terlihat), tidak ada
// satu pun error di konsol — hanya dua peringatan — dan setiap tombol diam.
// Instance kedua menimpa registry komponen milik instance pertama.
//
// Bundle mana yang disajikan Livewire ditentukan oleh `csp_safe` di
// config/livewire.php: `true` -> livewire.csp.js (tanpa `new Function()`,
// cocok untuk CSP ketat), `false` -> livewire.js. Jadi konfigurasi itu adalah
// SATU-SATUNYA saklar; app.js tidak boleh ikut campur.
//
// Tidak ada pustaka chart di sini: grafik dirender sebagai SVG di sisi server
// (app/Support/Analytics/Sparkline.php), sehingga tidak ada bundel frontend
// tambahan yang harus dirawat atau diaudit.
//
// Penutupan modal dengan Escape juga bukan urusan file ini: markup modal sudah
// memakai `x-on:keydown.escape.window="$wire.tutupModal()"`. Handler global
// kedua akan saling bertabrakan dan `document.querySelector('[role="dialog"]')`
// menemukan dialog yang salah begitu ada lebih dari satu komponen.
