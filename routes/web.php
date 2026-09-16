<?php

use App\Livewire\AnalitikTautan;
use App\Livewire\TautanIndex;
use Illuminate\Support\Facades\Route;

/*
 * Landing page. Sengaja ringan dan tanpa autentikasi — ini yang pertama dilihat
 * pengunjung (termasuk perekrut), jadi ia harus bisa dijelaskan dalam satu layar.
 */
Route::get('/', function () {
    return view('landing');
})->name('landing');

Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', TautanIndex::class)->name('dashboard');

    // Model binding memakai `code` supaya URL-nya enak dibaca dan tidak
    // membocorkan id berurutan.
    Route::get('/dashboard/{link:code}', AnalitikTautan::class)
        ->name('tautan.analitik');
});
