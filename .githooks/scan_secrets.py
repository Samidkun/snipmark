#!/usr/bin/env python3
"""Zero-dependency secret scanner. Fallback when gitleaks is unavailable.

Usage: scan_secrets.py <file> [<file> ...]
Exit 0 = clean, 1 = secret found. Prints findings to stderr.

This is a safety net, NOT a replacement for gitleaks. It catches the
common high-signal patterns so the pre-commit hook actually blocks
something when gitleaks is not installed.
"""
import re
import sys
import os

PATTERNS = [
    # cloud provider keys
    (r"AKIA[0-9A-Z]{16}", "AWS access key ID"),
    (r"aws_secret_access_key\s*[:=]\s*['\"]?[A-Za-z0-9/+=]{40}", "AWS secret key"),
    (r"AIza[0-9A-Za-z_\-]{35}", "Google API key"),
    (r"ya29\.[0-9A-Za-z_\-]{20,}", "Google OAuth token"),
    (r"sk_live_[0-9a-zA-Z]{24,}", "Stripe live secret key"),
    (r"rk_live_[0-9a-zA-Z]{24,}", "Stripe restricted key"),
    (r"sk-[A-Za-z0-9]{20,}", "OpenAI-style API key"),
    (r"ghp_[0-9A-Za-z]{36}", "GitHub personal access token"),
    (r"gho_[0-9A-Za-z]{36}", "GitHub OAuth token"),
    (r"github_pat_[0-9A-Za-z_]{22,}", "GitHub fine-grained PAT"),
    (r"xox[baprs]-[0-9A-Za-z\-]{10,}", "Slack token"),
    (r"-----BEGIN (RSA |EC |OPENSSH |DSA |PGP )?PRIVATE KEY-----", "private key block"),
    (r"eyJ[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}", "JWT"),
    (r"SG\.[A-Za-z0-9_\-]{22,}\.[A-Za-z0-9_\-]{22,}", "SendGrid API key"),
    (r"npm_[A-Za-z0-9]{36}", "npm token"),
    (r"sq0atp-[0-9A-Za-z_\-]{22}", "Square access token"),
    (r"(mysql|postgres|postgresql|mongodb|redis)://[^\s:@]+:[^\s:@]+@", "DB URL with password"),
    # Laravel APP_KEY: a real one is base64: followed by 44 chars of key
    # material. This was MISSED entirely - an .env backup carrying the live
    # APP_KEY committed cleanly, and APP_KEY decrypts every session cookie and
    # encrypted column, so leaking it is equivalent to leaking the database.
    (r"base64:[A-Za-z0-9+/]{40,}={0,2}", "Laravel APP_KEY (base64)"),
]

# generic assignment of a secret-ish name to a literal value.
# Handles quoted ("x"), single-quoted ('x'), and bare .env style (KEY=value).
#
# The (?!:) guard is load-bearing: without it, PHP/JS static-call syntax
# (Password::defaults(), Cache::get(), Foo::BAR) reads as "name = value" and
# flags every framework file that mentions the word "password".
GENERIC = re.compile(
    r"(?i)(?<![A-Za-z0-9])(api[_-]?key|app[_-]?key|secret|passwd|password|token|private[_-]?key|access[_-]?key)"
    r"(?![A-Za-z0-9_])"
    r"\s*[:=]\s*(?!:)(?:['\"]([^'\"]{8,})['\"]|([^\s'\"#]{8,}))"
)

SKIP_EXT = {".png", ".jpg", ".jpeg", ".gif", ".webp", ".ico", ".pdf", ".zip", ".gz",
            ".tar", ".mp4", ".mp3", ".woff", ".woff2", ".ttf", ".eot", ".so", ".dll",
            ".exe", ".bin", ".lock", ".min.js", ".map"}
# whole filenames that are config, not credentials
SKIP_FILE = {".htaccess", ".htpasswd", "web.config", "nginx.conf", "php.ini",
             ".editorconfig", ".gitignore", ".gitattributes", ".dockerignore",
             "tsconfig.json", "composer.json", "package.json"}
SKIP_PATH = (".env.example", "package-lock.json", "composer.lock", "yarn.lock",
             "pnpm-lock.yaml", "poetry.lock", "Cargo.lock", ".git/", "vendor/",
             "node_modules/", ".githooks/")

# template/variable syntax that is never a real secret
PLACEHOLDER = re.compile(
    r"(?i)^(x{3,}|\*{3,}|your[_-]?|changeme|placeholder|example|dummy|"
    r"test|fake|todo|none|null|undefined|true|false|<.*>|"
    r"\$\{.*\}|%\{.*\}|\{\{.*\}\})"
)


def is_placeholder(val: str) -> bool:
    v = val.strip()
    if len(v) < 12:
        return True
    return bool(PLACEHOLDER.match(v))


# Real secrets are opaque literals. Code is not: a right-hand side containing
# static-access (::), member access (->), a call ( ), an index/array, a
# variable ($), or a statement terminator is an expression, not a credential.
# Without this, `$this->password = Password::MIN_LENGTH;` reads as a leak.
CODE_LIKE = re.compile(r"::|->|[()\[\]{};$]|\bnew\s")


def is_code(val: str) -> bool:
    return bool(CODE_LIKE.search(val))


def scan(path: str):
    findings = []
    base = os.path.basename(path)
    ext = os.path.splitext(path)[1].lower()
    if ext in SKIP_EXT or base in SKIP_FILE:
        return findings
    for sp in SKIP_PATH:
        if sp in path:
            return findings
    try:
        with open(path, "r", encoding="utf-8", errors="ignore") as f:
            for lineno, line in enumerate(f, 1):
                if len(line) > 5000:
                    continue
                for pat, label in PATTERNS:
                    for m in re.finditer(pat, line):
                        findings.append((lineno, label, m.group(0)[:12] + "..."))
                for m in GENERIC.finditer(line):
                    val = m.group(2) or m.group(3) or ""
                    if not is_placeholder(val) and not is_code(val):
                        findings.append((lineno, f"generic {m.group(1)}", val[:6] + "..."))
    except (OSError, UnicodeDecodeError):
        return findings
    return findings


def main(argv):
    total = 0
    for path in argv[1:]:
        if not os.path.isfile(path):
            continue
        for lineno, label, preview in scan(path):
            print(f"  {path}:{lineno}  {label}  ({preview})", file=sys.stderr)
            total += 1
    if total:
        print(f"\nBLOCKED: {total} possible secret(s). "
              f"If false positive, add to .gitleaks.toml allowlist or use a placeholder.",
              file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
