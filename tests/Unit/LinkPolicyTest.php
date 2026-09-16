<?php

namespace Tests\Unit;

use App\Models\Link;
use App\Models\User;
use App\Policies\LinkPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Kebijakan kepemilikan tautan — SATU sumber kebenaran (spec §10).
 *
 * Sebelumnya pemeriksaan kepemilikan DIDUPLIKASI di tiap komponen
 * (`TautanIndex::tautanMilikSaya()` dan `AnalitikTautan::mount()`). Duplikasi
 * berarti komponen ke-3 bisa lupa memeriksa, dan bug itu tidak akan terlihat
 * sampai ada yang menebak id. Policy memindahkannya ke satu tempat yang diuji.
 *
 * Test ini sengaja menguji POLICY-nya langsung (bukan lewat komponen), supaya
 * kegagalan menunjuk ke aturan yang salah — bukan ke komponen yang memakainya.
 */
final class LinkPolicyTest extends TestCase
{
    use RefreshDatabase;

    private LinkPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new LinkPolicy;
    }

    private function user(): User
    {
        return User::factory()->create();
    }

    public function test_pemilik_dapat_melihat_mengubah_dan_menghapus(): void
    {
        $u = $this->user();
        $link = Link::factory()->create(['user_id' => $u->id]);

        self::assertTrue($this->policy->view($u, $link), 'pemilik harus bisa melihat');
        self::assertTrue($this->policy->update($u, $link), 'pemilik harus bisa mengubah');
        self::assertTrue($this->policy->delete($u, $link), 'pemilik harus bisa menghapus');
    }

    public function test_bukan_pemilik_tidak_dapat_apa_pun(): void
    {
        $penyusup = $this->user();
        $pemilik = $this->user();
        $link = Link::factory()->create(['user_id' => $pemilik->id]);

        self::assertFalse($this->policy->view($penyusup, $link), 'bukan pemilik TIDAK boleh melihat');
        self::assertFalse($this->policy->update($penyusup, $link), 'bukan pemilik TIDAK boleh mengubah');
        self::assertFalse($this->policy->delete($penyusup, $link), 'bukan pemilik TIDAK boleh menghapus');
    }

    /**
     * Guard terhadap penghapusan tak sengaja: kalau policy ini dihapus atau
     * di-rename, komponen yang memakai `Gate::authorize()` akan melempar
     * AuthorizationException untuk SEMUA orang — termasuk pemilik. Test ini
     * memastikan Laravel benar-benar menemukan policy ini untuk model Link.
     */
    public function test_laravel_menemukan_policy_untuk_model_link(): void
    {
        self::assertInstanceOf(
            LinkPolicy::class,
            Gate::getPolicyFor(Link::class),
            'LinkPolicy harus ter-auto-discover untuk App\Models\Link',
        );
    }

    /** Gate::allows harus sejalan dengan policy (bukan dua jalur yang bisa menyimpang). */
    public function test_gate_allows_sejalan_dengan_policy(): void
    {
        $u = $this->user();
        $link = Link::factory()->create(['user_id' => $u->id]);
        $lain = $this->user();

        self::assertTrue(Gate::forUser($u)->allows('view', $link));
        self::assertFalse(Gate::forUser($lain)->allows('view', $link));
    }
}
