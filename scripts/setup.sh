#!/usr/bin/env bash
#
# One command to go from a fresh clone to a running POS.
#
# It used to start by cloning pdaftar.backend as a sibling, because
# pos_backend/composer.json mapped `App\` into it and nothing here would boot
# without it. That is gone: this repository runs on its own.
#
# Safe to run again; every step checks before it acts.
#
#   ./scripts/setup.sh              full setup, including Docker
#   ./scripts/setup.sh --no-docker  dependencies only
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WITH_DOCKER=1

for arg in "$@"; do
    case "$arg" in
        --no-docker) WITH_DOCKER=0 ;;
        -h|--help) sed -n '2,16p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Noma'lum argument: $arg" >&2; exit 2 ;;
    esac
done

step() { printf '\n\033[1m==> %s\033[0m\n' "$*"; }
die()  { printf '\n\033[31mXATO: %s\033[0m\n' "$*" >&2; exit 1; }

need() { command -v "$1" >/dev/null || die "'$1' topilmadi. Avval o'rnating."; }
need git
need composer
need npm

# ── 1. POS backend ─────────────────────────────────────────────────────────
step "POS backend (pos_backend)"
cd "$ROOT/pos_backend"
[ -f .env ] || { cp .env.example .env; echo "   .env yaratildi"; }
composer install --no-interaction --prefer-dist
if grep -q '^APP_KEY=$' .env; then
    php artisan key:generate --force
fi

# ── 2. Kassa ───────────────────────────────────────────────────────────────
step "Kassa ilovasi (pos)"
cd "$ROOT/pos"
npm install

# ── 3. Docker ──────────────────────────────────────────────────────────────
if [ "$WITH_DOCKER" -eq 1 ]; then
    command -v docker >/dev/null || die "docker topilmadi. --no-docker bilan ishga tushiring."

    step "POS konteynerlari (php, nginx, mariadb, redis)"
    (cd "$ROOT/pos_backend" && docker compose up -d)
fi

# ── 4. Haqiqatan ishlayaptimi? ─────────────────────────────────────────────
step "Tekshiruv: php artisan pos:health"
cd "$ROOT/pos_backend"
if [ "$WITH_DOCKER" -eq 1 ]; then
    docker compose exec -T php-pos php artisan pos:health || true
else
    php artisan pos:health || true
fi

cat <<'DONE'

Tayyor.

  Kassa     cd pos && npm run dev      → http://localhost:5174
  POS API   http://localhost:8090/api/pos/v1
  Health    http://localhost:8090/api/pos/v1/health

Yuqoridagi pos:health'da XATO qatorlari bo'lsa, avval ularni tuzating.
DONE
