<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\SupportServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    // Binding untuk kelas yang butuh argumen skalar (VisitorHasher, ClickRecorder).
    SupportServiceProvider::class,
];
