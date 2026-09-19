#!/usr/bin/env bash
#
# Deploy the POS on the staging box. Run ON the server, from the checkout:
#
#   cd /var/www/pos/pdaftar_pos && ./scripts/deploy-staging.sh
#
# This is what CI runs over SSH (.github/workflows/deploy-staging.yml). It is
# the sibling of scripts/deploy-devdata.sh, and differs in two ways that both
# come from the box being small and dedicated:
#
#   * it does not build the till — CI built it on a cloud runner with the RAM
#     to do it and rsynced pos/dist here. A 1 GB instance running vite build
#     gets OOM-killed, and a half-written dist/ is a white screen, not an error
#   * it does not expect pDaftar's containers, because there are none. The
#     stack is docker-compose.staging.yml, selected via COMPOSE_FILE in
#     pos_backend/.env, so plain `docker compose` commands do the right thing
#
#   --ref <git-ref>   deploy that ref instead of origin/main
#   --no-pull         deploy the working tree as-is
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKEND="$(dirname "$ROOT")/backend"
PULL=1
REF=""

while [ $# -gt 0 ]; do
    case "$1" in
        --no-pull) PULL=0 ;;
        --ref) REF="${2:-}"; shift ;;
        -h|--help) sed -n '2,20p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Nomalum argument: $1" >&2; exit 2 ;;
    esac
    shift
done

step() { printf "\n\033[1m==> %s\033[0m\n" "$*"; }
die()  { printf "\n\033[31mTOXTATILDI: %s\033[0m\n" "$*" >&2; exit 1; }

# ── Preflight ──────────────────────────────────────────────────────────────
step "Tekshiruv"

[ -f "$BACKEND/bootstrap/helpers.php" ] \
    || die "$BACKEND topilmadi. pdaftar.backend shu repo yonida turishi shart —
POS sotuvni pDaftarning OZ domen kodi orqali yozadi:
    /var/www/pos/
      backend/       <- pdaftar.backend
      pdaftar_pos/   <- shu repo"

[ -f "$ROOT/pos_backend/.env" ] \
    || die "pos_backend/.env yoq. Avval: ./scripts/provision-staging.sh <domain>"

grep -q '^COMPOSE_FILE=docker-compose.staging.yml' "$ROOT/pos_backend/.env" \
    || die "pos_backend/.env da COMPOSE_FILE=docker-compose.staging.yml yoq.
Usiz \`docker compose\` laptop uchun mo'ljallangan docker-compose.yml ni oladi
va pDaftarning bu yerda mavjud bo'lmagan tarmogini qidiradi."

# ── Kod ────────────────────────────────────────────────────────────────────
if [ "$PULL" -eq 1 ]; then
    step "Kodni yangilash"
    git -C "$ROOT" fetch origin --prune
    if [ -n "$REF" ]; then
        git -C "$ROOT" checkout --detach "$REF"
    else
        git -C "$ROOT" checkout main
        git -C "$ROOT" merge --ff-only origin/main
    fi
    # The shared domain is half the application; a POS pinned to a stale
    # pDaftar writes sales through last month's rules.
    git -C "$BACKEND" fetch origin --prune && git -C "$BACKEND" merge --ff-only origin/main
fi
echo "   pos     $(git -C "$ROOT" rev-parse --short HEAD)"
echo "   backend $(git -C "$BACKEND" rev-parse --short HEAD)"

# ── Backend ────────────────────────────────────────────────────────────────
step "POS backend"
cd "$ROOT/pos_backend"

docker compose up -d --build
docker compose exec -T php-pos composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader
docker compose exec -T php-pos php artisan migrate --force
docker compose exec -T php-pos php artisan config:cache
docker compose exec -T php-pos php artisan route:cache

# ── Kassa ──────────────────────────────────────────────────────────────────
# Not built here. CI rsynced it; this only checks that it actually arrived,
# because an empty dist/ serves a blank page with a 200 and no log line.
step "Kassa ilovasi"
if [ -f "$ROOT/pos/dist/index.html" ]; then
    echo "   dist/ joyida ($(find "$ROOT/pos/dist" -type f | wc -l) fayl)"
else
    echo "   OGOHLANTIRISH: pos/dist/index.html yoq — CI hali kassani yubormagan."
fi

# ── Gate ───────────────────────────────────────────────────────────────────
# Last, and allowed to stop the release. Everything above can succeed while the
# POS is wired to the wrong database or queueing into a namespace nobody reads,
# and every one of those failures is silent: a missing observer writes the sale
# and never moves the cash to Kassa.
#
# POS_HEALTH_GATE in pos_backend/.env decides whether it stops the release.
# `on` is the default and the only setting a POS taking real sales may have.
# `off` exists for the window before the database question is answered: with
# no pDaftar schema the checks cannot pass, and a pipeline that is red for a
# known reason teaches everyone to ignore red.
step "Yakuniy tekshiruv: pos:health"
gate="$(grep '^POS_HEALTH_GATE=' "$ROOT/pos_backend/.env" | cut -d= -f2- | tr -d "\"' " || true)"
if docker compose exec -T php-pos php artisan pos:health; then
    :
elif [ "$gate" = "off" ]; then
    printf '\n\033[33mpos:health xato qaytardi. POS_HEALTH_GATE=off — deploy toxtatilmadi.\033[0m\n'
    printf 'Bu vaqtinchalik: sxema/baza hal bolgach .env da `on` qiling.\n'
else
    die "pos:health xato qaytardi. Yuqoridagi XATO qatorlarini tuzatmaguncha
POSni trafikka qoymang."
fi

echo
echo "Deploy tugadi: $(git -C "$ROOT" rev-parse --short HEAD)"
