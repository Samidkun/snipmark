<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Laporan hasil `snipmark:rollup:reconcile`.
 *
 * `drifts` adalah daftar ketidaksesuaian yang DITEMUKAN (baik sudah diperbaiki
 * maupun belum). Dipisahkan dari `fixed` supaya perintah bisa melaporkan
 * "ada 3 drift, semuanya diperbaiki" alih-alih "bersih" — laporan yang menyembunyikan
 * masalah lebih berbahaya daripada masalahnya.
 */
final readonly class ReconcileReport
{
    /**
     * @param  list<array{type: string, link_id: int, date: ?string, recorded: int, actual: int}>  $drifts
     */
    public function __construct(
        public array $drifts = [],
        public int $fixed = 0,
    ) {}

    public function isClean(): bool
    {
        return $this->drifts === [];
    }

    public function driftCount(): int
    {
        return count($this->drifts);
    }

    /**
     * Baris tabel untuk output terminal. Dipisahkan dari service supaya logika
     * data tidak bercampur dengan format tampilan.
     *
     * @return list<array<string, string>>
     */
    public function table(): array
    {
        return array_map(fn (array $d) => [
            'jenis' => $d['type'],
            'link' => (string) $d['link_id'],
            'tanggal' => $d['date'] ?? '-',
            'tercatat' => (string) $d['recorded'],
            'sebenarnya' => (string) $d['actual'],
            'selisih' => (string) ($d['recorded'] - $d['actual']),
        ], $this->drifts);
    }
}
