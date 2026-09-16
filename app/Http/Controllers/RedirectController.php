<?php

namespace App\Http\Controllers;

use App\Models\Link;
use App\Services\ClickRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Jalur redirect /c/{code} — endpoint paling panas aplikasi ini (spec §7).
 *
 * Urutan kerja:
 *   1. baca mapping kode -> [destination, link_id] dari cache (nol query DB saat hit)
 *   2. miss: SELECT lewat UNIQUE index, lalu isi cache
 *   3. increment() counter secara ATOMIK (bukan read-then-write)
 *   4. catat event (sinkron; lihat docs/benchmarks.md untuk alasannya)
 *   5. 302 ke destination
 *
 * Kenapa 302 dan bukan 301 (ADR-0006): 301 di-cache browser permanen, sehingga
 * mengganti destination atau mematikan link tidak akan pernah menjangkau
 * pengunjung lama. Kerelannya: sedikit lebih banyak request. Diterima sadar.
 *
 * Rute memakai prefix /c/ (ADR-0005) supaya kode link tidak pernah bertabrakan
 * dengan rute aplikasi dan seluruh kelas bug "reserved word" hilang.
 */
class RedirectController extends Controller
{
    /** Umur cache pemetaan kode (detik). */
    private const CACHE_TTL_SECONDS = 3600;

    public function __construct(private readonly ClickRecorder $recorder) {}

    public function __invoke(Request $request, string $code): Response
    {
        $target = $this->resolve($code);

        if ($target === null) {
            abort(404);
        }

        if ($target['gone'] === true) {
            // 410: tautannya memang pernah ada dan sekarang kedaluwarsa.
            abort(410);
        }

        // Counter dinaikkan lebih dulu, sebelum pencatatan: kalau pencatatan.event
        // gagal, angka besar tetap tidak kehilangan hit (rollup/reconcile yang akan
        // melaporkan selisih, bukan jalur ini yang menggagalkan redirect).
        Link::query()->whereKey($target['id'])->increment('total_clicks');

        // Kegagalan pencatatan TIDAK boleh menggagalkan redirect. Linktetap dibuka;
        // yang hilang hanya satu baris analytics.
        try {
            $this->recorder->record($request, Link::query()->whereKey($target['id'])->firstOrFail());
        } catch (\Throwable $e) {
            report($e);
        }

        return redirect()->away($target['destination'], 302);
    }

    /**
     * @return array{id: int, destination: string, gone: bool}|null
     */
    private function resolve(string $code): ?array
    {
        return Cache::remember(
            'snip:url:'.$code,
            self::CACHE_TTL_SECONDS,
            fn () => $this->lookup($code),
        );
    }

    /** @return array{id: int, destination: string, gone: bool}|null */
    private function lookup(string $code): ?array
    {
        $link = Link::query()
            ->withTrashed()
            ->where('code', $code)
            ->first(['id', 'destination', 'is_active', 'expires_at', 'deleted_at']);

        if ($link === null) {
            return null;
        }

        // Baris ter-soft-delete atau nonaktif -> 404 (bukan 410: tautan tidak
        // "pernah ada lalu mati", melainkan memang tidak tersedia).
        if ($link->deleted_at !== null || ! $link->is_active) {
            return null;
        }

        if ($link->expires_at !== null && $link->expires_at->isPast()) {
            return ['id' => $link->id, 'destination' => $link->destination, 'gone' => true];
        }

        return ['id' => $link->id, 'destination' => $link->destination, 'gone' => false];
    }
}
