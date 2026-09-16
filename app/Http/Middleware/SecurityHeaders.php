<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header keamanan (spec §10).
 *
 * CSP dengan NONCE, bukan `'unsafe-inline'`. Ini penting dan bukan formalitas:
 * Livewire menyuntikkan script inline; bila `script-src 'self'` dipakai tanpa
 * nonce, browser memblokirnya dan SELURUH interaksi mati sementara halaman tetap
 * tampak normal. Bug #19 dari run sebelumnya persis ini, dan ia hanya terlihat
 * saat aplikasi dijalankan dengan konfigurasi produksi di browser nyata.
 *
 * NONCE DIBUAT DI SINI, SEKALI PER REQUEST — dan itu bukan pilihan gaya.
 *
 * Sebelumnya nonce dibuat di ServiceProvider::boot(). Provider di-boot SEKALI per
 * aplikasi, sementara nonce harus unik per request (kalau tidak, CSP-nya bisa
 * di-replay). Yang lebih parah: nilai itu dibuat pada waktu yang berbeda dari
 * saat HTML dirender, sehingga header CSP dan atribut `nonce="..."` di HTML
 * memuat nilai BERBEDA. Gejalanya sunyi — halaman tampak normal, tetapi browser
 * memblokir setiap script inline Livewire dan tidak satu pun tombol bekerja.
 *
 * Urutan yang benar, dan alasan kode ini berbentuk demikian:
 *   1. buat nonce
 *   2. simpan ke container + Vite SEBELUM $next()  -> view memakai nilai ini
 *   3. $next($request)                             -> HTML dirender dengan nonce #1
 *   4. pasang header CSP memakai nilai yang SAMA   -> header cocok dengan HTML
 *
 * HSTS hanya dikirim pada request HTTPS. Mengirimnya lewat HTTP berarti browser
 * memaksa HTTPS pada host yang mungkin belum punya sertifikat — dan pada
 * pengembangan lokal itu membuat situs tidak bisa diakses sama sekali.
 */
class SecurityHeaders
{
    /** Kunci container untuk nonce request ini. */
    public const NONCE_KEY = 'csp-nonce';

    public function handle(Request $request, Closure $next): Response
    {
        // (1) Satu nonce per request. Dibuat SEBELUM render agar view bisa
        // memakainya lewat Vite::useCspNonce() dan atribut nonce di blade.
        $nonce = Str::random(24);

        // (2) Daftarkan lebih dulu: apa pun yang dirender di dalam $next()
        // akan membaca nilai yang sama dengan yang masuk ke header di bawah.
        app()->instance(self::NONCE_KEY, $nonce);
        Vite::useCspNonce($nonce);

        // (3) Render response (view Livewire membaca nonce di atas).
        $response = $next($request);

        // (4) Header memakai nonce yang SAMA — bukan nilai baru.
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            // `style-src 'unsafe-inline'` adalah konsesi TERBATAS dan disadari:
            // Livewire dan progress bar menyuntikkan blok <style> saat runtime
            // dan tidak menyediakan hook nonce. CSS inline tidak dapat
            // mengeksekusi kode, sehingga risikonya jauh lebih kecil daripada
            // script inline — tetapi konsesi ini didokumentasikan, bukan
            // disembunyikan.
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "object-src 'none'",
        ]);

        $response->headers->set('Content-Security-Policy', $csp);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
