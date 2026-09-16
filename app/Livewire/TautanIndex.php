<?php

namespace App\Livewire;

use App\Models\ClickEvent;
use App\Models\Link;
use App\Support\DestinationValidator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Daftar tautan + aksi buat/ubah/nonaktif/hapus.
 *
 * ATURAN KEPEMILIKAN (spec §10): `user_id` HANYA diambil dari sesi terautentikasi.
 * Tidak ada satu pun jalur yang membacanya dari input. Setiap aksi pada tautan
 * memeriksa kepemilikan lebih dulu dan mengembalikan 403 bila bukan miliknya —
 * bukan mengabaikan diam-diam, karena abaikan-diam-diam menyembunyikan bug
 * otorisasi sampai ada yang menebak-nebak id.
 *
 * Pencarian menetralkan wildcard LIKE: `%` dan `_` di-escape supaya tidak
 * mengembalikan seluruh tabel (kelas bug yang sudah pernah lolos di run lain).
 */
#[Layout('layouts.app')]
class TautanIndex extends Component
{
    use WithPagination;

    /** Per halaman. Daftar harus berpaginasu karena bisa tumbuh tanpa batas. */
    public const PER_HALAMAN = 25;

    #[Url(as: 'q', except: '')]
    public string $cari = '';

    public bool $modalTerbuka = false;

    /** id tautan yang sedang diubah; null = mode buat. */
    public ?int $mengubah = null;

    public string $destination = '';

    public function updatingCari(): void
    {
        $this->resetPage();
    }

    /**
     * Aturan validasi dasar untuk field ini.
     *
     * Aturan URL yang sesungguhnya hidup di `DestinationValidator` (kelas murni
     * yang sudah diuji); method ini hanya menjaga tipe & panjang, lalu validator
     * itu yang memutuskan boleh/tidak. Satu sumber kebenaran, bukan dua aturan
     * yang bisa menyimpang.
     *
     * @return array<string, array<int, string>>
     */
    protected function rules(): array
    {
        return [
            'destination' => ['required', 'string', 'max:'.Link::MAX_DESTINATION_LENGTH],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'destination.required' => 'Tujuan wajib diisi.',
            'destination.max' => 'Tujuan terlalu panjang (maks :max karakter).',
        ];
    }

    public function bukaBuat(): void
    {
        $this->resetValidation();
        $this->mengubah = null;
        $this->destination = '';
        $this->modalTerbuka = true;
    }

    public function bukaUbah(int $id): void
    {
        $link = $this->tautanMilikSaya($id);

        $this->resetValidation();
        $this->mengubah = $link->id;
        $this->destination = $link->destination;
        $this->modalTerbuka = true;
    }

    public function tutupModal(): void
    {
        $this->modalTerbuka = false;
        $this->mengubah = null;
        $this->destination = '';
        $this->resetValidation();
    }

    public function simpan(): void
    {
        $this->destination = $this->normalkanScheme(trim($this->destination));

        $validator = new DestinationValidator;

        // Satu sumber kebenaran untuk aturan URL: kelas murni yang sudah diuji.
        if (! $validator->isValid($this->destination)) {
            $this->addError('destination', $validator->reason($this->destination) ?? 'Tujuan tidak sah.');

            return;
        }

        $this->validateOnly('destination');

        if ($this->mengubah !== null) {
            $link = $this->tautanMilikSaya($this->mengubah);
            $link->update(['destination' => $this->destination]);

            $pesan = 'Tautan diperbarui.';
        } else {
            // Batas laju HANYA untuk pembuatan (menambah baris). Diperiksa setelah
            // validasi supaya percobaan yang ditolak validasi tidak memakan kuota.
            // Key per pengguna: batas satu akun tidak boleh menghukum akun lain.
            $batas = max(1, (int) config('snipmark.link_create_rate_limit', 60));
            $key = 'buat-tautan:'.auth()->id();

            if (RateLimiter::tooManyAttempts($key, $batas)) {
                $detik = RateLimiter::availableIn($key);

                $this->addError('destination', "Terlalu banyak tautan dibuat. Coba lagi dalam {$detik} detik.");

                return;
            }

            // user_id dari SESI, tidak pernah dari input (spec §10).
            Link::createWithUniqueCode([
                'user_id' => auth()->id(),
                'destination' => $this->destination,
            ]);

            // Kuota hanya terpakai setelah tautan benar-benar tersimpan.
            RateLimiter::hit($key, 60);

            $pesan = 'Tautan dibuat.';
        }

        $this->tutupModal();
        $this->dispatch('notifikasi', pesan: $pesan);
        session()->flash('sukses', $pesan);
    }

    public function toggleAktif(int $id): void
    {
        $link = $this->tautanMilikSaya($id);

        $link->update(['is_active' => ! $link->is_active]);

        session()->flash('sukses', $link->is_active ? 'Tautan diaktifkan.' : 'Tautan dinonaktifkan.');
    }

    public function hapus(int $id): void
    {
        $link = $this->tautanMilikSaya($id);

        // Soft delete: tautan mati seketika, tetapi datanya masih bisa dipulihkan.
        $link->delete();

        session()->flash('sukses', 'Tautan dihapus.');
    }

    /**
     * Mengambil tautan milik pengguna ATAU gagal dengan 403.
     *
     * `abort(403)` dipilih daripada mengembalikan null, supaya percobaan menyentuh
     * data orang lain terlihat sebagai kesalahan — bukan sunyi yang menyembunyikan
     * bug otorisasi.
     */
    private function tautanMilikSaya(int $id): Link
    {
        $link = Link::query()->whereKey($id)->first();

        if ($link === null || $link->user_id !== auth()->id()) {
            abort(403);
        }

        return $link;
    }

    /** Menambahkan https:// bila pengguna tidak mengetikkan scheme. */
    private function normalkanScheme(string $url): string
    {
        if ($url === '') {
            return '';
        }

        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*:#', $url)) {
            return $url;
        }

        return 'https://'.ltrim($url, '/');
    }

    /** Menetralkan wildcard LIKE supaya '%' tidak mengembalikan semua baris. */
    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
    }

    public function render(): View
    {
        $userId = auth()->id();
        $term = trim($this->cari);

        $links = Link::query()
            ->where('user_id', $userId)
            ->when($term !== '', function ($q) use ($term) {
                $like = '%'.$this->escapeLike(mb_strtolower($term)).'%';

                // LOWER() di KEDUA sisi: tanpa itu pencarian peka huruf besar/kecil
                // dan separuh hasil hilang tanpa pesan (pelajaran bug #11).
                $q->where(function ($qq) use ($like) {
                    $qq->whereRaw('LOWER(destination) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(code) LIKE ?', [$like]);
                });
            })
            ->orderByDesc('created_at')
            ->paginate(self::PER_HALAMAN);

        return view('livewire.tautan-index', [
            'links' => $links,
            'ringkasan' => $this->ringkasan($userId),
        ]);
    }

    /**
     * Ringkasan angka di atas daftar.
     *
     * Dua angka diambil dari SUMBER BERBEDA, dan itu disengaja:
     *
     *  - `total_klik` = SUM(links.total_clicks) — counter denormalized, SAMA dengan
     *    yang ditampilkan di kolom "Klik" pada tabel. Bila dihitung dari
     *    click_events, ringkasan dan tabel bisa berbeda angka untuk data yang sama,
     *    dan itu terbaca seperti bug oleh pengguna.
     *  - `total_manusia` = dihitung dari click_events (sumber kebenaran), karena
     *    pemisahan bot hanya ada di sana. Counter tidak menyimpannya.
     */
    private function ringkasan(int $userId): array
    {
        $tautan = Link::query()->where('user_id', $userId);
        $ids = $tautan->pluck('id');

        $manusia = ClickEvent::query()
            ->whereIn('link_id', $ids)
            ->where('is_bot', false)
            ->count();

        return [
            'total_tautan' => $ids->count(),
            'total_klik' => (int) Link::query()->where('user_id', $userId)->sum('total_clicks'),
            'total_manusia' => $manusia,
        ];
    }
}
