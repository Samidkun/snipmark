#!/usr/bin/env python3
"""Ringkas output `php artisan test` (JSON) jadi beberapa baris — DAN jaga exit code.

KENAPA EXIT CODE DIJAGA (bug B7):
Reporter bawaan Laravel 13 (`laravel/pao`) mencetak `"result":"passed"` walaupun
PHPUnit keluar dengan exit code 1. Membaca JSON saja akan melaporkan "hijau" untuk
suite yang gate-nya merah.

KENAPA OUTPUT DIBATASI KERAS (pelajaran 16 Sep):
Satu kegagalan PHPUnit bisa membawa stack trace belasan ribu karakter. Pernah
sekali output mencapai 257 KB dan membanjiri konteks sampai kerja berhenti.
Skrip ini MENJAMIN total keluaran < 4000 karakter, apa pun isi pesannya.

Pakai:
    bash scripts/run-tests.sh              # jalur normal
    php artisan test 2>&1 | python3 scripts/tests-summary.py --exit $?
"""
import json
import os
import sys
from collections import Counter

MAX_OUTPUT_CHARS = 3800

raw = sys.stdin.read()

exit_code = None
if "--exit" in sys.argv:
    exit_code = int(sys.argv[sys.argv.index("--exit") + 1])

max_msgs = 3
if "--max" in sys.argv:
    max_msgs = int(sys.argv[sys.argv.index("--max") + 1])


def clip(s: str, n: int) -> str:
    s = " ".join(str(s).split())
    return s if len(s) <= n else s[: n - 1] + "…"


def emit(lines: list[str], code: int) -> None:
    """Cetak dengan batas keras, lalu keluar dengan kode yang diminta."""
    out, total = [], 0
    for ln in lines:
        if total + len(ln) + 1 > MAX_OUTPUT_CHARS:
            out.append(f"… (keluaran dipotong pada {MAX_OUTPUT_CHARS} karakter)")
            break
        out.append(ln)
        total += len(ln) + 1
    print("\n".join(out))
    sys.exit(code)


start = raw.find("{")

if start == -1:
    # Bukan JSON (mis. pao tanpa TTY). Jangan pernah mencetak mentah.
    emit([
        "output BUKAN JSON (reporter pao butuh TTY).",
        f"  ekor (dipotong 400 char): {clip(raw[-400:], 400)}",
    ], 2)

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
        emit([f"JSON rusak. ekor: {clip(raw[-300:], 300)}"], 2)

claimed = data.get("result", "?")
lines = [
    f"result={claimed}  tests={data.get('tests', 0)}  passed={data.get('passed', 0)}  "
    f"errors={data.get('errors', 0)}  failures={data.get('failures', 0)}  "
    f"assertions={data.get('assertions', 0)}  {data.get('duration_ms', 0)}ms"
]

details = data.get("error_details") or data.get("failure_details") or []
if details:
    counts = Counter(clip(d.get("message", "?"), 160) for d in details)
    lines.append(f"--- {len(details)} kegagalan, {len(counts)} pesan unik ---")
    for msg, n in counts.most_common(max_msgs):
        first = next(d for d in details if clip(d.get("message", "?"), 160) == msg)
        lines.append(f"  [{n}x] {msg}")
        lines.append(f"        contoh: {clip(first.get('test', '?'), 90)} (baris {first.get('line', '?')})")
    if len(counts) > max_msgs:
        lines.append(f"  ... dan {len(counts) - max_msgs} pesan unik lain")

# --- gerbang kejujuran: exit code mengalahkan klaim JSON ---
if exit_code is not None and exit_code != 0:
    if claimed == "passed":
        lines += [
            "",
            "KLaim JSON BERBEDA DENGAN EXIT CODE",
            f"  JSON bilang '{claimed}' tapi proses keluar {exit_code}.",
            "  Penyebab umum: PHPUnit warning (failOnWarning=true).",
            "  Jalankan `./vendor/bin/phpunit` langsung dan baca bagian warning.",
        ]
    emit(lines, 1)

emit(lines, 0 if claimed == "passed" else 1)
