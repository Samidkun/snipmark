<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuthViewServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\SupportServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    // Binding untuk kelas yang butuh argumen skalar (VisitorHasher, ClickRecorder).
    SupportServiceProvider::class,
    // View + response Fortify: tanpa ini, seluruh route auth melempar 500.
    AuthViewServiceProvider::class,
];
