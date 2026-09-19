#!/usr/bin/env bash
#
# Deploy the POS to the devdata server.
#
# Run from the POS checkout ON the server:
#
#   cd /path/to/pdaftar_pos && ./scripts/deploy-devdata.sh
#
# The POS is deliberately its own compose project on its own port, so this
# never touches pDaftar's containers. It does depend on them being up —
# MariaDB and Redis are pDaftar's, and the shared domain is read straight out
# of ../backend.
#
# It refuses to finish on a broken link. `pos:health` exits 1 when the shared
# domain, the observers, the database or the queue namespace are wrong, and
# every one of those fails SILENTLY in production: a missing observer writes
# the sale and never moves the cash to Kassa. Better a stopped release than a
# week of wrong books.
#
#   --no-pull   deploy the working tree as-is (for testing a fix in place)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKEND="$(dirname "$ROOT")/backend"
PULL=1

for arg in "$@"; do
    case "$arg" in
        --no-pull) PULL=0 ;;
        -h|--help) sed -n '2,20p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *) echo "Nomalum argument: $arg" >&2; exit 2 ;;
    esac
done

step() { printf "\n\033[1m==> %s\033[0m\n" "$*"; }
die()  { printf "\n\033[31mTOXTATILDI: %s\033[0m\n" "$*" >&2; exit 1; }

# ── Preflight ──────────────────────────────────────────────────────────────
# Two names the POS borrows from pDaftar's compose project. Compose derives
# both the image and the network from COMPOSE_PROJECT_NAME, so if that is
# unset or different on this server, `docker compose up` fails with an obscure
# "image not found" / "network not found" rather than saying why. pos:health
# cannot catch these — the container never starts.
step "Tekshiruv: pDaftar yonma-yon va togri nomda turibdimi"

[ -f "$BACKEND/bootstrap/helpers.php" ] \
    || die "$BACKEND topilmadi. pdaftar.backend shu repo yonida (sibling) turishi shart:
    parent/
      backend/       <- git clone pdaftar.backend
      pdaftar_pos/   <- shu repo"

[ -f "$BACKEND/.env" ] || die "$BACKEND/.env yoq — pDaftar hali sozlanmagan."

project="$(grep -E '^COMPOSE_PROJECT_NAME=' "$BACKEND/.env" | cut -d= -f2- | tr -d "\"' " || true)"
[ "$project" = "pdaftar_dev" ] || die "backend/.env da COMPOSE_PROJECT_NAME=\"${project:-BOSH}\", kutilgani \"pdaftar_dev\".
pos_backend/docker-compose.yml \`pdaftar_dev-php\` obraziga va \`pdaftar_dev_network\`
tarmogiga suyanadi — ikkalasini ham compose shu nomdan yasaydi."

docker image inspect pdaftar_dev-php >/dev/null 2>&1 \
    || die "\`pdaftar_dev-php\` obrazi yoq. Avval pDaftarni kotaring:
    (cd $BACKEND && docker compose up -d --build)"

docker network inspect pdaftar_dev_network >/dev/null 2>&1 \
    || die "\`pdaftar_dev_network\` tarmogi yoq. Avval pDaftarni kotaring:
    (cd $BACKEND && docker compose up -d)"

[ -f "$ROOT/pos_backend/.env" ] \
    || die "pos_backend/.env yoq. Namunadan yarating va toldiring:
    cp pos_backend/.env.devdata.example pos_backend/.env"

# ── Kod ────────────────────────────────────────────────────────────────────
if [ "$PULL" -eq 1 ]; then
    step "Kodni yangilash"
    git -C "$ROOT" pull --ff-only
fi

# ── Backend ────────────────────────────────────────────────────────────────
step "POS backend"
cd "$ROOT/pos_backend"

docker compose up -d
docker compose exec -T php-pos composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader

# The POS owns exactly two tables. --force because there is no TTY here.
docker compose exec -T php-pos php artisan migrate --force

docker compose exec -T php-pos php artisan config:cache
docker compose exec -T php-pos php artisan route:cache

# ── Kassa ──────────────────────────────────────────────────────────────────
step "Kassa ilovasi"
cd "$ROOT/pos"
npm ci
npm run build

# ── Gate ───────────────────────────────────────────────────────────────────
# Last, and allowed to stop the release. Everything above can succeed while the
# POS is wired to the wrong database or queueing into a namespace nobody reads.
step "Yakuniy tekshiruv: pos:health"
cd "$ROOT/pos_backend"

if ! docker compose exec -T php-pos php artisan pos:health; then
    die "pos:health xato qaytardi. Yuqoridagi XATO qatorlarini tuzatmaguncha
POSni trafikka qoymang — bu xatolar jimgina buziladi, keyinroq ular haqida
hech qanday log bolmaydi."
fi

cat <<'DONE'

Deploy tugadi.

  POS API   https://pos.pdaftar.uz/api/pos/v1
  Health    https://pos.pdaftar.uz/api/pos/v1/health
  Swagger   https://pos.pdaftar.uz/api/documentation/pos

Nginx vhost birinchi marta ornatilayotgan bolsa:
  deploy/nginx/pos.pdaftar.uz.conf
DONE
