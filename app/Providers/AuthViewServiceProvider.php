<?php

namespace App\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\LoginViewResponse;
use Laravel\Fortify\Contracts\LogoutResponse;
use Laravel\Fortify\Contracts\RegisterViewResponse;
use Laravel\Fortify\Contracts\RequestPasswordResetLinkViewResponse;
use Laravel\Fortify\Contracts\ResetPasswordViewResponse;
use Laravel\Fortify\Contracts\TwoFactorChallengeViewResponse;
use Laravel\Fortify\Contracts\VerifyEmailViewResponse;

/**
 * Fortify hanya menyediakan LOGIKA autentikasi; tampilannya harus disediakan
 * aplikasi. Tanpa binding di bawah, setiap route auth melempar
 * `Target [LoginViewResponse] is not instantiable` — HTTP 500, bukan halaman.
 *
 * Keputusan: memakai Fortify (bukan Breeze) karena Breeze adalah installer
 * DESTRUKTIF — di run sebelumnya ia menimpa routes/web.php, menurunkan Tailwind
 * 4 ke 3, dan menduplikasi entri package.json sambil melaporkan "successfully".
 * Fortify hanya paket Composer dan tidak menyentuh aset frontend.
 */
class AuthViewServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LoginViewResponse::class, fn () => new class implements LoginViewResponse
        {
            public function toResponse($request)
            {
                return response()->view('auth.login');
            }
        });

        $this->app->bind(RegisterViewResponse::class, fn () => new class implements RegisterViewResponse
        {
            public function toResponse($request)
            {
                return response()->view('auth.register');
            }
        });

        $this->app->bind(RequestPasswordResetLinkViewResponse::class, fn () => new class implements RequestPasswordResetLinkViewResponse
        {
            public function toResponse($request)
            {
                return response()->view('auth.lupa-sandi');
            }
        });

        $this->app->bind(ResetPasswordViewResponse::class, fn () => new class implements ResetPasswordViewResponse
        {
            public function toResponse($request)
            {
                return response()->view('auth.reset-sandi', ['request' => $request]);
            }
        });

        $this->app->bind(VerifyEmailViewResponse::class, fn () => new class implements VerifyEmailViewResponse
        {
            public function toResponse($request)
            {
                return response()->view('auth.verifikasi-email');
            }
        });

        $this->app->bind(TwoFactorChallengeViewResponse::class, fn () => new class implements TwoFactorChallengeViewResponse
        {
            public function toResponse($request)
            {
                return response()->view('auth.login');
            }
        });

        // Setelah login: kembali ke halaman yang dituju, atau ke dasbor.
        $this->app->bind(LoginResponse::class, fn () => new class implements LoginResponse
        {
            public function toResponse($request)
            {
                return $request->wantsJson()
                    ? response()->json(['two_factor' => false])
                    : redirect()->intended(route('dashboard'));
            }
        });

        $this->app->bind(LogoutResponse::class, fn () => new class implements LogoutResponse
        {
            public function toResponse($request)
            {
                return redirect()->route('landing');
            }
        });
    }

    /**
     * Provider ini HANYA mengurus binding respons Fortify.
     *
     * CSP nonce pernah dibuat di sini dan itu salah: `boot()` berjalan SEKALI per
     * aplikasi, bukan per request, sehingga nonce tidak unik antar request dan
     * nilainya dibuat pada waktu yang berbeda dari render HTML — akibatnya header
     * CSP dan atribut `nonce` di HTML berbeda, dan browser memblokir seluruh
     * script inline Livewire (bug #19). Nonce kini dibuat di SecurityHeaders,
     * sekali per request, sebelum render.
     */
    public function boot(): void
    {
        //
    }
}
