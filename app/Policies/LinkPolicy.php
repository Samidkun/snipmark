<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Link;
use App\Models\User;

/**
 * Satu sumber kebenaran untuk kepemilikan tautan (spec §10).
 *
 * KENAPA INI ADA
 *
 * Sebelumnya pemeriksaan kepemilikan diduplikasi di setiap komponen:
 * `TautanIndex::tautanMilikSaya()` dan `AnalitikTautan::mount()` masing-masing
 * menulis `$link->user_id !== auth()->id()`. Fungsinya benar, tetapi duplikasi
 * adalah utang: komponen ke-3 yang lupa memeriksa akan membuka IDOR, dan tidak
 * ada test yang gagal ketika itu terjadi — bug baru muncul saat ada yang menebak
 * id. Policy memindahkan aturan itu ke satu tempat yang bisa diuji sendiri.
 *
 * ATURAN: satu-satunya yang boleh menyentuh tautan adalah PEMILIKNYA.
 * Tidak ada role, tidak ada admin, tidak ada berbagi (spec §10) — karena itu
 * `spatie/laravel-permission` sengaja tidak dipasang (YAGNI, spec §3).
 *
 * `user_id` TIDAK PERNAH diambil dari request; ia diisi dari sesi saat pembuatan
 * (lihat `TautanIndex::simpan()`). Policy ini hanya MEMERIKSA, tidak menetapkan.
 *
 * Laravel 11+ menemukan policy ini otomatis lewat konvensi
 * `App\Models\Link` -> `App\Policies\LinkPolicy`; tidak perlu registrasi manual.
 */
final class LinkPolicy
{
    /** Melihat daftar/detail tautan. */
    public function view(User $user, Link $link): bool
    {
        return $this->miliknya($user, $link);
    }

    /** Mengubah tujuan, menyalakan/mematikan, atau apa pun yang mengubah baris. */
    public function update(User $user, Link $link): bool
    {
        return $this->miliknya($user, $link);
    }

    /** Menghapus (soft delete). */
    public function delete(User $user, Link $link): bool
    {
        return $this->miliknya($user, $link);
    }

    /** Membuat tautan baru tidak butuh kepemilikan — hanya butuh login. */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Perbandingan identitas pemilik.
     *
     * `$link->user_id` bertipe int (dari DB) dan `$user->id` juga int, jadi
     * perbandingan ketat aman. Baris tanpa pemilik (user_id null) TIDAK pernah
     * cocok dengan siapa pun — termasuk bila ada user dengan id null.
     */
    private function miliknya(User $user, Link $link): bool
    {
        return $link->user_id !== null && $link->user_id === $user->id;
    }
}
