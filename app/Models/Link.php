<?php

namespace App\Models;

use Database\Factories\LinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Satu tautan pendek yang dimiliki seorang user.
 *
 * Catatan desain:
 *  - `total_clicks` adalah counter DENORMALIZED. Ia ada supaya daftar link tidak
 *    perlu COUNT(*) atas click_events untuk setiap baris. Karena denormalisasi,
 *    ia bisa melenceng — dan itulah alasan `snipmark:rollup:reconcile` ada (spec §5.2).
 *    Selalu dinaikkan lewat `increment()`, tidak pernah read-then-write.
 *  - `destination` TIDAK divalidasi di model. Validasi ada di FormRequest
 *    (satu trust boundary), dan model hanya menyimpan apa yang sudah lolos.
 */
class Link extends Model
{
    /** @use HasFactory<LinkFactory> */
    use HasFactory, SoftDeletes;

    /** Panjang kode. 62^7 ≈ 3,5×10^12 ruang kunci (spec §4.1). */
    public const CODE_LENGTH = 7;

    /** Batas panjang destination yang divalidasi (spec §4.1). */
    public const MAX_DESTINATION_LENGTH = 2048;

    protected $fillable = [
        'user_id',
        'code',
        'destination',
        'is_active',
        'expires_at',
    ];

    /**
     * Membatalkan cache jalur redirect.
     *
     * Tanpa ini, mengganti destination TIDAK berlaku sampai TTL kedaluwarsa —
     * dan pada tabel yang isinya URL, "perubahan tidak terasa" itu bug.
     */
    protected static function booted(): void
    {
        static::saved(fn (self $link) => self::flushCodeCache($link->code));
        static::deleted(fn (self $link) => self::flushCodeCache($link->code));
        static::restored(fn (self $link) => self::flushCodeCache($link->code));
    }

    public static function flushCodeCache(string $code): void
    {
        Cache::forget('snip:url:'.$code);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'total_clicks' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function clickEvents(): HasMany
    {
        return $this->hasMany(ClickEvent::class);
    }

    public function dailyRollups(): HasMany
    {
        return $this->hasMany(LinkDailyRollup::class);
    }

    /** URL pendek yang bisa dibagikan. */
    public function shortUrl(): string
    {
        return url('/c/'.$this->code);
    }

    /**
     * Link bisa dibuka hanya jika aktif DAN belum kedaluwarsa.
     *
     * Batas: `expires_at` null berarti tidak pernah kedaluwarsa.
     * Perbandingan memakai waktu sekarang di zona aplikasi (Asia/Jakarta).
     */
    public function isReachable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /**
     * Membuat Link dengan kode unik yang AMAN TERHADAP KONKURENSI (spec §4.1).
     *
     * Kenapa bukan "cek exists() dulu, baru insert": pola itu balah terhadap
     * konkurensi — dua permintaan sama-sama membaca "kosong", keduanya lolos,
     * lalu salah satu menabrak UNIQUE index dan menghasilkan HTTP 500.
     * Yang menjadi penjaga sesungguhnya adalah UNIQUE index itu sendiri; di sini
     * kita MENABRAKNYA dengan sengaja dan menyerap tabrakannya lewat retry.
     *
     * @param  array<string, mixed>  $attributes  tanpa `code`
     *
     * @throws \RuntimeException jika percobaan habis (ruang kunci 62^7, ini Practically mustahil)
     */
    public static function createWithUniqueCode(array $attributes, int $maxAttempts = 5): self
    {
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                /** @var self */
                $link = static::query()->create([
                    ...$attributes,
                    'code' => static::randomCode(),
                ]);

                return $link;
            } catch (QueryException $e) {
                // 23000 = integrity constraint violation. Kalau yang ditabrak bukan
                // `code`, retry tidak akan menolong: lempar apa adanya.
                if (! static::isCodeCollision($e)) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException(
            "Gagal membuat kode unik setelah {$maxAttempts} percobaan."
        );
    }

    /** Kode acak. Tabrakan TIDAK diperiksa di sini — index yang menjaga. */
    public static function randomCode(): string
    {
        return Str::lower(Str::random(self::CODE_LENGTH));
    }

    /**
     * Membedakan "kode ini sudah dipakai" dari pelanggaran constraint lain.
     * Memeriksa pesan/error code, bukan hanya 23000, supaya duplicate pada
     * kolom lain tidak memicu retry buta.
     */
    protected static function isCodeCollision(QueryException $e): bool
    {
        // MariaDB: 1062 "Duplicate entry". SQLSTATE-nya 23000 (integrity constraint).
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        if ($driverCode !== self::DUPLICATE_ENTRY_CODE) {
            return false;
        }

        // Duplicate pada kolom LAIN tidak boleh memicu retry buta — retry hanya
        // masuk akal bila yang ditabrak adalah kode link.
        $message = $e->getMessage();

        return str_contains($message, 'links_code_unique')
            || str_contains($message, "'code'")
            || str_contains($message, '.code');
    }

    protected const DUPLICATE_ENTRY_CODE = 1062;
}
