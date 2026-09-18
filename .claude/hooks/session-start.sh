#!/bin/bash
#
# SessionStart hook — prepares a Claude Code on the web container.
#
# The unusual step is the first one. pos_backend maps `App\` at
# ../../backend/app/ (see pos_backend/composer.json), so the container has to
# hold pdaftar.backend as a SIBLING of this repo before composer can even dump
# an autoloader — `composer install` boots Laravel in post-autoload-dump and
# that requires ../../backend/bootstrap/helpers.php. Cloning it is therefore
# setup, not convenience.
#
# Runs only in the remote environment; a local checkout is set up by
# scripts/setup.sh, which does the same work plus Docker.
set -euo pipefail

if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
    exit 0
fi

ROOT="${CLAUDE_PROJECT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
BACKEND="$(dirname "$ROOT")/backend"
BACKEND_URL="${PDAFTAR_BACKEND_URL:-https://github.com/parmonov98/pdaftar.backend}"

say() { printf '[session-start] %s\n' "$*"; }

# ── 1. pDaftar's shared domain, as a sibling ────────────────────────────────
if [ -d "$BACKEND/.git" ]; then
    say "shared domain already at $BACKEND"
elif git clone --depth 1 "$BACKEND_URL" "$BACKEND" 2>&1 | sed 's/^/[session-start]   /'; then
    say "cloned shared domain into $BACKEND"
else
    rmdir "$BACKEND" 2>/dev/null || true
    say "WARNING: could not clone $BACKEND_URL"
    say "  Add parmonov98/pdaftar.backend to this environment's repository"
    say "  sources, or the POS backend cannot boot. The frontend is unaffected."
fi

# ── 2. POS backend ─────────────────────────────────────────────────────────
cd "$ROOT/pos_backend"

[ -f .env ] || cp .env.example .env

# --no-scripts when the sibling is absent: package:discover would fatal on the
# missing helpers.php and take the whole hook down with it.
if [ -f "$BACKEND/bootstrap/helpers.php" ]; then
    composer install --no-interaction --prefer-dist --no-progress
else
    say "shared domain missing — installing without Laravel's discover step"
    composer install --no-interaction --prefer-dist --no-progress --no-scripts
fi

if grep -q '^APP_KEY=$' .env; then php artisan key:generate --force; fi

# ── 3. Kassa frontend ──────────────────────────────────────────────────────
cd "$ROOT/pos"
npm install --no-audit --no-fund

# ── 4. Say plainly whether this container can actually run the POS ──────────
cd "$ROOT/pos_backend"
if [ -f vendor/autoload.php ] && [ -f "$BACKEND/bootstrap/helpers.php" ]; then
    php artisan pos:health || say "pos:health reports problems (see above) — expected here: there is no pDaftar database or Redis in this container."
fi

say "ready"
