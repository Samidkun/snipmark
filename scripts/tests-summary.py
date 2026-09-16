#!/usr/bin/env python3
"""Ringkas output `php artisan test` (JSON) jadi beberapa baris — DAN jaga exit code.

Kenapa ada: runner di project ini mencetak JSON lengkap dengan SETIAP kegagalan.
Membacanya mentah membanjiri konteks dan menyembunyikan yang penting.

KENAPA EXIT CODE DIJAGA (bug B7):
Reporter bawaan Laravel 13 (`laravel/pao`, "agent-optimized output") mencetak
`"result":"passed"` walaupun PHPUnit keluar dengan exit code 1 — yang terjadi bila
ada PHPUnit warning, dan `failOnWarning="true"` membuat warning itu FATAL.
Membaca JSON saja akan melaporkan "hijau" untuk suite yang gate-nya merah.
Ini kelas yang sama dengan gate yang melaporkan sukses tanpa benar-benar lolos.

Jadi skrip ini menerima exit code proses asli sebagai argumen dan MENOLAK
mengklaim hijau bila exit code bukan 0. JSON hanya dipakai untuk meringkas.

Pakai:
    php artisan test > /tmp/out.json 2>&1; python3 scripts/tests-summary.py --exit $? < /tmp/out.json
    (atau lihat scripts/run-tests.sh yang menangani ini otomatis)
"""
import json
import sys
from collections import Counter

raw = sys.stdin.read()

exit_code = None
if "--exit" in sys.argv:
    exit_code = int(sys.argv[sys.argv.index("--exit") + 1])

max_msgs = 5
if "--max" in sys.argv:
    max_msgs = int(sys.argv[sys.argv.index("--max") + 1])

start = raw.find("{")
if start == -1:
    print("BUKAN JSON — output mentah (ekor 3000 char):")
    print(raw[-3000:])
    sys.exit(2)

try:
    data = json.loads(raw[start:])
except json.JSONDecodeError:
    depth, end = 0, None
    for i, ch in enumerate(raw[start:], start):
        if ch == "{":
            depth += 1
        elif ch == "}":
            depth -= 1
            if depth == 0:
                end = i + 1
                break
    try:
        data = json.loads(raw[start:end]) if end else {}
    except json.JSONDecodeError:
        print("JSON rusak — ekor output:")
        print(raw[-2000:])
        sys.exit(2)

claimed = data.get("result", "?")
tests = data.get("tests", 0)
passed = data.get("passed", 0)
assertions = data.get("assertions", 0)
ms = data.get("duration_ms", 0)
errors = data.get("errors", 0)
failures = data.get("failures", 0)

print(f"result={claimed}  tests={tests}  passed={passed}  errors={errors}  "
      f"failures={failures}  assertions={assertions}  {ms}ms")

details = data.get("error_details") or data.get("failure_details") or []
if details:
    counts = Counter(d.get("message", "?") for d in details)
    print(f"--- {len(details)} kegagalan, {len(counts)} pesan unik ---")
    for msg, n in counts.most_common(max_msgs):
        first = next(d for d in details if d.get("message") == msg)
        print(f"  [{n}x] {msg[:200]}")
        print(f"        contoh: {first.get('test', '?')[:100]}  (baris {first.get('line', '?')})")
    if len(counts) > max_msgs:
        print(f"  ... dan {len(counts) - max_msgs} pesan unik lain")

# --- gerbang kejujuran: exit code mengalahkan klaim JSON ---
if exit_code is not None:
    if exit_code != 0 and claimed == "passed":
        print()
        print("=" * 68)
        print("  KLaim JSON BERBEDA DENGAN EXIT CODE")
        print(f"  JSON bilang '{claimed}' tapi proses keluar dengan {exit_code}.")
        print("  Penyebab paling umum: PHPUnit warning (failOnWarning=true).")
        print("  Jalankan `./vendor/bin/phpunit` LANGSUNG dan baca bagian warning-nya.")
        print("=" * 68)
        sys.exit(1)
    if exit_code != 0:
        sys.exit(1)

sys.exit(0 if claimed == "passed" else 1)
