<?php

namespace Tests\Feature;

use App\Support\BotVerdict;
use App\Support\ParsedUserAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Test SKEMA. Bukan test indah-indah: ini menjaga hal-hal yang kalau salah,
 * baru ketahuan saat query produksi lambat atau saat insert ditolak MariaDB.
 *
 * Yang dijaga:
 *  - index yang membuat agregasi rollup memakai index (bukan filesort)
 *  - enum yang sinkron dengan daftar nilai di PHP
 *  - ketiadaan kolom IP mentah (kontrak privasi spec §6.5 / ADR-0002)
 *  - constraint UNIQUE yang jadi penjaga sebenarnya terhadap tabrakan kode
 */
final class SchemaTest extends TestCase
{
    use RefreshDatabase;

    private function columns(string $table): array
    {
        return array_map(
            fn (\stdClass $r) => $r->Field,
            DB::select("SHOW COLUMNS FROM `{$table}`")
        );
    }

    private function indexedColumns(string $table): array
    {
        $out = [];
        foreach (DB::select("SHOW INDEX FROM `{$table}`") as $row) {
            $out[$row->Key_name][$row->Seq_in_index] = $row->Column_name;
        }
        ksort($out);

        return array_map(fn (array $m) => array_values($m), $out);
    }

    public function test_tabel_inti_ada(): void
    {
        foreach (['links', 'click_events', 'link_daily_rollups'] as $t) {
            self::assertTrue(
                DB::select("SHOW TABLES LIKE '{$t}'") !== [],
                "tabel {$t} tidak ada"
            );
        }
    }

    /** S9: IP mentah TIDAK PERNAH disimpan. */
    public function test_click_events_tidak_punya_kolom_ip(): void
    {
        $cols = $this->columns('click_events');

        foreach (['ip', 'ip_address', 'visitor_ip', 'remote_addr'] as $forbidden) {
            self::assertNotContains(
                $forbidden, $cols,
                "kolom {$forbidden} menyimpan IP mentah — melanggar kontrak privasi spec §6.5"
            );
        }

        self::assertContains('visitor_hash', $cols);
    }

    /** Keputusan user (S14): UA mentah disimpan supaya bisa di-reparse. */
    public function test_click_events_menyimpan_user_agent_mentah(): void
    {
        $cols = $this->columns('click_events');

        self::assertContains('user_agent', $cols,
            'UA mentah harus tersimpan; tanpa itu bug parser tidak bisa dipulihkan (§4.4)');

        $type = collect(DB::select("SHOW COLUMNS FROM `click_events` LIKE 'user_agent'"))->first();
        self::assertStringContainsString('varchar(512)', strtolower($type->Type),
            'UA mentah dibatasi 512 char (spesifikasi, bukan bebas tak terbatas)');
    }

    /**
     * AMANDEMEN SKEMA #1: `occurred_on` (tanggal versi zona analitik) WAJIB ada
     * dan WAJIB ter-index.
     *
     * Tanpa kolom ini, rollup harus `GROUP BY DATE(occurred_at)`. Fungsi di atas
     * kolom membuat index tidak bisa dipakai → full scan + filesort pada tabel
     * yang ditumbuhkan untuk jutaan baris. Ini penyebab dashboard lambat yang
     * tidak kelihatan di database kecil.
     */
    public function test_click_events_punya_kolom_tanggal_terindeks(): void
    {
        $cols = $this->columns('click_events');
        self::assertContains('occurred_on', $cols,
            'kolom tanggal tersimpan diperlukan supaya agregasi memakai index, bukan DATE(occurred_at)');

        $idx = $this->indexedColumns('click_events');
        self::assertContains(
            ['link_id', 'occurred_on'], $idx,
            'index komposit (link_id, occurred_on) wajib ada untuk rollup harian'
        );
    }

    /** Index jalur redirect: lookup by code lewat UNIQUE index. */
    public function test_links_code_punya_unique_index(): void
    {
        $idx = $this->indexedColumns('links');

        self::assertContains(['code'], $idx, 'kode link harus dijaga UNIQUE index (penjaga tabrakan sesungguhnya)');
    }

    /** Rollup harus idempoten: UNIQUE(link, tanggal) adalah yang menegakkannya. */
    public function test_rollup_punya_unique_link_dan_tanggal(): void
    {
        $idx = $this->indexedColumns('link_daily_rollups');

        self::assertContains(
            ['link_id', 'date'], $idx,
            'UNIQUE(link_id, date) wajib — tanpanya rollup bisa menghasilkan baris ganda (S4)'
        );
    }

    /** Enum harus memuat persis kategori yang bisa dihasilkan BotDetector. */
    public function test_enum_bot_category_sinkron_dengan_kode(): void
    {
        $row = collect(DB::select("SHOW COLUMNS FROM `click_events` LIKE 'bot_category'"))->first();
        self::assertNotNull($row, 'kolom bot_category tidak ada');

        preg_match("/enum\(([^)]*)\)/i", $row->Type, $m);
        self::assertNotEmpty($m, "tipe bot_category bukan enum: {$row->Type}");

        $dbValues = array_map(fn (string $v) => trim($v, "'\""), explode(',', $m[1]));

        self::assertEqualsCanonicalizing(
            BotVerdict::categories(), $dbValues,
            'enum MariaDB dan BotVerdict::categories() berbeda — selisihnya jadi error insert saat produksi'
        );
    }

    public function test_enum_device_type_sinkron_dengan_parser(): void
    {
        $row = collect(DB::select("SHOW COLUMNS FROM `click_events` LIKE 'device_type'"))->first();
        self::assertNotNull($row);

        preg_match("/enum\(([^)]*)\)/i", $row->Type, $m);
        $dbValues = array_map(fn (string $v) => trim($v, "'\""), explode(',', $m[1]));

        self::assertNotEmpty($dbValues);
        foreach ($dbValues as $v) {
            self::assertContains($v, ParsedUserAgent::DEVICES);
        }
    }

    /** total_clicks harus BIGINT UNSIGNED — INT biasa penuh di ~2,1 miliar. */
    public function test_counter_tidak_akan_overflow_cepat(): void
    {
        $row = collect(DB::select("SHOW COLUMNS FROM `links` LIKE 'total_clicks'"))->first();
        self::assertNotNull($row, 'total_clicks tidak ada (kolom denormalized, spec §4.1)');

        $type = strtolower($row->Type);
        self::assertStringContainsString('bigint', $type, "tipe {$type} berisiko overflow");
        self::assertStringContainsString('unsigned', $type);
    }

    /** Kontribusi click_events harus ikut hilang saat link dihapus permanen. */
    public function test_foreign_key_menggunakan_cascade(): void
    {
        $rows = DB::select(
            "SELECT CONSTRAINT_NAME, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'click_events'"
        );

        self::assertNotEmpty($rows, 'click_events tidak punya foreign key ke links');

        foreach ($rows as $r) {
            self::assertSame('CASCADE', strtoupper($r->DELETE_RULE),
                "FK {$r->CONSTRAINT_NAME} bukan CASCADE — klik yatim akan menumpuk");
        }
    }
}
