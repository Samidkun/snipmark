#!/usr/bin/env python3
"""Ringkas output `php artisan test` (JSON) jadi beberapa baris.

Kenapa ada: runner di project ini mencetak JSON lengkap dengan SETIAP kegagalan
(95 test gagal = puluhan KB). Membacanya mentah membanjiri konteks dan menyembunyikan
informasi yang penting: berapa yang lolos, berapa gagal, dan apa pesan unik-nya.

Pakai:
    php artisan test 2>&1 | python3 scripts/tests-summary.py
    php artisan test --testsuite=Unit 2>&1 | python3 scripts/tests-summary.py --max 5
"""
import json
import sys
from collections import Counter

raw = sys.stdin.read()
max_msgs = 5
if "--max" in sys.argv:
    max_msgs = int(sys.argv[sys.argv.index("--max") + 1])

start = raw.find("{")
if start == -1:
    print("BUKAN JSON — output mentah:")
    print(raw[-3000:])
    sys.exit(2)

try:
    data = json.loads(raw[start:])
except json.JSONDecodeError:
    # cari objek JSON terakhir yang valid
    depth, end = 0, None
    for i, ch in enumerate(raw[start:], start):
        if ch == "{":
            depth += 1
        elif ch == "}":
            depth -= 1
            if depth == 0:
                end = i + 1
                break
    data = json.loads(raw[start:end]) if end else {}

result = data.get("result", "?")
tests = data.get("tests", 0)
passed = data.get("passed", 0)
errors = data.get("errors", 0)
failures = data.get("failures", 0)
assertions = data.get("assertions", 0)
ms = data.get("duration_ms", 0)

print(f"result={result}  tests={tests}  passed={passed}  errors={errors}  "
      f"failures={failures}  assertions={assertions}  {ms}ms")

details = data.get("error_details") or data.get("failure_details") or []
if details:
    # kelompokkan pesan identik -> satu baris per kelas masalah, bukan per test
    counts = Counter(d.get("message", "?") for d in details)
    print(f"--- {len(details)} kegagalan, {len(counts)} pesan unik ---")
    for msg, n in counts.most_common(max_msgs):
        first = next(d for d in details if d.get("message") == msg)
        line = first.get("line", "?")
        print(f"  [{n}x] {msg[:220]}")
        print(f"        contoh: {first.get('test','?')[:110]}  (baris {line})")
    if len(counts) > max_msgs:
        print(f"  ... dan {len(counts) - max_msgs} pesan unik lain")

sys.exit(0 if result == "passed" else 1)
