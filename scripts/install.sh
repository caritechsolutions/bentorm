#!/usr/bin/env bash
# Bentorm backend installer — Ubuntu 22.04 / 24.04
# Usage: curl -fsSL https://raw.githubusercontent.com/caritechsolutions/bentorm/main/scripts/install.sh | sudo bash
#
# Installs PHP 8.3 + extensions, Composer, MySQL 8, Redis, clones the repo to
# /opt/bentorm, creates the database and user, writes .env, runs composer install,
# generates the app key and runs migrations. Safe to re-run.

set -euo pipefail

REPO_URL="https://github.com/caritechsolutions/bentorm"
INSTALL_DIR="/opt/bentorm"
DB_NAME="bentorm"
DB_USER="bentorm"
BRANCH="main"

log()  { echo -e "\e[1;32m==>\e[0m $*"; }
warn() { echo -e "\e[1;33m!!\e[0m $*"; }
die()  { echo -e "\e[1;31mERROR:\e[0m $*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "Run as root (curl ... | sudo bash)"
command -v apt-get >/dev/null || die "This installer supports Ubuntu/Debian only"

export DEBIAN_FRONTEND=noninteractive

# ---------------------------------------------------------------- packages
log "Updating apt and installing base packages"
apt-get update -qq
apt-get install -y -qq software-properties-common ca-certificates curl git unzip lsb-release gnupg >/dev/null

if ! php -v 2>/dev/null | grep -q "PHP 8.3"; then
  log "Adding PHP 8.3 repository (ondrej/php)"
  add-apt-repository -y ppa:ondrej/php >/dev/null 2>&1 || true
  apt-get update -qq
fi

log "Installing PHP 8.3 and extensions"
apt-get install -y -qq \
  php8.3-cli php8.3-fpm php8.3-mysql php8.3-redis php8.3-mbstring php8.3-xml \
  php8.3-curl php8.3-zip php8.3-bcmath php8.3-intl php8.3-gd >/dev/null
update-alternatives --set php /usr/bin/php8.3 >/dev/null 2>&1 || true

log "Installing MySQL 8 and Redis"
apt-get install -y -qq mysql-server redis-server >/dev/null
systemctl enable --now mysql redis-server >/dev/null

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
if [[ -d "$INSTALL_DIR/.git" ]]; then
  log "Updating existing checkout in $INSTALL_DIR"
  git -C "$INSTALL_DIR" fetch -q origin "$BRANCH"
  git -C "$INSTALL_DIR" reset -q --hard "origin/$BRANCH"
else
  log "Cloning $REPO_URL to $INSTALL_DIR"
  git clone -q -b "$BRANCH" "$REPO_URL" "$INSTALL_DIR"
fi
BACKEND="$INSTALL_DIR/backend"

# ---------------------------------------------------------------- database
ENV_FILE="$BACKEND/.env"
if [[ -f "$ENV_FILE" ]] && grep -q '^DB_PASSWORD=.\+' "$ENV_FILE"; then
  DB_PASS=$(grep '^DB_PASSWORD=' "$ENV_FILE" | cut -d= -f2-)
  log "Reusing existing database password from .env"
else
  DB_PASS=$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 32)
fi

log "Creating database '$DB_NAME' and user '$DB_USER' (idempotent)"
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL

# ---------------------------------------------------------------- .env
if [[ ! -f "$ENV_FILE" ]]; then
  log "Writing .env"
  cp "$BACKEND/.env.example" "$ENV_FILE"
fi
sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=$DB_PASS|" "$ENV_FILE"

# ---------------------------------------------------------------- app
log "Installing PHP dependencies"
cd "$BACKEND"
COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --no-dev --optimize-autoloader --quiet

if ! grep -q '^APP_KEY=base64:' "$ENV_FILE"; then
  log "Generating application key"
  php artisan key:generate --force --quiet
fi

log "Running migrations"
php artisan migrate --force --no-interaction

log "Setting permissions"
chown -R www-data:www-data "$INSTALL_DIR"
chmod -R ug+rwx "$BACKEND/storage" "$BACKEND/bootstrap/cache"

echo
log "Done."
echo "  Install dir : $INSTALL_DIR"
echo "  Laravel     : $(php artisan --version)"
echo "  PHP         : $(php -r 'echo PHP_VERSION;')"
echo "  MySQL       : $(mysql -N -e 'SELECT VERSION();')"
echo "  Redis       : $(redis-cli ping)"
echo "  DB password : stored in $ENV_FILE"
