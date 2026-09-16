#!/usr/bin/env python3
"""Mutasi terkendali untuk membuktikan test BotDetector bisa gagal.

Penting: mutasi HARUS diverifikasi benar-benar mengubah berkas sebelum test
dijalankan. Run sebelumnya "gagal" karena bash mengganti $ua jadi string kosong,
sehingga replace() tidak cocok dan test tetap hijau — itu bukan bukti apa pun.
"""
import shutil
import subprocess
import sys

SRC = "app/Support/BotDetector.php"
KEEP = "/tmp/BotDetector.keep"

MUTATIONS = [
    (
        "buang jalur struktural (lapisan 2)",
        'if ($ua === \'\' || strlen($ua) < self::MIN_UA_LENGTH) {',
        'if (false) {',
    ),
    (
        "TOKEN min. panjang jadi 1 (efektif membuang pemeriksaan pendek)",
        'private const MIN_UA_LENGTH = 15;',
        'private const MIN_UA_LENGTH = 1;',
    ),
    (
        "batasi panjang token generik jadi 3 (boundary 'curl' hilang)",
        'private const BOUNDARY_MAX_LEN = 7;',
        'private const BOUNDARY_MAX_LEN = 3;',
    ),
    (
        " Accept-Language kosong tidak lagi dianggap sinyal",
        "return $acceptLanguage === null || trim($acceptLanguage) === '';",
        "return $acceptLanguage === null;",
    ),
]

shutil.copy(SRC, KEEP)
failures = []

for label, old, new in MUTATIONS:
    t = open(SRC, encoding="utf-8").read()
    if old not in t:
        print(f"SKIP  {label}\n      Pola tidak ditemukan — mutasi TIDAP diterapkan, "
              f"hasilnya tidak bisa dipakai sebagai bukti.")
        failures.append(label + " (pola tidak ditemukan)")
        continue

    open(SRC, "w", encoding="utf-8").write(t.replace(old, new, 1))
    changed = open(SRC, encoding="utf-8").read() != t
    assert changed, f"mutasi {label} tidak mengubah berkas"

    r = subprocess.run(
        ["php", "artisan", "test", "--testsuite=Unit"],
        capture_output=True, text=True,
    )
    out = subprocess.run(
        ["python3", "scripts/tests-summary.py", "--max", "1"],
        input=r.stdout + r.stderr, capture_output=True, text=True,
    ).stdout.strip().splitlines()

    status = out[0] if out else "?"
    red = "result=failed" in status
    print(f"{'RED ✓ ' if red else 'GREEN ✗'} {label}")
    print(f"        {status[:150]}")
    if not red:
        failures.append(label)

    shutil.copy(KEEP, SRC)

r = subprocess.run(["php", "artisan", "test", "--testsuite=Unit"], capture_output=True, text=True)
restored = subprocess.run(["python3", "scripts/tests-summary.py"], input=r.stdout,
                          capture_output=True, text=True).stdout.strip()
print(f"\npemulihan: {restored}")

if failures:
    print(f"\n{len(failures)} MUTASI TIDAK TERDETeksi:")
    for f in failures:
        print("  -", f)
    sys.exit(1)
print("\nSEMUA MUTASI TERDETEKSI — test punya gigi.")
