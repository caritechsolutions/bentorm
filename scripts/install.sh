#!/usr/bin/env bash
# Bentorm backend installer / updater — Ubuntu 22.04 / 24.04
#
#   Run via scripts/bootstrap.sh (see README) — it updates the checkout then execs this file from /opt/bentorm.
#
# Optional environment variables (first run only):
#   ADMIN_PHONE=2461234567   first admin account (can also be set later in /opt/bentorm/backend/.env)
#   ADMIN_NAME="Richard"
#   FRESH=1                     drop and recreate all tables (DESTROYS DATA — pre-launch schema resets only)
#   API_HOST=api.ridecoop.net   public hostname NPM uses for the API  (sets APP_URL)
#   WS_HOST=ws.ridecoop.net     public hostname NPM uses for WebSockets (sets REVERB_HOST)
#   ADMIN_HOST=newadmin.ridecoop.net  public hostname NPM uses for the admin panel (nginx :8082)
#   STORE_HOST=store.ridecoop.net     optional hostname for the store portal (same :8082, opens /store)
#   FLEET_HOST=fleet.ridecoop.net     optional hostname for the fleet portal (same :8082, opens /fleet)
#   SUPPORT_HOST=support.ridecoop.net optional hostname for the help / support / statement pages the apps open
#                               (NPM → this server's API port :8080, like API_HOST)
#   GOOGLE_MAPS_KEY=AIza...      Google Maps Platform key: road distances (Routes API) + address search (Places)
#   SMTP_HOST, SMTP_PORT (465), SMTP_ENCRYPTION (ssl|tls|none), SMTP_USER, SMTP_PASSWORD, SMTP_FROM, SMTP_FROM_NAME
#                               email sender for sign-in codes and receipts (stored encrypted in the gateways table)
#   TWILIO_SID, TWILIO_TOKEN [, TWILIO_FROM | TWILIO_MESSAGING_SID]  SMS sender for sign-in codes
#   TURN_HOST=turn.ridecoop.net  install the call relay (coturn) here for in-app calls; the name must point at this
#                               server's public IP. Forward UDP+TCP 3478 and the relay ports (UDP 49160-49660) here.
#                               TURN_MIN_PORT / TURN_MAX_PORT change the relay port range (about 2 ports per phone per call).
#                               TURN_PUBLIC_IP=1.2.3.4 if the public IP can't be detected. Re-runs keep the secret.
#   FIREBASE_CREDENTIALS=/root/firebase.json  Firebase service-account key file, for push notifications
#                               (stored encrypted in the gateways table; delete the file afterwards)
#                               (no FROM: the account's first SMS-capable number is used)
#   Secrets are only needed once (or to change them). Start the command with a space so bash keeps
#   it out of your history, and use single quotes around passwords containing ! or $.
#   BRANCH=main
#
# Installs PHP 8.3 + extensions, Composer, MySQL 8, Redis, nginx; clones/updates the repo to
# /opt/bentorm; creates the database; writes .env; composer install; migrates + seeds; sets up
# nginx on :8080 (API) and :8082 (admin panel), Reverb WebSockets on :8081, a queue worker and the scheduler as systemd services.
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
if [[ -n "${STORE_HOST:-}" ]]; then
  set_env STORE_HOST "$STORE_HOST"
fi
if [[ -n "${FLEET_HOST:-}" ]]; then
  set_env FLEET_HOST "$FLEET_HOST"
fi
if [[ -n "${SUPPORT_HOST:-}" ]]; then
  set_env SUPPORT_HOST "$SUPPORT_HOST"
fi
if [[ -n "${ADMIN_HOST:-}" ]]; then
  set_env ADMIN_HOST "$ADMIN_HOST"
fi
if [[ -n "${GOOGLE_MAPS_KEY:-}" ]]; then
  set_env GOOGLE_MAPS_SERVER_KEY "$GOOGLE_MAPS_KEY"
  set_env GOOGLE_MAPS_CLIENT_KEY "$GOOGLE_MAPS_KEY"
  set_env DISTANCE_PROVIDER google
fi
if [[ -n "${WS_HOST:-}" ]]; then
  set_env REVERB_HOST "$WS_HOST"
  set_env REVERB_PORT 443
  set_env REVERB_SCHEME https
fi

# ---------------------------------------------------------------- app
log "Installing PHP dependencies"
cd "$BACKEND"
# composer.lock is not committed, so the server's lock goes stale whenever composer.json changes
# (new packages). Re-resolve when composer.json differs from the one the lock was built from.
export COMPOSER_ALLOW_SUPERUSER=1
JSON_SUM=$(sha256sum composer.json | cut -d' ' -f1)
SUM_FILE="$BACKEND/storage/composer.json.sha256"
if [[ ! -f composer.lock || ! -f "$SUM_FILE" || "$(cat "$SUM_FILE")" != "$JSON_SUM" ]]; then
  log "composer.json changed — resolving dependencies (composer update)"
  composer update --no-interaction --no-dev --optimize-autoloader --no-progress </dev/null
else
  composer install --no-interaction --no-dev --optimize-autoloader --no-progress </dev/null
fi
echo "$JSON_SUM" > "$SUM_FILE"

if ! grep -q '^APP_KEY=base64:' "$ENV_FILE"; then
  log "Generating application key"
  php artisan key:generate --force --quiet
fi

log "Running migrations and seeders"
php artisan optimize:clear --quiet
if [[ "${FRESH:-0}" == "1" ]]; then
  warn "FRESH=1 — dropping all tables and recreating them"
  php artisan migrate:fresh --force --no-interaction
else
  php artisan migrate --force --no-interaction
fi
php artisan db:seed --force --no-interaction
# Store logos and product photos are public files (storage/app/public → public/storage).
php artisan storage:link --force --quiet 2>/dev/null || true
ADMIN_LOGIN=$(php artisan bentorm:admin-password --if-missing 2>&1 || true)

# ---------------------------------------------------------------- gateways (optional, from env)
if [[ -n "${SMTP_HOST:-}" ]]; then
  log "Configuring email gateway (SMTP $SMTP_HOST)"
  SMTP_ARGS=(--set "host=$SMTP_HOST" --set "port=${SMTP_PORT:-465}" --set "encryption=${SMTP_ENCRYPTION:-ssl}")
  [[ -n "${SMTP_USER:-}" ]] && SMTP_ARGS+=(--set "username=$SMTP_USER")
  [[ -n "${SMTP_PASSWORD:-}" ]] && SMTP_ARGS+=(--set "password=$SMTP_PASSWORD")
  SMTP_ARGS+=(--set "from_address=${SMTP_FROM:-${SMTP_USER:-}}" --set "from_name=${SMTP_FROM_NAME:-Ride Coop}")
  php artisan bentorm:gateway smtp --name="Email (SMTP)" "${SMTP_ARGS[@]}" --test 2>&1 | sed 's/^/    /' || warn "Email gateway test failed - see above"
fi
if [[ -n "${FIREBASE_CREDENTIALS:-}" ]]; then
  # Path to the Firebase service-account key file (Firebase console → Project settings → Service accounts).
  log "Configuring push notifications (Firebase)"
  if [[ -r "$FIREBASE_CREDENTIALS" ]]; then
    php artisan bentorm:gateway fcm --name="Push (Firebase)" --set-file "service_account=$FIREBASE_CREDENTIALS" --test 2>&1 | sed 's/^/    /' || warn "Firebase test failed - see above"
  else
    warn "FIREBASE_CREDENTIALS: cannot read $FIREBASE_CREDENTIALS"
  fi
fi
if [[ -n "${TWILIO_SID:-}" ]]; then
  # TWILIO_FROM is optional: without it the first SMS-capable number on the account is used.
  log "Configuring SMS gateway (Twilio)"
  TW_ARGS=(--set "account_sid=$TWILIO_SID")
  [[ -n "${TWILIO_TOKEN:-}" ]] && TW_ARGS+=(--set "auth_token=$TWILIO_TOKEN")
  [[ -n "${TWILIO_FROM:-}" ]] && TW_ARGS+=(--set "from=$TWILIO_FROM")
  [[ -n "${TWILIO_MESSAGING_SID:-}" ]] && TW_ARGS+=(--set "messaging_service_sid=$TWILIO_MESSAGING_SID")
  php artisan bentorm:gateway twilio --name="SMS (Twilio)" "${TW_ARGS[@]}" --test 2>&1 | sed 's/^/    /' || warn "Twilio test failed - see above"
fi

# ---------------------------------------------------------------- call relay (optional)
if [[ -n "${TURN_HOST:-}" ]] || dpkg -s coturn >/dev/null 2>&1; then
  TURN_HOST="${TURN_HOST:-$(grep '^TURN_URLS=' "$ENV_FILE" | sed -E 's|^TURN_URLS=turn:([^:]+):.*|\1|' || true)}"
  log "Configuring the call relay (coturn) for $TURN_HOST"
  DEBIAN_FRONTEND=noninteractive apt-get install -y -qq coturn >/dev/null
  TURN_SECRET=$(grep '^TURN_SECRET=' "$ENV_FILE" | cut -d= -f2 || true)
  [[ -z "$TURN_SECRET" ]] && TURN_SECRET=$(rand 'A-Za-z0-9' 40)
  PUBLIC_IP="${TURN_PUBLIC_IP:-$(curl -fsS --max-time 5 https://api.ipify.org 2>/dev/null || true)}"
  PRIVATE_IP=$(hostname -I 2>/dev/null | awk '{print $1}')
  # Relay ports: each call uses ~2 per phone. Keep the range already forwarded unless a new one is given.
  OLD_MIN=$(grep -oP '^min-port=\K[0-9]+' /etc/turnserver.conf 2>/dev/null || true)
  OLD_MAX=$(grep -oP '^max-port=\K[0-9]+' /etc/turnserver.conf 2>/dev/null || true)
  TURN_MIN_PORT="${TURN_MIN_PORT:-${OLD_MIN:-49160}}"
  TURN_MAX_PORT="${TURN_MAX_PORT:-${OLD_MAX:-49660}}"
  EXTERNAL="${PUBLIC_IP:+external-ip=$PUBLIC_IP${PRIVATE_IP:+/$PRIVATE_IP}}"
  cat > /etc/turnserver.conf <<TURN
# Ride Coop call relay — written by scripts/install.sh. The API hands apps short-lived logins (use-auth-secret).
listening-port=3478
min-port=$TURN_MIN_PORT
max-port=$TURN_MAX_PORT
fingerprint
use-auth-secret
static-auth-secret=$TURN_SECRET
realm=$TURN_HOST
$EXTERNAL
no-cli
no-multicast-peers
no-tlsv1
no-tlsv1_1
# Never relay into private networks (the server's own LAN).
denied-peer-ip=10.0.0.0-10.255.255.255
denied-peer-ip=172.16.0.0-172.31.255.255
denied-peer-ip=192.168.0.0-192.168.255.255
total-quota=300
TURN
  sed -i 's/^#\?TURNSERVER_ENABLED=.*/TURNSERVER_ENABLED=1/' /etc/default/coturn 2>/dev/null || true
  systemctl enable --now coturn >/dev/null 2>&1 || true
  systemctl restart coturn
  set_env TURN_URLS "turn:$TURN_HOST:3478?transport=udp,turn:$TURN_HOST:3478?transport=tcp"
  set_env TURN_SECRET "$TURN_SECRET"
  if [[ -z "$PUBLIC_IP" ]]; then
    warn "Could not detect the public IP — set TURN_PUBLIC_IP=... and run again (calls behind NAT need it)."
  fi
fi

log "Caching configuration and routes"
php artisan config:cache --quiet
php artisan route:cache --quiet
php artisan event:cache --quiet
php artisan filament:assets --quiet
php artisan filament:optimize-clear --quiet || true
php artisan filament:optimize --quiet || true

log "Configuring PHP upload limits (document photos)"
cat > /etc/php/8.3/fpm/conf.d/99-bentorm.ini <<INI
upload_max_filesize = 20M
post_max_size = 25M
memory_limit = 256M
max_execution_time = 60
INI
mkdir -p "$BACKEND/storage/app/private"

log "Setting permissions"
chown -R www-data:www-data "$INSTALL_DIR"
chmod -R ug+rwx "$BACKEND/storage" "$BACKEND/bootstrap/cache"

# ---------------------------------------------------------------- services
log "Configuring nginx (:$API_PORT API, :8082 admin panel)"
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
echo "  Admin panel : http://$IP:8082/admin  ($(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:8082/admin/login"))${ADMIN_HOST:+  ->  https://$ADMIN_HOST}"
echo "  Store portal: http://$IP:8082/store  ($(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:8082/store/login"))${ADMIN_HOST:+  ->  https://$ADMIN_HOST/store}"
FLEET_HOST_SET=$(grep -E '^FLEET_HOST=' "$ENV_FILE" | cut -d= -f2- || true)
echo "  Fleet portal: http://$IP:8082/fleet  ($(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:8082/fleet/login"))${FLEET_HOST_SET:+  ->  https://$FLEET_HOST_SET}"
echo "  Google Maps : $(grep -q '^GOOGLE_MAPS_SERVER_KEY=.\+' "$ENV_FILE" && echo "configured (distance: $(grep '^DISTANCE_PROVIDER=' "$ENV_FILE" | cut -d= -f2))" || echo "not set — pass GOOGLE_MAPS_KEY=... (address search off, straight-line distances)")"
echo "  SMS         : $(php artisan tinker --execute='$g=app(App\Plugins\PluginManager::class)->gatewayFor(App\Enums\GatewayType::Sms); echo $g ? "{$g->name} ({$g->plugin})".($g->last_test_result ? " - last test {$g->last_test_result}" : "") : "none (codes only in the log)";' 2>/dev/null | tail -1)"
echo "  Email       : $(php artisan tinker --execute='$g=app(App\Plugins\PluginManager::class)->gatewayFor(App\Enums\GatewayType::Email); echo $g ? "{$g->name} ({$g->plugin})".($g->last_test_result ? " - last test {$g->last_test_result}" : "") : "none";' 2>/dev/null | tail -1)"
echo "  Push        : $(php artisan tinker --execute='$g=app(App\Plugins\PluginManager::class)->gatewayFor(App\Enums\GatewayType::Push); echo $g ? "{$g->name} ({$g->plugin})".($g->last_test_result ? " - last test {$g->last_test_result}" : "") : "none (apps must be open to see new rides)";' 2>/dev/null | tail -1)"
echo "  Call relay  : $(systemctl is-active coturn 2>/dev/null | grep -qx active && echo "coturn active (turn:$(grep '^TURN_URLS=' "$ENV_FILE" | sed -E 's|^TURN_URLS=turn:([^:]+):.*|\1|'):3478) — forward UDP+TCP 3478 and UDP $(grep -oP '^min-port=\K[0-9]+' /etc/turnserver.conf)-$(grep -oP '^max-port=\K[0-9]+' /etc/turnserver.conf) to $IP" || echo "not installed — pass TURN_HOST=turn.yourdomain to add it (calls still work on most networks)")"
echo "  Config      : $ENV_FILE"
echo
echo "$ADMIN_LOGIN" | sed 's/^/  /'
echo
}

main "$@" </dev/null
