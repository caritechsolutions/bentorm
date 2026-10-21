#!/usr/bin/env bash
# Bentorm backend installer / updater — Ubuntu 22.04 / 24.04
#
#   curl -fsSL https://raw.githubusercontent.com/caritechsolutions/bentorm/main/scripts/install.sh | sudo bash
#
# Optional environment variables (first run only):
#   ADMIN_PHONE=2461234567   first admin account (can also be set later in /opt/bentorm/backend/.env)
#   ADMIN_NAME="Richard"
#   API_HOST=api.ridecoop.net   public hostname NPM uses for the API  (sets APP_URL)
#   WS_HOST=ws.ridecoop.net     public hostname NPM uses for WebSockets (sets REVERB_HOST)
#   BRANCH=main
#
# Installs PHP 8.3 + extensions, Composer, MySQL 8, Redis, nginx; clones/updates the repo to
# /opt/bentorm; creates the database; writes .env; composer install; migrates + seeds; sets up
# nginx on :8080, Reverb WebSockets on :8081, a queue worker and the scheduler as systemd services.
# Safe to re-run — re-running pulls the latest code and restarts the services.

set -euo pipefail

REPO_URL="https://github.com/caritechsolutions/bentorm"
INSTALL_DIR="/opt/bentorm"
BRANCH="${BRANCH:-main}"
DB_NAME="bentorm"
DB_USER="bentorm"
API_PORT=8080
WS_PORT=8081

log()  { echo -e "\e[1;32m==>\e[0m $*"; }
warn() { echo -e "\e[1;33m!!\e[0m $*"; }
die()  { echo -e "\e[1;31mERROR:\e[0m $*" >&2; exit 1; }
rand() { # rand <chars> <length> — pipe-safe random string
  head -c 512 /dev/urandom | tr -dc "$1" | cut -c1-"$2"
}

main() {
trap 'die "Installer failed at line $LINENO: $BASH_COMMAND (exit $?)"' ERR

[[ $EUID -eq 0 ]] || die "Run as root (curl ... | sudo bash)"
command -v apt-get >/dev/null || die "This installer supports Ubuntu/Debian only"
export DEBIAN_FRONTEND=noninteractive

# ---------------------------------------------------------------- packages
log "Installing base packages"
apt-get update -qq
apt-get install -y -qq software-properties-common ca-certificates curl git unzip lsb-release gnupg >/dev/null

if ! php -v 2>/dev/null | grep -q "PHP 8.3"; then
  log "Adding PHP 8.3 repository (ondrej/php)"
  add-apt-repository -y ppa:ondrej/php >/dev/null 2>&1 || true
  apt-get update -qq
fi

log "Installing PHP 8.3, nginx, MySQL 8, Redis"
apt-get install -y -qq \
  php8.3-cli php8.3-fpm php8.3-mysql php8.3-redis php8.3-mbstring php8.3-xml \
  php8.3-curl php8.3-zip php8.3-bcmath php8.3-intl php8.3-gd php8.3-sqlite3 \
  nginx mysql-server redis-server >/dev/null
update-alternatives --set php /usr/bin/php8.3 >/dev/null 2>&1 || true
systemctl enable --now mysql redis-server php8.3-fpm nginx >/dev/null

if ! command -v composer >/dev/null; then
  log "Installing Composer"
  EXPECTED=$(curl -fsSL https://composer.github.io/installer.sig)
  curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
  ACTUAL=$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")
  [[ "$EXPECTED" == "$ACTUAL" ]] || die "Composer installer checksum mismatch"
  php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi

# ---------------------------------------------------------------- repo
git config --global --add safe.directory "$INSTALL_DIR" >/dev/null 2>&1 || true
if [[ -d "$INSTALL_DIR/.git" ]]; then
  log "Updating checkout in $INSTALL_DIR ($BRANCH)"
  git -C "$INSTALL_DIR" fetch origin "$BRANCH" </dev/null
  git -C "$INSTALL_DIR" reset --hard "origin/$BRANCH" </dev/null
else
  log "Cloning $REPO_URL to $INSTALL_DIR"
  git clone -b "$BRANCH" "$REPO_URL" "$INSTALL_DIR" </dev/null
fi
BACKEND="$INSTALL_DIR/backend"
ENV_FILE="$BACKEND/.env"

# ---------------------------------------------------------------- database
if [[ -f "$ENV_FILE" ]] && grep -q '^DB_PASSWORD=.\+' "$ENV_FILE"; then
  DB_PASS=$(grep '^DB_PASSWORD=' "$ENV_FILE" | cut -d= -f2-)
else
  DB_PASS=$(rand 'A-Za-z0-9' 32)
fi

log "Ensuring database '$DB_NAME' and user '$DB_USER'"
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL

# ---------------------------------------------------------------- .env
set_env() { # key value — set or replace KEY=value in .env
  if grep -q "^$1=" "$ENV_FILE"; then
    sed -i "s|^$1=.*|$1=$2|" "$ENV_FILE"
  else
    echo "$1=$2" >> "$ENV_FILE"
  fi
}

if [[ ! -f "$ENV_FILE" ]]; then
  log "Writing .env"
  cp "$BACKEND/.env.example" "$ENV_FILE"
  set_env APP_ENV production
  set_env APP_DEBUG false
  set_env LOG_LEVEL info
  set_env REVERB_APP_KEY "$(rand 'a-z0-9' 24)"
  set_env REVERB_APP_SECRET "$(rand 'A-Za-z0-9' 40)"
  [[ -n "${ADMIN_PHONE:-}" ]] && set_env ADMIN_PHONE "$ADMIN_PHONE"
  [[ -n "${ADMIN_NAME:-}" ]] && set_env ADMIN_NAME "\"$ADMIN_NAME\""
fi
set_env DB_PASSWORD "$DB_PASS"
if [[ -n "${API_HOST:-}" ]]; then
  set_env APP_URL "https://$API_HOST"
fi
if [[ -n "${WS_HOST:-}" ]]; then
  set_env REVERB_HOST "$WS_HOST"
  set_env REVERB_PORT 443
  set_env REVERB_SCHEME https
fi

# ---------------------------------------------------------------- app
log "Installing PHP dependencies"
cd "$BACKEND"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --no-dev --optimize-autoloader --quiet </dev/null

if ! grep -q '^APP_KEY=base64:' "$ENV_FILE"; then
  log "Generating application key"
  php artisan key:generate --force --quiet
fi

log "Running migrations and seeders"
php artisan optimize:clear --quiet
php artisan migrate --force --no-interaction
php artisan db:seed --force --no-interaction

log "Caching configuration and routes"
php artisan config:cache --quiet
php artisan route:cache --quiet
php artisan event:cache --quiet

log "Setting permissions"
chown -R www-data:www-data "$INSTALL_DIR"
chmod -R ug+rwx "$BACKEND/storage" "$BACKEND/bootstrap/cache"

# ---------------------------------------------------------------- services
log "Configuring nginx (:$API_PORT)"
install -m 644 "$INSTALL_DIR/scripts/deploy/nginx-bentorm.conf" /etc/nginx/sites-available/bentorm.conf
ln -sf /etc/nginx/sites-available/bentorm.conf /etc/nginx/sites-enabled/bentorm.conf
rm -f /etc/nginx/sites-enabled/default
nginx -t -q && systemctl reload nginx

log "Installing systemd services (queue, reverb, scheduler)"
for unit in bentorm-queue bentorm-reverb bentorm-scheduler; do
  install -m 644 "$INSTALL_DIR/scripts/deploy/$unit.service" "/etc/systemd/system/$unit.service"
done
systemctl daemon-reload
systemctl enable --now bentorm-queue bentorm-reverb bentorm-scheduler >/dev/null
systemctl restart bentorm-queue bentorm-reverb bentorm-scheduler
systemctl restart php8.3-fpm

# ---------------------------------------------------------------- verify
sleep 2
HEALTH=$(curl -fsS "http://127.0.0.1:$API_PORT/api/v1/health" 2>/dev/null || echo "FAILED")
IP=$(hostname -I 2>/dev/null | awk '{print $1}')

echo
log "Done."
echo "  Install dir : $INSTALL_DIR"
echo "  Laravel     : $(php artisan --version)"
echo "  PHP         : $(php -r 'echo PHP_VERSION;')"
echo "  MySQL       : $(mysql -N -e 'SELECT VERSION();')"
echo "  Redis       : $(redis-cli ping)"
echo "  API         : http://$IP:$API_PORT/api/v1  -> $HEALTH"
echo "  WebSockets  : ws://$IP:$WS_PORT  ($(systemctl is-active bentorm-reverb))"
echo "  Queue       : $(systemctl is-active bentorm-queue)"
echo "  Scheduler   : $(systemctl is-active bentorm-scheduler)"
echo "  Config      : $ENV_FILE"
echo
echo "  Next: point Nginx Proxy Manager at :$API_PORT (API) and :$WS_PORT (WebSockets, enable 'Websockets Support'),"
echo "        then set APP_URL and REVERB_HOST/REVERB_SCHEME in .env and re-run this script."
}

main "$@" </dev/null
