#!/usr/bin/env python3
"""Runner mutation check yang HANYA memakai exit code.

KENAPA TIDAK MEMBACA OUTPUT (pelajaran berulang, 3x di sesi 16 Sep):
  1. `php artisan test | tail`  -> exit code milik `tail`, bukan test-nya.
  2. `laravel/pao` bisa mencetak `"result":"passed"` saat proses keluar 1 (bug B7).
  3. pao hanya mengeluarkan JSON bila ada TTY; lewat subprocess output-nya
     human-readable, sehingga parser JSON menyimpulkan "hijau" untuk suite MERAH.

Kesimpulan: exit code adalah satu-satunya sinyal yang tidak bisa berbohong di sini.
Skrip ini menolak menyimpulkan apa pun bila mutasi tidak terbukti mengubah berkas.

Pakai:
    python3 scripts/mutation-check.py             # semua grup
    python3 scripts/mutation-check.py support     # satu grup
"""
import shutil
import subprocess
import sys

D = "/mnt/data/01_Projects/Porto/snipmark/"

GROUPS = {
    "support": [
        ("app/Support/BotDetector.php", "buang jalur struktural (lapisan 2)",
         "if ($ua === '' || strlen($ua) < self::MIN_UA_LENGTH) {", "if (false) {"),
        ("app/Support/BotDetector.php", "MIN_UA_LENGTH jadi 1",
         "private const MIN_UA_LENGTH = 15;", "private const MIN_UA_LENGTH = 1;"),
        ("app/Support/BotDetector.php", "BOUNDARY_MAX_LEN jadi 3 (batas kata hilang)",
         "private const BOUNDARY_MAX_LEN = 7;", "private const BOUNDARY_MAX_LEN = 3;"),
        ("app/Support/BotDetector.php", "Accept-Language kosong bukan sinyal",
         "return $acceptLanguage === null || trim($acceptLanguage) === '';",
         "return $acceptLanguage === null;"),
        ("app/Support/BotDetector.php", "urutan token dibalik (twitterbot sebelum telegrambot)",
         "'telegrambot' => ['TelegramBot', 'chat_preview'],\n        'twitterbot' => ['Twitterbot', 'chat_preview'],",
         "'twitterbot' => ['Twitterbot', 'chat_preview'],\n        'telegrambot' => ['TelegramBot', 'chat_preview'],"),
        ("app/Support/UserAgentParser.php", "os: android tidak diperiksa (jadi linux)",
         "        if (str_contains($ua, 'android')) {\n            return 'android';\n        }\n", ""),
        ("app/Support/UserAgentParser.php", "os: ios tidak diperiksa (jadi macos)",
         "        foreach (['iphone', 'ipad', 'ipod', 'ios'] as $needle) {\n            if (str_contains($ua, $needle)) {\n                return 'ios';\n            }\n        }\n", ""),
        ("app/Support/UserAgentParser.php", "edge dihapus (jadi chrome)",
         "            'edge' => ['edg/', 'edge/', 'edgios', 'edga'],\n", ""),
        ("app/Support/UserAgentParser.php", "samsung dihapus (jadi chrome)",
         "            'samsung' => ['samsungbrowser'],\n", ""),
        ("app/Support/UserAgentParser.php", "tablet android dihapus (jadi mobile)",
         "        if (str_contains($ua, 'android')) {\n            return str_contains($ua, 'mobile') ? 'mobile' : 'tablet';\n        }\n",
         "        if (str_contains($ua, 'android')) {\n            return 'mobile';\n        }\n"),
        ("app/Support/UserAgentParser.php", "bot tidak dibuang lebih dulu",
         r"        if ($this->botDetector->detect($ua, acceptsHtml: true, acceptLanguage: 'en')->isBot) {" + "\n            return ParsedUserAgent::other();\n        }\n", ""),
        ("app/Support/VisitorHasher.php", "HMAC jadi digest polos",
         "return hash_hmac('sha256', $normalized.'|'.$at->format('Y-m-d'), $this->secret);",
         "return hash('sha256', $normalized.'|'.$at->format('Y-m-d'));"),
        ("app/Support/VisitorHasher.php", "rotasi harian dibuang",
         "$normalized.'|'.$at->format('Y-m-d')", "$normalized"),
        ("app/Support/VisitorHasher.php", "guard secret kosong dihapus",
         "        if (trim($secret) === '') {", "        if (false) {"),
    ],
    "model": [
        ("app/Models/Link.php", "retry dihapus (exception dilempar langsung)",
         "            } catch (QueryException $e) {\n                // 23000 = integrity constraint violation. Kalau yang ditabrak bukan\n                // `code`, retry tidak akan menolong: lempar apa adanya.\n                if (! static::isCodeCollision($e)) {\n                    throw $e;\n                }\n            }",
         "            } catch (QueryException $e) {\n                throw $e;\n            }"),
        ("app/Models/Link.php", "isCodeCollision selalu false (retry mati)",
         "        if ($driverCode !== self::DUPLICATE_ENTRY_CODE) {\n            return false;\n        }",
         "        if (true) {\n            return false;\n        }"),
        ("app/Models/Link.php", "kode tidak acak",
         "        return Str::lower(Str::random(self::CODE_LENGTH));", "        return 'fixed01';"),
        ("app/Models/Link.php", "isReachable mengabaikan is_active",
         "        if (! $this->is_active) {\n            return false;\n        }\n", ""),
        ("app/Models/Link.php", "isReachable mengabaikan kedaluwarsa",
         "        return $this->expires_at === null || $this->expires_at->isFuture();",
         "        return true;"),
    ],
}


def run_tests():
    r = subprocess.run(["php", "artisan", "test"], capture_output=True, text=True, cwd=D)
    return r.returncode


def main():
    which = sys.argv[1] if len(sys.argv) > 1 else None
    groups = {which: GROUPS[which]} if which in GROUPS else GROUPS

    baseline = run_tests()
    print(f"baseline exit code: {baseline}  ({'hijau' if baseline == 0 else 'MERAH — perbaiki dulu'})")
    if baseline != 0:
        return 2

    misses = []
    total = 0

    for gname, muts in groups.items():
        print(f"\n=== grup: {gname} ({len(muts)} mutasi) ===")
        for relpath, label, old, new in muts:
            path = D + relpath
            original = open(path, encoding="utf-8").read()
            total += 1

            if old not in original:
                print(f"SKIP   {label}\n       POLA TIDAK KETEMU — mutasi tidak diterapkan, tidak ada bukti.")
                misses.append(f"{label} (pola tidak ditemukan)")
                continue

            open(path, "w", encoding="utf-8").write(original.replace(old, new, 1))
            if open(path, encoding="utf-8").read() == original:
                print(f"SKIP   {label}\n       berkas tidak berubah.")
                misses.append(f"{label} (berkas tidak berubah)")
                continue

            code = run_tests()
            red = code != 0
            print(f"{'RED ✓ ' if red else 'GREEN ✗'} {label}   (exit={code})")
            if not red:
                misses.append(label)

            open(path, "w", encoding="utf-8").write(original)

    print(f"\npemulihan: exit={run_tests()}")
    for relpath in {m[0] for g in groups.values() for m in g}:
        pass  # berkas sudah ditulis ulang dari isi asli per mutasi

    if misses:
        print(f"\n{len(misses)}/{total} MUTASI TIDAK TERDETEKSI:")
        for m in misses:
            print("  -", m)
        return 1

    print(f"\n{total}/{total} mutasi terdeteksi MERAH.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
