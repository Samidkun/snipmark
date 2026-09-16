<?php

namespace App\Providers;

use App\Services\ClickRecorder;
use App\Services\RollupService;
use App\Support\Analytics\RollupAggregator;
use App\Support\BotDetector;
use App\Support\UserAgentParser;
use App\Support\VisitorHasher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Binding kelas yang TIDAK bisa di-autowire container.
 *
 * Kenapa perlu: `VisitorHasher` butuh `string $secret` dan `ClickRecorder` butuh
 * array CIDR. Container tidak bisa menebak nilai skalar, jadi tanpa binding
 * keduanya melempar `Unresolvable dependency resolving [Parameter #0 [ <required>
 * string $secret ]]` — dan itu muncul sebagai HTTP 500 di jalur redirect.
 *
 * Secret diambil dari APP_KEY (spec §6.5 / ADR-0002). APP_KEY juga yang
 * mengenkripsi sesi, jadi ia sudah wajib ada dan sudah rahasia.
 */
class SupportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(VisitorHasher::class, function (Application $app) {
            $key = (string) $app['config']->get('app.key');

            if ($key === '') {
                // Gagal keras: tanpa kunci, hash pengunjung bisa di-enumerasi
                // offline dari dump database (ADR-0002).
                throw new \RuntimeException(
                    'APP_KEY kosong. VisitorHasher memerlukan kunci untuk HMAC.'
                );
            }

            return new VisitorHasher($key);
        });

        $this->app->singleton(BotDetector::class);
        $this->app->singleton(UserAgentParser::class);

        $this->app->singleton(RollupAggregator::class);
        $this->app->singleton(RollupService::class);

        $this->app->singleton(ClickRecorder::class, function (Application $app) {
            return new ClickRecorder(
                hasher: $app->make(VisitorHasher::class),
                parser: $app->make(UserAgentParser::class),
                detector: $app->make(BotDetector::class),
                datacenterCidrs: (array) $app['config']->get('snipmark.datacenter_cidrs', []),
            );
        });
    }
}
