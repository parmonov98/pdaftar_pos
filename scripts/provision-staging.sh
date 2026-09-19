#!/usr/bin/env bash
#
# Turn a bare Ubuntu instance into the POS staging box. Run it ON the server,
# as a sudo-capable user, once:
#
#   curl -fsSL https://raw.githubusercontent.com/parmonov98/pdaftar_pos/main/scripts/provision-staging.sh | bash -s -- pos-staging.pdaftar.uz
#
# or, from a checkout already on the box:
#
#   ./scripts/provision-staging.sh pos-staging.pdaftar.uz
#
# What it does, in the order it matters:
#   1. swap, before anything else — a 1 GB instance that builds a PHP image
#      without swap gets its build OOM-killed halfway and leaves no trace why
#   2. Docker
#   3. the code
#   4. pos_backend/.env, generated, with real random secrets
#   5. the stack and the POS's own schema
#   6. host nginx + TLS in front of the container on 127.0.0.1:8090
#
# It is safe to run again: every step checks before it acts. It does NOT
# deploy code — that is the CI workflow's job (.github/workflows/deploy-staging.yml).
set -euo pipefail

DOMAIN="${1:-}"
[ -n "$DOMAIN" ] || { echo "Foydalanish: $0 <domain>   masalan: $0 pos-staging.pdaftar.uz" >&2; exit 2; }

ROOT_DIR=/var/www/pos
POS_DIR="$ROOT_DIR/pdaftar_pos"
POS_REPO="${POS_REPO:-https://github.com/parmonov98/pdaftar_pos.git}"

step() { printf "\n\033[1m==> %s\033[0m\n" "$*"; }
die()  { printf "\n\033[31mTOXTATILDI: %s\033[0m\n" "$*" >&2; exit 1; }

[ "$(id -u)" -ne 0 ] || die "root sifatida ishga tushirmang. sudo huquqli oddiy foydalanuvchidan ishga tushiring."
sudo -n true 2>/dev/null || die "parolsiz sudo kerak."

# ── 1. Swap ────────────────────────────────────────────────────────────────
# First, not last. Everything below is heavier than a 1 GB instance expects.
step "Swap"
if [ "$(swapon --show --noheadings | wc -l)" -gt 0 ]; then
    echo "   allaqachon bor: $(swapon --show --noheadings --bytes | awk '{printf "%.0fMB\n", $3/1024/1024}' | paste -sd' ')"
else
    sudo fallocate -l 2G /swapfile
    sudo chmod 600 /swapfile
    sudo mkswap /swapfile >/dev/null
    sudo swapon /swapfile
    grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab >/dev/null
    echo "   2GB swap yoqildi"
fi

# ── 2. Docker ──────────────────────────────────────────────────────────────
step "Docker"
if command -v docker >/dev/null; then
    echo "   allaqachon bor: $(docker --version)"
else
    sudo install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
        | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg --yes
    sudo chmod a+r /etc/apt/keyrings/docker.gpg
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" \
        | sudo tee /etc/apt/sources.list.d/docker.list >/dev/null
    sudo apt-get update -qq
    sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -qq \
        docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
    sudo usermod -aG docker "$USER"
    echo "   ornatildi. Guruh shu sessiyada kuchga kirmaydi — script sudo bilan davom etadi."
fi
# The group membership above does not apply to the shell already running, so
# everything below goes through sudo rather than failing on the first call.
DOCKER="sudo docker"

# ── 3. Code ────────────────────────────────────────────────────────────────
# One repository. This step used to clone pdaftar.backend as a sibling named
# exactly `backend`, because composer.json mapped `App\` into it — the POS
# could not boot without a checkout of a different product beside it. It no
# longer maps anything there.
step "Kod ($ROOT_DIR)"
sudo mkdir -p "$ROOT_DIR"
sudo chown "$USER:$USER" "$ROOT_DIR"

if [ -d "$POS_DIR/.git" ]; then
    echo "   pdaftar_pos bor"
else
    git clone "$POS_REPO" "$POS_DIR"
fi

# ── 4. .env ────────────────────────────────────────────────────────────────
# Generated, not copied from an example with its placeholder passwords left in.
step "pos_backend/.env"
ENV_FILE="$POS_DIR/pos_backend/.env"
if [ -f "$ENV_FILE" ]; then
    echo "   allaqachon bor — tegilmadi"
else
    db_pass="$(openssl rand -hex 24)"
    redis_pass="$(openssl rand -hex 24)"
    sed -e "s#^APP_URL=.*#APP_URL=https://$DOMAIN#" \
        -e "s#^APP_KEY=.*#APP_KEY=#" \
        -e "s#^DB_DATABASE=.*#DB_DATABASE=pos_staging#" \
        -e "s#^DB_PASSWORD=.*#DB_PASSWORD=$db_pass#" \
        -e "s#^REDIS_PASSWORD=.*#REDIS_PASSWORD=$redis_pass#" \
        -e "s#^L5_SWAGGER_CONST_HOST=.*#L5_SWAGGER_CONST_HOST=https://$DOMAIN#" \
        "$POS_DIR/pos_backend/.env.devdata.example" > "$ENV_FILE"
    # Compose reads this same file, so the stack picks the staging definition
    # up without anyone having to remember an -f flag. `docker compose up -d`
    # and the deploy script then do the right thing unchanged.
    printf '\n# Compose\nCOMPOSE_FILE=docker-compose.staging.yml\n' >> "$ENV_FILE"
    chmod 600 "$ENV_FILE"
    echo "   yaratildi, parollar tasodifiy"
fi

# ── 5. Stack ───────────────────────────────────────────────────────────────
step "Konteynerlar"
cd "$POS_DIR/pos_backend"
$DOCKER compose up -d --build

step "Bogliqliklar"
$DOCKER compose exec -T php-pos composer install --no-interaction --prefer-dist --no-dev --optimize-autoloader
grep -q '^APP_KEY=$' "$ENV_FILE" && $DOCKER compose exec -T php-pos php artisan key:generate --force

# ── Sxema ──────────────────────────────────────────────────────────────────
# The POS owns its database outright. pDaftar's ~147 tables are on pDaftar's
# own server, reached over the API, so nothing here imports or expects them —
# `migrate` creates the POS's tables and that is the whole schema.
# --force: no TTY here.
$DOCKER compose exec -T php-pos php artisan migrate --force

# ── 6. Public name ─────────────────────────────────────────────────────────
step "nginx + TLS ($DOMAIN)"
command -v nginx >/dev/null || sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -qq nginx
command -v certbot >/dev/null || sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -qq certbot python3-certbot-nginx

vhost=/etc/nginx/sites-available/$DOMAIN
if [ ! -f "$vhost" ]; then
    sed "s/POS_DOMAIN/$DOMAIN/g" "$POS_DIR/deploy/nginx/pos-staging.conf" | sudo tee "$vhost" >/dev/null
    sudo ln -sfn "$vhost" /etc/nginx/sites-enabled/$DOMAIN
    # Ubuntu's default vhost answers for every name on port 80, including this
    # one, and it wins by being first. certbot's HTTP-01 challenge then fails
    # on a box that looks correctly configured.
    sudo rm -f /etc/nginx/sites-enabled/default
fi
sudo nginx -t && sudo systemctl reload nginx

# The till is rsynced by CI and is not here on a fresh box. Without this,
# nginx answers / with 404 from a root that does not exist, which reads like a
# broken vhost rather than "nothing deployed yet".
#
# Created WITHOUT sudo, and that is the point: CI rsyncs into this directory
# as the deploy user. A root-owned dist/ fails the deploy with
# "mkdir .../assets failed: Permission denied", several steps away from the
# line that caused it.
mkdir -p "$POS_DIR/pos/dist"

if sudo test -d "/etc/letsencrypt/live/$DOMAIN"; then
    echo "   sertifikat bor"
elif [ -n "${CERTBOT_EMAIL:-}" ]; then
    sudo certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "$CERTBOT_EMAIL" --redirect \
        || echo "   OGOHLANTIRISH: certbot bajarilmadi. DNS $DOMAIN -> shu server ekanini tekshiring."
else
    echo "   TLS sertifikati yoq. DNS $DOMAIN -> shu server ekaniga ishonch hosil qiling, keyin:"
    echo "     sudo certbot --nginx -d $DOMAIN --redirect"
fi

# ── Gate ───────────────────────────────────────────────────────────────────
# pos:health was written for the design where the POS shares pDaftar's
# database, so most of what it asserts — the shared domain loads, the
# observers attach, the Redis namespace matches — describes an arrangement
# this server no longer has. It is run and shown because its output is still
# the fastest way to see what the POS thinks it is connected to, but it cannot
# pass here and must not stop provisioning.
#
# It stops being advisory when it is rewritten against the API integration.
# That is what flips POS_HEALTH_GATE to `on`, and a POS taking real sales
# must not run with it `off`.
step "Tekshiruv: pos:health (ma'lumot uchun)"
$DOCKER compose exec -T php-pos php artisan pos:health || true

if ! grep -q '^POS_HEALTH_GATE=' "$ENV_FILE"; then
    printf '\n# Deploy gate: pos:health API integratsiyasiga moslangach `on`\nPOS_HEALTH_GATE=off\n' >> "$ENV_FILE"
fi

cat <<DONE

Tayyor.

  Konteyner   http://127.0.0.1:8090/api/pos/v1/health
  Ommaviy     https://$DOMAIN/api/pos/v1/health
  Deploy gate POS_HEALTH_GATE=$(grep '^POS_HEALTH_GATE=' "$ENV_FILE" | cut -d= -f2)

Keyingi qadam — CI uchun kalit:
  ssh-keygen -t ed25519 -f ~/.ssh/pos_deploy -N ''
  cat ~/.ssh/pos_deploy.pub >> ~/.ssh/authorized_keys
  # yopiq kalitni GitHub repo secretiga qoying: POS_SSH_KEY
DONE
