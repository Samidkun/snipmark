// Snipmark — entrypoint JS.
//
// Hanya satu dependensi: Livewire. Tidak ada Alpine terpisah (Livewire 4 sudah
// membawanya) dan TIDAK ada pustaka chart — grafik dirender sebagai SVG di sisi
// server (app/Support/Analytics/Sparkline.php), sehingga tidak ada bundel
// frontend tambahan yang harus dirawat atau diaudit.
import '../../vendor/livewire/livewire/dist/livewire.esm';

// Tutup modal dengan Escape. Livewire 4 sudah memuat Alpine, jadi kita tidak
// menambah dependensi apa pun di sini.
document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;

    const modal = document.querySelector('[role="dialog"]');
    if (!modal) return;

    const wire = window.Livewire?.all?.().find((c) => c.$wire);
    wire?.$wire?.tutupModal?.();
});
