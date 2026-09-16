<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hasil agregasi SATU HARI untuk SATU link. Inilah tabel yang membuat dashboard
 * tetap cepat saat `click_events` menembus jutaan baris.
 *
 * Jumlah baris = O(jumlah link × jumlah hari), bukan O(jumlah klik).
 *
 * IDEMPOTENSI (spec §5.1): satu-satunya baris per (link_id, date), ditegakkan
 * UNIQUE index. Perintah rollup menghitung ULANG dari `click_events` lalu
 * upsert — jadi menjalankannya sepuluh kali menghasilkan angka yang sama,
 * bukan angka berlipat.
 *
 * Dimensi disimpan sebagai JSON agregat siap-saji (bukan tabel ternormalisasi),
 * supaya dashboard tidak perlu join 4 tabel untuk satu grafik.
 */
class LinkDailyRollup extends Model
{
    protected $fillable = [
        'link_id',
        'date',
        'total',
        'human',
        'bots',
        'unique_visitors',
        'by_device',
        'by_browser',
        'by_os',
        'by_referrer',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'total' => 'integer',
            'human' => 'integer',
            'bots' => 'integer',
            'unique_visitors' => 'integer',
            'by_device' => 'array',
            'by_browser' => 'array',
            'by_os' => 'array',
            'by_referrer' => 'array',
            'computed_at' => 'datetime',
        ];
    }

    /** Maksimum kunci per dimensi; sisanya digabung ke '(other)' (spec §4.4). */
    public const MAX_DIMENSION_KEYS = 25;

    /** Kunci untuk referrer kosong / tidak ada. */
    public const DIRECT_REFERRER = '(direct)';

    public function link(): BelongsTo
    {
        return $this->belongsTo(Link::class);
    }
}
