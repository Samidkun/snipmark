<?php

namespace Tests\Feature;

use App\Livewire\AnalitikTautan;
use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Halaman analitik satu tautan — FOKUS: otorisasi.
 *
 * Sebelumnya tidak ada satu pun test untuk otorisasi halaman ini, padahal ia
 * menerima id dari URL (`/dashboard/{link:code}`). Itu justru permukaan yang
 * paling mungkin jadi IDOR: pengguna cukup menebak/mengganti kode di address bar.
 *
 * Test ini dipasang SEBELUM refactor ke `LinkPolicy`, supaya perilaku yang sudah
 * benar terkunci dan refactor tidak bisa diam-diam merusaknya.
 */
final class AnalitikTautanTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create();
    }

    public function test_pemilik_dapat_membuka_analitik(): void
    {
        $u = $this->user();
        $link = Link::factory()->create(['user_id' => $u->id]);

        Livewire::actingAs($u)
            ->test(AnalitikTautan::class, ['link' => $link])
            ->assertOk();
    }

    /** Inti IDOR: bukan pemilik TIDAK boleh membuka analitik tautan orang lain. */
    public function test_bukan_pemilik_ditolak_403(): void
    {
        $penyusup = $this->user();
        $pemilik = $this->user();
        $link = Link::factory()->create(['user_id' => $pemilik->id]);

        Livewire::actingAs($penyusup)
            ->test(AnalitikTautan::class, ['link' => $link])
            ->assertForbidden();
    }

    /**
     * Lewat RUTE sungguhan (bukan hanya komponen): ini yang membuktikan alur
     * end-to-end menolak, termasuk model binding `{link:code}`.
     */
    public function test_rute_analitik_menolak_bukan_pemilik(): void
    {
        $penyusup = $this->user();
        $pemilik = $this->user();
        $link = Link::factory()->create(['user_id' => $pemilik->id, 'code' => 'rahs123']);

        $this->actingAs($penyusup)
            ->get('/dashboard/rahs123')
            ->assertForbidden();
    }

    public function test_rute_analitik_mengizinkan_pemilik(): void
    {
        $u = $this->user();
        $link = Link::factory()->create(['user_id' => $u->id, 'code' => 'milik12']);

        $this->actingAs($u)
            ->get('/dashboard/milik12')
            ->assertOk();
    }
}
