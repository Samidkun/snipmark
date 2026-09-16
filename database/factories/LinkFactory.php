<?php

namespace Database\Factories;

use App\Models\Link;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Link>
 */
class LinkFactory extends Factory
{
    /**
     * Catatan: tidak memakai $this->fake() — method itu TIDAK ADA di
     * Illuminate\Database\Eloquent\Factories\Factory (diverifikasi di vendor).
     * Helper `fake()` adalah fungsi global, bukan method factory.
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'code' => Str::lower(Str::random(7)),
            'destination' => 'https://example.com/'.Str::uuid(),
            'is_active' => true,
            'total_clicks' => 0,
            'expires_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subMinute()]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
