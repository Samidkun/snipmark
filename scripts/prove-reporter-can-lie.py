#!/usr/bin/env python3
"""Bukti/reproduksi bug B7: reporter bawaan Laravel 13 bisa melaporkan "passed"
untuk suite yang exit code-nya 1.

KONDISI YANG HARUS DIREPRODUKSI (penting — reproduksi yang salah memberi kesimpulan salah):
  - method test hanya menerima 1 argumen, dataset berisi 4  -> PHPUnit WARNING
  - body method TIDAK menyentuh argumen ekstra itu           -> test tetap LOLOS
  Hasil: semua test passed, tapi ada PHPUnit warning, dan `failOnWarning="true"`
  membuat proses keluar dengan exit 1.

Kalau body-nya menyentuh argumen ekstra, variabelnya undefined -> null ->
assertion GAGAL BENERAN -> JSON dengan benar melaporkan "failed". Itu bukan B7,
dan mutasi seperti itu TIDAK membuktikan apa pun.

Dipakai: python3 scripts/prove-reporter-can-lie.py
Exit 0 = terbukti reporter bisa berbeda dengan exit code (penjaga wajib ada)
Exit 1 = tidak terbukti pada kondisi ini
"""
import shutil
import subprocess
import sys

TEST_FILE = "tests/Unit/UserAgentParserTest.php"
KEEP = "/tmp/UserAgentParserTest.b7"

METHOD = """    #[DataProvider('realUserAgents')]
    public function test_keluaran_selalu_nilai_enum_yang_sah(
        string $ua,
        string $expectedDevice,
        string $expectedBrowser,
        string $expectedOs,
    ): void {
        $p = $this->parser->parse($ua);

        self::assertContains($p->device, ParsedUserAgent::DEVICES, "device tak dikenal: {$p->device}");
        self::assertContains($p->browser, ParsedUserAgent::BROWSERS, "browser tak dikenal: {$p->browser}");
        self::assertContains($p->os, ParsedUserAgent::OSES, "os tak dikenal: {$p->os}");

        self::assertContains($expectedDevice, ParsedUserAgent::DEVICES);
        self::assertContains($expectedBrowser, ParsedUserAgent::BROWSERS);
        self::assertContains($expectedOs, ParsedUserAgent::OSES);
    }"""

# Reproduksi bug asli: signature 1 argumen, body TIDAK menyentuh param ekstra.
BUGGY = """    #[DataProvider('realUserAgents')]
    public function test_keluaran_selalu_nilai_enum_yang_sah(string $ua): void
    {
        $p = $this->parser->parse($ua);

        self::assertContains($p->device, ParsedUserAgent::DEVICES);
        self::assertContains($p->browser, ParsedUserAgent::BROWSERS);
        self::assertContains($p->os, ParsedUserAgent::OSES);
    }"""

shutil.copy(TEST_FILE, KEEP)
t = open(TEST_FILE, encoding="utf-8").read()

if METHOD not in t:
    print("SKIP: method asli tidak ditemukan — bentuknya sudah berubah.")
    sys.exit(2)

open(TEST_FILE, "w", encoding="utf-8").write(t.replace(METHOD, BUGGY, 1))

try:
    r = subprocess.run(["php", "artisan", "test"], capture_output=True, text=True)
    out = r.stdout + r.stderr

    claims_pass = '"result":"passed"' in out
    n_warn = "PHPUnit Warnings" in out or "warning" in out.lower()

    print(f"exit code proses       : {r.returncode}")
    print(f"JSON mengklaim         : {'passed' if claims_pass else 'failed/bukan passed'}")

    # warning asli hanya terlihat kalau reporter dimatikan
    r2 = subprocess.run(["php", "artisan", "test"], capture_output=True, text=True,
                        env={**__import__("os").environ, "PAO_DISABLE": "1"})
    warn_lines = [ln.strip() for ln in (r2.stdout + r2.stderr).splitlines()
                  if "warning" in ln.lower() or "OK, but" in ln]
    print(f"warning terlihat       : {warn_lines[:2]}")

    if claims_pass and r.returncode != 0:
        print()
        print("→ TERBUKTI: reporter melaporkan 'passed' sementara proses keluar != 0.")
        print("  Penjaga exit code di scripts/tests-summary.py WAJIB ada.")
        sys.exit(0)

    print()
    print("→ TIDAK terbukti pada kondisi ini.")
    print("  Jika kelak terbukti tidak lagi berbohong, penjaga exit code bisa ditinjau,")
    print("  tapi JANGAN dihapus tanpa bukti baru: exit code tetap satu-satunya sumber benar.")
    sys.exit(1)
finally:
    shutil.copy(KEEP, TEST_FILE)
    r = subprocess.run(["php", "artisan", "test"], capture_output=True, text=True)
    print(f"\npemulihan: exit={r.returncode} ({'hijau' if r.returncode == 0 else 'MERAH'})")
