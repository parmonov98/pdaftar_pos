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
#   3. the sibling layout this repo cannot run without (see README)
#   4. pos_backend/.env, generated, with real random secrets
#   5. the stack, then the schema
#   6. host nginx + TLS in front of the container on 127.0.0.1:8090
#
# It is safe to run again: every step checks before it acts. It does NOT
# deploy code — that is the CI workflow's job (.github/workflows/deploy-staging.yml).
set -euo pipefail

DOMAIN="${1:-}"
[ -n "$DOMAIN" ] || { echo "Foydalanish: $0 <domain>   masalan: $0 pos-staging.pdaftar.uz" >&2; exit 2; }

ROOT_DIR=/var/www/pos
POS_DIR="$ROOT_DIR/pdaftar_pos"
BACKEND_DIR="$ROOT_DIR/backend"
POS_REPO="${POS_REPO:-https://github.com/parmonov98/pdaftar_pos.git}"
BACKEND_REPO="${BACKEND_REPO:-git@github.com:parmonov98/pdaftar.backend.git}"

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

# ── 3. Sibling layout ──────────────────────────────────────────────────────
# pos_backend/composer.json maps `App\` to ../../backend/app/. The directory
# names are part of that contract, not a preference.
step "Kod ($ROOT_DIR)"
sudo mkdir -p "$ROOT_DIR"
sudo chown "$USER:$USER" "$ROOT_DIR"

if [ -d "$POS_DIR/.git" ]; then
    echo "   pdaftar_pos bor"
else
    git clone "$POS_REPO" "$POS_DIR"
fi

if [ -d "$BACKEND_DIR/.git" ]; then
    echo "   backend bor"
else
    git clone --depth 50 "$BACKEND_REPO" "$BACKEND_DIR" \
        || die "pdaftar.backend klon qilinmadi. Bu yopiq repo — shu serverning SSH kaliti
GitHub akkauntga qoshilganmi? Tekshirish:  ssh -T git@github.com"
fi
[ -f "$BACKEND_DIR/bootstrap/helpers.php" ] \
    || die "$BACKEND_DIR ichida bootstrap/helpers.php yoq — kutilgan repo emas."

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

# ── pDaftar sxemasi ────────────────────────────────────────────────────────
# The POS owns exactly two tables. The other ~145 are pDaftar's, and they
# cannot be created here: pos_backend requires 9 composer packages, pDaftar's
# migrations assume 34 (Filament, Telescope, Horizon, ...), so running them
# from this app dies partway through and leaves a half-built schema — which is
# worse than none, because `migrate` then believes it has run.
#
# So the schema arrives as a dump. Structure only by default: a staging till
# has no business holding real shops' books.
step "pDaftar sxemasi"
db_name="$(grep '^DB_DATABASE=' "$ENV_FILE" | cut -d= -f2-)"
has_shops="$($DOCKER compose exec -T mariadb sh -c \
    'mysql -uroot -p"$MARIADB_ROOT_PASSWORD" -N -B -e "select count(*) from information_schema.tables where table_schema=\"'"$db_name"'\" and table_name=\"shops\""' 2>/dev/null | tr -d '\r')"

if [ "${has_shops:-0}" = "0" ]; then
    dump=""
    for candidate in "$ROOT_DIR/pdaftar-schema.sql.gz" "$ROOT_DIR/pdaftar-schema.sql"; do
        [ -f "$candidate" ] && { dump="$candidate"; break; }
    done

    if [ -n "$dump" ]; then
        echo "   import: $dump"
        if [ "${dump##*.}" = "gz" ]; then gunzip -c "$dump"; else cat "$dump"; fi \
            | $DOCKER compose exec -T mariadb sh -c \
                'mysql -uroot -p"$MARIADB_ROOT_PASSWORD" "'"$db_name"'"' \
            || die "sxema importi muvaffaqiyatsiz."
    else
        # Not fatal, deliberately. Deployment and the schema are separate
        # decisions: the pipeline can be stood up and proven while how the POS
        # reaches a pDaftar database is still open. What must NOT pass
        # silently is the consequence — an empty database is a POS that takes
        # a sale and writes it nowhere. So it is loud here, pos:health says it
        # on every deploy, and POS_HEALTH_GATE decides whether it stops one.
        SCHEMA_MISSING=1
        printf '\n   \033[33mOGOHLANTIRISH: pDaftar sxemasi yoq.\033[0m\n'
        cat <<WARN
   POS ishga tushadi, lekin SOTUV YOZA OLMAYDI. Deploy quvuri baribir ishlaydi.

   Sxema kerak bo'lganda (MA'LUMOTSIZ):
     ssh devdata 'sudo mysqldump --no-data --single-transaction \
         --routines --events api_pdaftar_devdata_uz | gzip' > pdaftar-schema.sql.gz
     scp pdaftar-schema.sql.gz <bu-server>:$ROOT_DIR/
     ./scripts/provision-staging.sh $DOMAIN
WARN
    fi
else
    echo "   allaqachon bor"
fi

# The POS's own two tables, on top of pDaftar's. --force: no TTY here.
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
sudo mkdir -p "$POS_DIR/pos/dist"

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
step "Yakuniy tekshiruv: pos:health"
if $DOCKER compose exec -T php-pos php artisan pos:health; then
    :
elif [ "${SCHEMA_MISSING:-0}" = "1" ]; then
    # Expected: there is no pDaftar schema yet, so the checks that read it
    # cannot pass. Said plainly rather than swallowed.
    printf '\n\033[33mpos:health xato qaytardi — sxema yoqligi uchun, kutilgan holat.\033[0m\n'
    printf 'Quvur tayyor; POS sotuv yoza olmaydi.\n'
else
    die "pos:health xato qaytardi va buni sxema yoqligi bilan izohlab bolmaydi.
POSni trafikka qoymang — bu xatolar jimgina buziladi."
fi

# The deploy gate, written where the deploy reads it. On by default: a release
# that breaks the link to pDaftar's domain must stop. Off only while the
# database question is open, because until then every deploy would fail on the
# one thing nobody has decided yet.
if ! grep -q '^POS_HEALTH_GATE=' "$ENV_FILE"; then
    if [ "${SCHEMA_MISSING:-0}" = "1" ]; then
        printf '\n# Deploy gate: sxema kelgach `on` qiling\nPOS_HEALTH_GATE=off\n' >> "$ENV_FILE"
    else
        printf '\n# Deploy gate\nPOS_HEALTH_GATE=on\n' >> "$ENV_FILE"
    fi
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
