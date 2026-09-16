#!/usr/bin/env python3
"""Mutation check untuk UserAgentParser + VisitorHasher.

Aturan (spec / SOP Stage 6 & 11): test yang tetap hijau setelah perbaikan dilepas
lebih buruk daripada tidak ada test. Setiap mutasi DIWAJIBIKAN membuat suite merah.

Verifikasi mutasi benar-benar diterapkan sebelum menjalankan test — kegagalan yang
pernah terjadi di BotDetector (bash menelan `$ua`) terdeteksi oleh assert di bawah.
"""
import shutil
import subprocess
import sys

PARSER = "app/Support/UserAgentParser.php"
HASHER = "app/Support/VisitorHasher.php"

MUTATIONS = [
    (
        PARSER,
        "urutan os dibalik: linux diperiksa sebelum android",
        "        if (str_contains($ua, 'android')) {\n            return 'android';\n        }\n",
        "",  # hapus cek android -> jatuh ke 'linux'
    ),
    (
        PARSER,
        "urutan os dibalik: macos diperiksa sebelum ios",
        "        foreach (['iphone', 'ipad', 'ipod', 'ios'] as $needle) {\n            if (str_contains($ua, $needle)) {\n                return 'ios';\n            }\n        }\n",
        "",  # hapus cek ios -> iPhone jatuh ke 'macos'
    ),
    (
        PARSER,
        "edge dihapus dari daftar browser (jadi chrome semua)",
        "            'edge' => ['edg/', 'edge/', 'edgios', 'edga'],\n",
        "",
    ),
    (
        PARSER,
        "samsung dihapus dari daftar browser (jadi chrome semua)",
        "            'samsung' => ['samsungbrowser'],\n",
        "",
    ),
    (
        PARSER,
        "deteksi tablet android dihapus (jadi mobile semua)",
        "        if (str_contains($ua, 'android')) {\n            return str_contains($ua, 'mobile') ? 'mobile' : 'tablet';\n        }\n",
        "        if (str_contains($ua, 'android')) {\n            return 'mobile';\n        }\n",
    ),
    (
        PARSER,
        "bot tidak lagi dibuang lebih dulu",
        r"        if ($this->botDetector->detect($ua, acceptsHtml: true, acceptLanguage: 'en')->isBot) {" + "\n            return ParsedUserAgent::other();\n        }\n",
        "",
    ),
    (
        HASHER,
        "HMAC diganti digest polos (bisa di-enumerasi offline)",
        "return hash_hmac('sha256', $normalized.'|'.$at->format('Y-m-d'), $this->secret);",
        "return hash('sha256', $normalized.'|'.$at->format('Y-m-d'));",
    ),
    (
        HASHER,
        "rotasi harian dibuang (hash jadi pengenal permanen)",
        "$normalized.'|'.$at->format('Y-m-d')",
        "$normalized",
    ),
    (
        HASHER,
        "secret boleh kosong (guard dihapus)",
        "        if (trim($secret) === '') {",
        "        if (false) {",
    ),
]

backups = {}
failures = []

for i, (path, label, old, new) in enumerate(MUTATIONS):
    if path not in backups:
        backups[path] = open(path, encoding="utf-8").read()

    t = backups[path]
    if old not in t:
        print(f"SKIP   {label}\n       POLA TIDAK KETEMU — mutasi tidak diterapkan, tidak ada bukti.")
        failures.append(label)
        continue

    mutated = t.replace(old, new, 1)
    if mutated == t:
        print(f"SKIP   {label}\n       mutasi tidak mengubah berkas.")
        failures.append(label)
        continue

    open(path, "w", encoding="utf-8").write(mutated)

    r = subprocess.run(["php", "artisan", "test", "--testsuite=Unit"], capture_output=True, text=True)
    s = subprocess.run(["python3", "scripts/tests-summary.py", "--max", "1"],
                       input=r.stdout, capture_output=True, text=True).stdout.strip().splitlines()

    red = s and "result=failed" in s[0]
    print(f"{'RED ✓ ' if red else 'GREEN ✗'} {label}")
    if s:
        print(f"       {s[0][:150]}")
    if not red:
        failures.append(label)

    open(path, "w", encoding="utf-8").write(backups[path])

r = subprocess.run(["php", "artisan", "test", "--testsuite=Unit"], capture_output=True, text=True)
print("\npemulihan:", subprocess.run(["python3", "scripts/tests-summary.py"],
      input=r.stdout, capture_output=True, text=True).stdout.strip())

for path, text in backups.items():
    assert open(path, encoding="utf-8").read() == text, f"{path} tidak kembali seperti semula"
print("berkas sumber identik dengan semula ✓")

if failures:
    print(f"\n{len(failures)} MUTASI TIDAK TERDETEKSI — suite punya lubang, bukan punya gigi:")
    for f in failures:
        print("  -", f)
    sys.exit(1)
print(f"\n{len(MUTATIONS)}/{len(MUTATIONS)} mutasi terdeteksi.")
