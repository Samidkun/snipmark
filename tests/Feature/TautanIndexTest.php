<?php

namespace Tests\Feature;

use App\Livewire\TautanIndex;
use App\Models\ClickEvent;
use App\Models\Link;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Komponen daftar tautan.
 *
 * Yang diuji di sini bukan tata letak, melainkan PROPERti yang menentukan apakah
 * data benar: paginasi, isolasi antar-user, validasi, dan bahwa setiap aksi
 * benar-benar mengubah keadaan. Bug #10 dari run sebelumnya ("form selalu ditolak
 * server") adalah kelas kegagalan yang tidak terlihat oleh test HTTP biasa —
 * karena itu aksi diuji lewat komponennya, bukan lewat request tiruan.
 */
final class TautanIndexTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create();
    }

    public function test_menampilkan_tautan_milik_user(): void
    {
        $u = $this->user();
        $link = Link::factory()->create(['user_id' => $u->id, 'destination' => 'https://contoh.test/a']);

        Livewire::actingAs($u)
            ->test(TautanIndex::class)
            ->assertSee('https://contoh.test/a')
            ->assertViewHas('links', fn ($links) => $links->total() === 1)
            ->assertSee((string) $link->code);
    }

    /** Isolasi: tautan user lain TIDAK boleh terlihat. */
    public function test_tidak_menampilkan_tautan_user_lain(): void
    {
        $saya = $this->user();
        $lain = $this->user();
        Link::factory()->create(['user_id' => $lain->id, 'destination' => 'https://rahasia.test/x']);

        Livewire::actingAs($saya)
            ->test(TautanIndex::class)
            ->assertDontSee('https://rahasia.test/x');
    }

    /** Empty state wajib ada (spec §9). */
    public function test_empty_state_muncul_saat_belum_ada_tautan(): void
    {
        Livewire::actingAs($this->user())
            ->test(TautanIndex::class)
            ->assertSee('Belum ada tautan');
    }

    // ---------------------------------------------------------------- membuat

    public function test_membuat_tautan_baru(): void
    {
        $u = $this->user();

        Livewire::actingAs($u)
            ->test(TautanIndex::class)
            ->set('destination', 'https://contoh.test/baru')
            ->call('simpan')
            ->assertHasNoErrors()
            // Flash message muncul setelah redirect berikutnya, jadi yang diuji
            // di sini adalah event notifikasinya (itu kontrak komponennya).
            ->assertDispatched('notifikasi');

        self::assertDatabaseHas('links', [
            'user_id' => $u->id,
            'destination' => 'https://contoh.test/baru',
        ]);

        // Kode dibuat otomatis dan panjangnya sesuai skema.
        self::assertSame(7, strlen(Link::sole()->code));
    }

    /**
     * `user_id` TIDAK PERNAH diambil dari input (spec §10).
     *
     * Diuji dengan MENGIRIM properti `user_id` sebagai data komponen. Livewire 4
     * MENOLAK properti yang tidak dideklarasikan, sehingga percobaan itu gagal
     * di lapisan framework — bukan diam-diam diabaikan. Kedua kemungkinan
     * (ditolak, atau diabaikan tanpa efek) sama-sama aman; yang TIDAK boleh
     * terjadi adalah nilai dari klien ikut tersimpan.
     */
    public function test_user_id_tidak_dapat_dipalsukan_dari_input(): void
    {
        $saya = $this->user();
        $korban = $this->user();

        $komponen = Livewire::actingAs($saya)->test(TautanIndex::class);

        // Livewire 4 menolak properti asing -> itu proteksi struktural.
        try {
            $komponen->set('user_id', $korban->id);
            self::fail('Livewire menerima properti asing user_id');
        } catch (\Throwable $e) {
            self::assertStringContainsString('user_id', $e->getMessage());
        }

        $komponen->set('destination', 'https://contoh.test/x')->call('simpan');

        self::assertSame($saya->id, Link::sole()->user_id,
            'user_id dari klien ikut tersimpan — pelanggaran kepemilikan');
    }

    /** Validasi destination lewat DestinationValidator (javascript:, IP internal). */
    public function test_menolak_destination_berbahaya(): void
    {
        Livewire::actingAs($this->user())
            ->test(TautanIndex::class)
            ->set('destination', 'javascript:alert(1)')
            ->call('simpan')
            ->assertHasErrors('destination');

        self::assertSame(0, Link::count());
    }

    public function test_menolak_destination_kosong(): void
    {
        Livewire::actingAs($this->user())
            ->test(TautanIndex::class)
            ->set('destination', '')
            ->call('simpan')
            ->assertHasErrors('destination');
    }

    public function test_menerima_destination_tanpa_scheme_dan_menambahkannya(): void
    {
        Livewire::actingAs($this->user())
            ->test(TautanIndex::class)
            ->set('destination', 'contoh.test/halaman')
            ->call('simpan')
            ->assertHasNoErrors();

        // Dinormalkan menjadi https:// supaya pengguna tidak perlu mengetiknya.
        self::assertSame('https://contoh.test/halaman', Link::sole()->destination);
    }

    /** Setelah simpan, field dibersihkan dan modal tertutup. */
    public function test_state_dibersihkan_setelah_simpan(): void
    {
        Livewire::actingAs($this->user())
            ->test(TautanIndex::class)
            ->set('destination', 'https://contoh.test/x')
            ->call('simpan')
            ->assertSet('destination', '')
            ->assertSet('modalTerbuka', false);
    }

    // ---------------------------------------------------------------- mengubah

    public function test_mengubah_tautan_milik_sendiri(): void
    {
        $u = $this->user();
        $link = Link::factory()->create(['user_id' => $u->id, 'destination' => 'https://lama.test/x']);

        Livewire::actingAs($u)
            ->test(TautanIndex::class)
            ->call('bukaUbah', $link->id)
            ->set('destination', 'https://baru.test/y')
            ->call('simpan')
            ->assertHasNoErrors();

        self::assertSame('https://baru.test/y', $link->fresh()->destination);
    }

    /** Menyentuh tautan user lain harus DITOLAK, bukan diam-diam diabaikan. */
    public function test_tidak_dapat_mengubah_tautan_user_lain(): void
    {
        $saya = $this->user();
        $lain = $this->user();
        $milikLain = Link::factory()->create(['user_id' => $lain->id, 'destination' => 'https://rahasia.test/x']);

        Livewire::actingAs($saya)
            ->test(TautanIndex::class)
            ->call('bukaUbah', $milikLain->id)
            ->assertForbidden();

        self::assertSame('https://rahasia.test/x', $milikLain->fresh()->destination);
    }

    // ---------------------------------------------------------------- mematikan

    public function test_menonaktifkan_tautan_milik_sendiri(): void
    {
        $u = $this->user();
        $link = Link::factory()->create(['user_id' => $u->id, 'is_active' => true]);

        Livewire::actingAs($u)->test(TautanIndex::class)->call('toggleAktif', $link->id);

        self::assertFalse($link->fresh()->is_active);
    }

    public function test_tidak_dapat_menonaktifkan_tautan_user_lain(): void
    {
        $saya = $this->user();
        $lain = $this->user();
        $link = Link::factory()->create(['user_id' => $lain->id, 'is_active' => true]);

        Livewire::actingAs($saya)
            ->test(TautanIndex::class)
            ->call('toggleAktif', $link->id)
            ->assertForbidden();

        self::assertTrue($link->fresh()->is_active);
    }

    // ----------------------------------------------------------------- menghapus

    public function test_menghapus_tautan_milik_sendiri(): void
    {
        $u = $this->user();
        $link = Link::factory()->create(['user_id' => $u->id]);

        Livewire::actingAs($u)->test(TautanIndex::class)->call('hapus', $link->id);

        self::assertSoftDeleted('links', ['id' => $link->id]);
    }

    public function test_tidak_dapat_menghapus_tautan_user_lain(): void
    {
        $saya = $this->user();
        $lain = $this->user();
        $link = Link::factory()->create(['user_id' => $lain->id]);

        Livewire::actingAs($saya)
            ->test(TautanIndex::class)
            ->call('hapus', $link->id)
            ->assertForbidden();

        self::assertDatabaseHas('links', ['id' => $link->id, 'deleted_at' => null]);
    }

    // ------------------------------------------------------------- pencarian

    public function test_pencarian_menyaring_berdasarkan_destination_dan_kode(): void
    {
        $u = $this->user();
        Link::factory()->create(['user_id' => $u->id, 'destination' => 'https://toko.test/sepatu']);
        Link::factory()->create(['user_id' => $u->id, 'destination' => 'https://blog.test/tulisan']);

        Livewire::actingAs($u)
            ->test(TautanIndex::class)
            ->set('cari', 'sepatu')
            ->assertSee('https://toko.test/sepatu')
            ->assertDontSee('https://blog.test/tulisan');
    }

    /**
     * LIKE wildcard injection: `%` tidak boleh mengembalikan SEMUA baris
     * (kelas bug yang ditemukan reviewer di run sebelumnya).
     */
    public function test_pencarian_menetralkan_wildcard_like(): void
    {
        $u = $this->user();
        Link::factory()->create(['user_id' => $u->id, 'destination' => 'https://satu.test/a']);
        Link::factory()->create(['user_id' => $u->id, 'destination' => 'https://dua.test/b']);

        Livewire::actingAs($u)
            ->test(TautanIndex::class)
            ->set('cari', '%')
            ->assertViewHas('links', fn ($links) => $links->total() === 0,
                'wildcard % tidak dinetralkan — pencarian mengembalikan semua baris');
    }

    /** Pencarian tidak peka huruf besar/kecil (pelajaran bug #11). */
    public function test_pencarian_tidak_peka_huruf_besar_kecil(): void
    {
        $u = $this->user();
        Link::factory()->create(['user_id' => $u->id, 'destination' => 'https://TOKO.test/SEPATU']);

        Livewire::actingAs($u)
            ->test(TautanIndex::class)
            ->set('cari', 'sepatu')
            ->assertViewHas('links', fn ($links) => $links->total() === 1);
    }

    // ------------------------------------------------------------- paginasi

    public function test_paginasi_membatasi_jumlah_baris(): void
    {
        $u = $this->user();
        Link::factory()->count(30)->create(['user_id' => $u->id]);

        Livewire::actingAs($u)
            ->test(TautanIndex::class)
            ->assertViewHas('links', fn ($links) => $links->count() === 25 && $links->total() === 30);
    }

    /** Ringkasan angka di atas daftar harus dari sumber yang sama dengan daftar. */
    public function test_ringkasan_menghitung_total_klik_dan_klik_manusia(): void
    {
        $u = $this->user();
        $link = Link::factory()->create(['user_id' => $u->id, 'total_clicks' => 7]);

        ClickEvent::create([
            'link_id' => $link->id,
            'occurred_at' => now(),
            'occurred_on' => now()->toDateString(),
            'visitor_hash' => str_repeat('a', 64),
            'device_type' => 'desktop',
            'browser_family' => 'chrome',
            'os_family' => 'linux',
            'is_bot' => false,
            'source' => 'web',
        ]);

        Livewire::actingAs($u)
            ->test(TautanIndex::class)
            ->assertViewHas('ringkasan', fn (array $r) => $r['total_tautan'] === 1
                && $r['total_klik'] === 7
                && $r['total_manusia'] === 1);
    }
}
