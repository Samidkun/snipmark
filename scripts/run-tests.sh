#!/usr/bin/env bash
# php artisan test + ringkasan yang TIDAK BISA bohong soal exit code.
#
# Kenapa perlu: reporter bawaan Laravel 13 (laravel/pao) mencetak "result":"passed"
# walau PHPUnit keluar dengan exit code 1 saat ada warning. Rangkaian `php artisan test | tail`
# juga memberi exit code dari `tail`, bukan dari test-nya (bug B7/B3).
#
# Pakai:  bash scripts/run-tests.sh [argumen artisan test...]
#         bash scripts/run-tests.sh --testsuite=Unit
set -uo pipefail

TMP="$(mktemp)"
trap 'rm -f "$TMP"' EXIT

php artisan test "$@" > "$TMP" 2>&1
RC=$?

python3 scripts/tests-summary.py --exit "$RC" "$@" < "$TMP" 2>/dev/null \
  || python3 scripts/tests-summary.py --exit "$RC" < "$TMP"

SUM=$?

if [ "$RC" -ne 0 ]; then
  echo "TESTS: MERAH (exit code PHPUnit = $RC)"
  exit 1
fi
[ "$SUM" -ne 0 ] && { echo "TESTS: MERAH (ringkasan)"; exit 1; }
echo "TESTS: HIJAU (exit code 0, tidak ada warning)"
exit 0
