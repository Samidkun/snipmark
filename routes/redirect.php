<?php

use App\Http\Controllers\RedirectController;
use Illuminate\Support\Facades\Route;

/*
 * Jalur redirect publik. Tidak memakai middleware 'web' (tidak perlu sesi/CSRF
 * untuk membaca tautan) dan dipisah dari web.php supaya rute publik yang panas
 * ini tetap terbaca.
 *
 * Where regex membatasi panjang & karakter kode -> permintaan sampah tidak
 * sempat menyentuh database.
 */
Route::get('/c/{code}', RedirectController::class)
    ->where('code', '[A-Za-z0-9]{1,16}')
    ->name('redirect.short');
