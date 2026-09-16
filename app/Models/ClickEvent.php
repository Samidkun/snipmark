<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu kunjungan pada satu link. APPEND-ONLY: tidak pernah di-UPDATE.
 *
 * Kontrak privasi (spec §6.5 / ADR-0002):
 *  - TIDAK ada kolom IP. Identitas pengunjung hanya `visitor_hash`,
 *    yaitu HMAC(ip|tanggal, APP_KEY) yang berotasi harian.
 *  - `user_agent` mentah DISIMPAN (keputusan user, spec §4.4) supaya bila parser
 *    diperbaiki, seluruh riwayat bisa diparse ulang. Kolom ini tidak pernah
 *    ditampilkan di UI dan dibatasi 512 karakter.
 *
 * `occurred_on` adalah tanggal versi zona analitik (Asia/Jakarta), tersimpan dan
 * ter-index. Ini yang membuat agregasi rollup memakai index alih-alih
 * `GROUP BY DATE(occurred_at)` yang memaksa full scan + filesort.
 */
class ClickEvent extends Model
{
    /** Tabel ini append-only; tidak ada updated_at. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'link_id',
        'occurred_at',
        'occurred_on',
        'visitor_hash',
        'referrer_host',
        'device_type',
        'browser_family',
        'os_family',
        'is_bot',
        'bot_name',
        'bot_category',
        'source',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'occurred_on' => 'date',
            'is_bot' => 'boolean',
        ];
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(Link::class);
    }

    /**
     * Batas panjang UA di kolom adalah 512. UA yang lebih panjang itu NYATA
     * (browser dengan banyak ekstensi), dan tanpa pemotongan ini MariaDB menolak
     * insert dengan "Data too long for column" -> redirect gagal dengan HTTP 500.
     *
     * Ditemukan oleh test, bukan oleh review. Memotong di sini (satu tempat)
     * lebih baik daripada berharap setiap pemanggil ingat.
     */
    public const MAX_USER_AGENT_LENGTH = 512;

    protected function userAgent(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value === null
                ? null
                : mb_substr($value, 0, self::MAX_USER_AGENT_LENGTH),
        );
    }
}
