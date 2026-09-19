#!/usr/bin/env bash
#
# One command to go from a fresh clone to a running POS.
#
# The first thing it does is the thing people forget: this repo does not run
# alone. pos_backend/composer.json maps `App\` to ../../backend/app/, so
# pdaftar.backend has to sit beside this repo:
#
#   parent/
#     backend/       <- pdaftar.backend
#     pdaftar_pos/   <- this repo
#
# Safe to run again; every step checks before it acts.
#
#   ./scripts/setup.sh              full setup, including Docker
#   ./scripts/setup.sh --no-docker  dependencies only
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKEND="$(dirname "$ROOT")/backend"
BACKEND_URL="${PDAFTAR_BACKEND_URL:-git@github.com:parmonov98/pdaftar.backend.git}"
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

# ── 1. pDaftar's shared domain ─────────────────────────────────────────────
step "pDaftar domeni ($BACKEND)"
if [ -d "$BACKEND/.git" ]; then
    echo "   allaqachon bor"
else
    git clone "$BACKEND_URL" "$BACKEND" \
        || die "pdaftar.backend'ni klon qilib bo'lmadi. Qo'lda: git clone $BACKEND_URL $BACKEND"
fi
[ -f "$BACKEND/bootstrap/helpers.php" ] \
    || die "$BACKEND ichida bootstrap/helpers.php yo'q — kutilgan repo emasga o'xshaydi."

# ── 2. POS backend ─────────────────────────────────────────────────────────
step "POS backend (pos_backend)"
cd "$ROOT/pos_backend"
[ -f .env ] || { cp .env.example .env; echo "   .env yaratildi"; }
composer install --no-interaction --prefer-dist
if grep -q '^APP_KEY=$' .env; then
    php artisan key:generate --force
fi

# ── 3. Kassa ───────────────────────────────────────────────────────────────
step "Kassa ilovasi (pos)"
cd "$ROOT/pos"
npm install

# ── 4. Docker ──────────────────────────────────────────────────────────────
if [ "$WITH_DOCKER" -eq 1 ]; then
    command -v docker >/dev/null || die "docker topilmadi. --no-docker bilan ishga tushiring."

    step "pDaftar konteynerlari (baza va Redis shundan)"
    (cd "$BACKEND" && docker compose up -d)

    step "POS konteynerlari"
    (cd "$ROOT/pos_backend" && docker compose up -d)
fi

# ── 5. Haqiqatan ishlayaptimi? ─────────────────────────────────────────────
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

Yuqoridagi pos:health'da XATO qatorlari bo'lsa, ularni avval tuzating —
README.md ning "Bu repo yolg'iz ishlamaydi" bo'limiga qarang.
DONE
