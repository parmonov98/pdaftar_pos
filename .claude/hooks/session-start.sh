#!/bin/bash
#
# SessionStart hook — prepares a Claude Code on the web container.
#
# It used to begin by cloning pdaftar.backend as a sibling: pos_backend mapped
# `App\` at ../../backend/app/, and `composer install` boots Laravel in
# post-autoload-dump, so without that checkout it could not even dump an
# autoloader. This repository stands alone now, so the hook installs its own
# dependencies and nothing else.
#
# Runs only in the remote environment; a local checkout is set up by
# scripts/setup.sh, which does the same work plus Docker.
set -euo pipefail

if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
    exit 0
fi

ROOT="${CLAUDE_PROJECT_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"

say() { printf '[session-start] %s\n' "$*"; }

# ── 1. POS backend ─────────────────────────────────────────────────────────
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

# ── 2. Kassa frontend ──────────────────────────────────────────────────────
cd "$ROOT/pos"
npm install --no-audit --no-fund

# ── 4. Say plainly whether this container can actually run the POS ──────────
cd "$ROOT/pos_backend"
if [ -f vendor/autoload.php ] && [ -f "$BACKEND/bootstrap/helpers.php" ]; then
    php artisan pos:health || say "pos:health reports problems (see above) — expected here: there is no pDaftar database or Redis in this container."
fi

say "ready"
