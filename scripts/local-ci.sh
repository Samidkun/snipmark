#!/usr/bin/env bash
# local-ci — run the SAME gates as .github/workflows/ci.yml, on your laptop.
#
# WHY THIS EXISTS
#
# The generated ci.yml only runs on GitHub Actions. On a local-only project (no
# remote) every one of those jobs is dead: the file looks like six gates and
# executes none. That is the exact failure the SOP bans — "a gate that reports
# success without running is worse than no gate" — and it was invisible, because
# bootstrap.sh said nothing about the missing remote.
#
# This script is the local execution of those gates. It does not replace CI; it
# makes the same checks runnable when there is no CI to run them.
#
# DRIFT WARNING — READ THIS
#
# This file and .github/workflows/ci.yml are two sources of truth for one gate
# list. They can drift, and a drifted gate is a lie. There is no clean way to
# generate one from the other (CI has matrix/services primitives a shell script
# does not). So instead of pretending they cannot drift, selftest.sh checks that
# both name the same gates and FAILS when one side has a gate the other lacks.
# If you add a gate here, add it there too.
#
# Usage:
#   bash scripts/local-ci.sh            # full run (includes e2e/a11y/perf)
#   bash scripts/local-ci.sh --fast     # skip e2e/a11y/perf (pre-push speed)
#   bash scripts/local-ci.sh --tier t0  # T0 relaxes e2e/perf to skip-by-default
#
# Exit: 0 = every gate green, 1 = at least one gate failed.
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 1

FAST=0
TIER="t1"
while [ $# -gt 0 ]; do
  case "$1" in
    --fast) FAST=1 ;;
    --tier) TIER="${2:-t1}"; shift ;;
    -h|--help) sed -n '2,30p' "$0"; exit 0 ;;
    *) echo "unknown flag: $1" >&2; exit 2 ;;
  esac
  shift
done
TIER="$(echo "$TIER" | tr '[:upper:]' '[:lower:]')"

# ---------- stack detection (same rule as bootstrap.sh) ----------
IS_NODE=0; IS_PHP=0; IS_PY=0
[ -f package.json ] && IS_NODE=1
[ -f composer.json ] && IS_PHP=1
{ [ -f pyproject.toml ] || [ -f requirements.txt ]; } && IS_PY=1

RESULTS=()
fail=0

gate() { # gate <name> <skip-reason-or-empty> <command...>
  local name="$1"; shift
  local skip="$1"; shift
  if [ -n "$skip" ]; then
    RESULTS+=("SKIP  $name — $skip")
    printf '\n--- %s: SKIP (%s) ---\n' "$name" "$skip"
    return 0
  fi
  printf '\n--- %s ---\n' "$name"
  if "$@"; then
    RESULTS+=("ok    $name")
    printf 'ok    %s\n' "$name"
  else
    RESULTS+=("FAIL  $name")
    printf 'FAIL  %s\n' "$name"
    fail=1
  fi
}

have() { command -v "$1" >/dev/null 2>&1; }
has_npm_script() { [ "$IS_NODE" = 1 ] && grep -q "\"$1\"" package.json 2>/dev/null; }

printf '================================================================\n'
printf ' local-ci  (tier=%s%s)\n' "$TIER" "$([ "$FAST" = 1 ] && echo ', fast')"
printf ' mirror of .github/workflows/ci.yml\n'
printf '================================================================\n'

# ---------- 1. secrets (always — this is the one gate that must never skip) ----------
# NOTE: secret_gate.py's DEFAULT mode scans the STAGED index. In a CI/local
# run nothing is usually staged, so the default exits 0 having examined zero
# bytes — a gate that reports success without running. Verified: with nothing
# staged it returned 0 even with a secret sitting in a tracked file. `--history`
# is the correct mode here: it walks every blob reachable from all refs.
if have gitleaks; then
  gate "secrets" "" gitleaks detect --no-banner --redact
elif [ -f .githooks/secret_gate.py ]; then
  gate "secrets" "" python3 .githooks/secret_gate.py --history
elif [ -f scripts/secret_gate.py ]; then
  gate "secrets" "" python3 scripts/secret_gate.py --history
else
  RESULTS+=("FAIL  secrets — no scanner available")
  printf '\nFAIL secrets: no gitleaks and no secret_gate.py found.\n'
  printf '  Re-run project-bootstrap, or install gitleaks.\n'
  fail=1
fi

# ---------- 2..7. per-stack: lint, typecheck, test, audit, license, build ----------
if [ "$IS_NODE" = 1 ]; then
  gate "node:lint"      "$(has_npm_script lint || echo 'no lint script')"       npm run --silent lint
  gate "node:typecheck" "$(has_npm_script typecheck || echo 'no typecheck script')" npm run --silent typecheck
  gate "node:test"      "$(has_npm_script test || echo 'no test script')"      npm run --silent test
  # npm audit needs a lockfile. Without one it exits ENOLOCK — that is the gate
  # being UNABLE to run, not a finding, and reporting it as "FAIL audit" hides
  # which of the two happened. Report the real reason.
  if [ ! -f package-lock.json ] && [ ! -f npm-shrinkwrap.json ]; then
    RESULTS+=("FAIL  node:audit — no lockfile (npm audit cannot run; commit one)")
    printf '\nFAIL node:audit: no package-lock.json. The gate cannot run without it.\n'
    printf '  Fix: npm i --package-lock-only && git add package-lock.json\n'
    fail=1
  elif ! have npm; then
    RESULTS+=("SKIP  node:audit — npm missing")
    printf '\n--- node:audit: SKIP (npm missing) ---\n'
  else
    gate "node:audit" "" npm audit --audit-level=high
  fi
  gate "node:license"   "$(have npx || echo 'npx missing')"                    npx --yes license-checker --production --failOn "GPL;AGPL"
  gate "node:build"     "$(has_npm_script build || echo 'no build script')"    npm run --silent build
fi

if [ "$IS_PHP" = 1 ]; then
  if [ -f artisan ]; then
    gate "php:test"  "" php artisan test
  else
    gate "php:test"  "$([ -x vendor/bin/phpunit ] || echo 'no phpunit')" ./vendor/bin/phpunit
  fi
  gate "php:audit" "$(have composer || echo 'composer missing')" composer audit --no-interaction
fi

if [ "$IS_PY" = 1 ]; then
  gate "py:test"  "$(have pytest || echo 'pytest missing')"  pytest -q
  gate "py:audit" "$(have pip-audit || echo 'pip-audit missing')" pip-audit
fi

# ---------- 8..10. web quality: e2e, a11y, perf ----------
if [ "$FAST" = 1 ]; then
  RESULTS+=("SKIP  e2e — --fast"); RESULTS+=("SKIP  a11y — --fast"); RESULTS+=("SKIP  perf — --fast")
  printf '\n--- e2e/a11y/perf: SKIP (--fast) ---\n'
elif [ "$TIER" = "t0" ]; then
  RESULTS+=("SKIP  e2e/a11y/perf — tier t0 (run without --tier to force)")
  printf '\n--- e2e/a11y/perf: SKIP (tier t0) ---\n'
else
  if [ -f playwright.config.ts ] || [ -f playwright.config.js ]; then
    # a11y dijalankan sebagai gate SENDIRI (bukan tenggelam di dalam "e2e"), supaya
    # kegagalan aksesibilitas terlihat sebagai a11y — bukan sebagai "e2e gagal".
    if [ -f e2e/a11y.spec.ts ] && ls node_modules/@axe-core >/dev/null 2>&1; then
      gate "a11y" "" npx playwright test e2e/a11y.spec.ts
    elif [ -f e2e/a11y.spec.ts ]; then
      RESULTS+=("SKIP  a11y — @axe-core/playwright not installed (npm i -D @axe-core/playwright)")
      printf '\n--- a11y: SKIP (dependency missing) ---\n'
    fi
    gate "e2e" "" npx playwright test
  else
    RESULTS+=("SKIP  e2e — no playwright config")
    printf '\n--- e2e: SKIP (no playwright config) ---\n'
  fi

  # perf: Lighthouse butuh SERVER HIDUP. Tanpa ini, Lighthouse menabrak halaman
  # error dan melaporkan CHROME_INTERSTITIAL_ERROR — gate yang selalu merah bukan
  # gate, dan orang akan belajar mengabaikannya. Port harus SAMA dengan
  # .lighthouserc.json (8899), bukan 3000 (itu port Vite, bukan Laravel).
  if [ -f .lighthouserc.json ]; then
    LH_PORT=8899
    LH_URL="http://127.0.0.1:${LH_PORT}/"
    if curl -s -o /dev/null --max-time 2 "$LH_URL"; then
      gate "perf" "" npx --yes @lhci/cli autorun
    else
      printf '\n--- perf: menyalakan server Laravel di :%s ---\n' "$LH_PORT"
      php artisan serve --port="$LH_PORT" > /tmp/local-ci-serve.log 2>&1 &
      LH_SRV=$!
      # Tunggu server siap (health check, bukan sleep buta).
      LH_READY=0
      for _ in $(seq 1 20); do
        if curl -s -o /dev/null --max-time 2 "$LH_URL"; then LH_READY=1; break; fi
        sleep 0.5
      done
      if [ "$LH_READY" = 1 ]; then
        gate "perf" "" npx --yes @lhci/cli autorun
      else
        RESULTS+=("FAIL  perf — server Laravel tidak siap di :$LH_PORT")
        printf '\nFAIL perf: server tidak siap di %s (lihat /tmp/local-ci-serve.log)\n' "$LH_URL"
        fail=1
      fi
      kill "$LH_SRV" 2>/dev/null || true
      wait "$LH_SRV" 2>/dev/null || true
    fi
  else
    RESULTS+=("SKIP  perf — no .lighthouserc.json")
  fi
fi

# ---------- summary ----------
printf '\n================================================================\n'
printf ' SUMMARY\n'
printf '================================================================\n'
for r in "${RESULTS[@]}"; do printf '  %s\n' "$r"; done
printf '%s\n' '----------------------------------------------------------------'
if [ "$fail" -eq 0 ]; then
  printf ' LOCAL-CI: ALL GREEN\n'
else
  printf ' LOCAL-CI: FAILURES ABOVE — do not ship\n'
fi
printf '================================================================\n'
exit "$fail"
