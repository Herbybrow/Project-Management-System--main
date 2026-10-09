#!/usr/bin/env bash
# Run as edward through an interactive SSH terminal. It only targets Site 2.
set -Eeuo pipefail
IFS=$'\n\t'

BACKEND=/var/www/nssf-project-mgmt-2
FRONTEND=/var/www/nssf-frontend-2
DB_NAME=site2_db
DB_USER=site2_user
DB_HOST=127.0.0.1
DB_PORT=5432
FRONTEND_PORT=8080
BACKEND_PORT=8001
FRONTEND_ORIGIN=http://147.182.164.148:8080
BACKEND_URL=http://147.182.164.148:8001
BACKEND_ARCHIVE="$HOME/nssf-backend-2-20261009.tgz"
FRONTEND_ARCHIVE="$HOME/nssf-frontend-2-20261009.tgz"
BACKEND_SHA=AC119234EB6A34EE7112A9D54E3DD8274D995DE462936DB1CA7F78E944D54236
FRONTEND_SHA=97ED61D82A97E77987AACE6931110298585DB37D7DB625C84C5DC0E153995453
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
BACKEND_STAGE=
FRONTEND_STAGE=

say() { printf '\n==> %s\n' "$*"; }
fail() { printf '\nSITE 2 DEPLOYMENT ABORTED: %s\n' "$*" >&2; exit 1; }
require() { command -v "$1" >/dev/null 2>&1 || fail "Missing required command: $1"; }

cleanup() {
  local result=$?
  if (( result == 0 )); then
    [[ -n "$BACKEND_STAGE" ]] && rm -rf -- "$BACKEND_STAGE"
    [[ -n "$FRONTEND_STAGE" ]] && rm -rf -- "$FRONTEND_STAGE"
  else
    printf '\nSite 2 staging retained for diagnosis: %s %s\n' "$BACKEND_STAGE" "$FRONTEND_STAGE" >&2
  fi
}
trap cleanup EXIT

verify_archive() {
  local archive=$1 expected=$2 label=$3 actual
  [[ -f "$archive" ]] || fail "$label archive is missing: $archive"
  actual=$(sha256sum "$archive" | awk '{print toupper($1)}')
  [[ "$actual" == "$expected" ]] || fail "$label archive checksum differs from the prepared release."
}

backup_site2_file() {
  local target=$1
  if sudo test -e "$target" || sudo test -L "$target"; then
    sudo cp -a -- "$target" "$target.before-site2-$STAMP"
    say "Backed up Site 2 file $target"
  fi
}

env_set() {
  local key=$1 value=$2
  ENV_KEY="$key" ENV_VALUE="$value" php -r '
    $path = ".env";
    $key = getenv("ENV_KEY");
    $value = getenv("ENV_VALUE");
    $body = file_get_contents($path);
    if ($body === false || $key === false || $value === false) exit(1);
    $line = $key."=".var_export($value, true);
    $pattern = "/^".preg_quote($key, "/")."=.*/m";
    $next = preg_match($pattern, $body)
      ? preg_replace($pattern, $line, $body, 1)
      : rtrim($body).PHP_EOL.$line.PHP_EOL;
    file_put_contents($path, $next);
  ' || fail "Could not update the protected Site 2 environment file."
}

site2_password_present() {
  php -r '
    $body = file_get_contents(".env");
    preg_match("/^DB_PASSWORD=(.*)$/m", (string) $body, $m);
    $value = trim(trim($m[1] ?? ""), "\"'\''");
    exit($value !== "" && $value !== "<SET_MANUALLY>" ? 0 : 1);
  '
}

app_key_present() {
  php -r '
    $body = file_get_contents(".env");
    preg_match("/^APP_KEY=(.*)$/m", (string) $body, $m);
    exit(trim(trim($m[1] ?? ""), "\"'\''") !== "" ? 0 : 1);
  '
}

artisan() {
  env -u DB_URL -u DB_CONNECTION -u DB_HOST -u DB_PORT -u DB_DATABASE -u DB_USERNAME \
    php artisan "$@"
}

assert_site2_config() {
  env -u DB_URL -u DB_CONNECTION -u DB_HOST -u DB_PORT -u DB_DATABASE -u DB_USERNAME \
    php -r '
      require "vendor/autoload.php";
      $app = require "bootstrap/app.php";
      $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
      exit(
        config("database.default") === "pgsql"
        && config("database.connections.pgsql.database") === "site2_db"
        && config("database.connections.pgsql.host") === "127.0.0.1"
        && (string) config("database.connections.pgsql.port") === "5432"
        && config("database.connections.pgsql.username") === "site2_user" ? 0 : 1
      );
    ' || fail "Laravel configuration is not restricted to site2_db/site2_user."
}

migration_repository_exists() {
  env -u DB_URL -u DB_CONNECTION -u DB_HOST -u DB_PORT -u DB_DATABASE -u DB_USERNAME \
    php -r '
      require "vendor/autoload.php";
      $app = require "bootstrap/app.php";
      $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
      $connection = config("database.default");
      exit(Illuminate\Support\Facades\Schema::connection($connection)->hasTable("migrations") ? 0 : 1);
    '
}

http_code() {
  curl --silent --show-error --max-time 15 -o /dev/null -w '%{http_code}' "$1" || true
}

verify_unauthenticated_json_api() {
  local api_url headers body status
  api_url="$1"
  headers=$(mktemp)
  body=$(mktemp)

  status=$(curl --silent --show-error --max-time 15 \
    -D "$headers" -o "$body" -w '%{http_code}' \
    -H 'Accept: application/json' \
    "$api_url" || true)

  if [[ "$status" != 401 ]]; then
    rm -f -- "$headers" "$body"
    fail "Unauthenticated Site 2 API expected JSON HTTP 401, got $status."
  fi

  if ! grep -qi '^Content-Type:[[:space:]]*application/json' "$headers"; then
    rm -f -- "$headers" "$body"
    fail "Unauthenticated Site 2 API did not return a JSON content type."
  fi

  if ! php -r '$body = file_get_contents($argv[1]); $json = json_decode($body, true); exit(is_array($json) && isset($json["message"]) && is_string($json["message"]) ? 0 : 1);' "$body"; then
    rm -f -- "$headers" "$body"
    fail "Unauthenticated Site 2 API did not return the expected JSON error body."
  fi

  rm -f -- "$headers" "$body"
}

assert_existing_site() {
  [[ "$2" =~ ^[1-4][0-9][0-9]$ ]] || fail "$1 is unavailable (HTTP $2)."
}

site2_listener_is_enabled() {
  local port=$1 site
  case "$port" in
    "$FRONTEND_PORT") site=/etc/nginx/sites-enabled/nssf-frontend-2 ;;
    "$BACKEND_PORT") site=/etc/nginx/sites-enabled/nssf-backend-2 ;;
    *) return 1 ;;
  esac
  sudo test -L "$site" && sudo test "$(sudo readlink -f "$site")" = "/etc/nginx/sites-available/$(basename "$site")"
}

say "Checking prerequisites, archive integrity, and Site 2 directories"
for command in awk composer curl find grep nginx php php-fpm8.3 readlink sha256sum ss sudo systemctl tar; do
  require "$command"
done
php -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' \
  || fail "PHP 8.3 or newer is required."
verify_archive "$BACKEND_ARCHIVE" "$BACKEND_SHA" Backend
verify_archive "$FRONTEND_ARCHIVE" "$FRONTEND_SHA" Frontend
[[ -d "$BACKEND" ]] || fail "Missing Site 2 backend directory: $BACKEND"
[[ -d "$FRONTEND" ]] || fail "Missing Site 2 frontend directory: $FRONTEND"

say "Recording Site 1 baseline without changing it"
SITE1_FRONTEND_BEFORE=$(http_code http://127.0.0.1:80/)
SITE1_BACKEND_BEFORE=$(http_code http://127.0.0.1:8000/)
assert_existing_site "Site 1 frontend port 80" "$SITE1_FRONTEND_BEFORE"
assert_existing_site "Site 1 backend port 8000" "$SITE1_BACKEND_BEFORE"
printf 'Site 1 baseline: frontend=%s backend=%s\n' "$SITE1_FRONTEND_BEFORE" "$SITE1_BACKEND_BEFORE"

say "Requesting sudo once for Site 2-only permissions, configuration, and graceful reloads"
sudo -v
id nssf2 >/dev/null 2>&1 || sudo adduser --system --group --no-create-home nssf2

say "Checking Site 2 ports without disturbing existing listeners"
for port in "$FRONTEND_PORT" "$BACKEND_PORT"; do
  if ss -ltn "sport = :$port" | grep -q LISTEN && ! site2_listener_is_enabled "$port"; then
    fail "Port $port is in use by something other than the named Site 2 Nginx listener."
  fi
done

say "Extracting both verified archives into isolated staging"
BACKEND_STAGE=$(mktemp -d "$HOME/.nssf-site2-backend.XXXXXX")
FRONTEND_STAGE=$(mktemp -d "$HOME/.nssf-site2-frontend.XXXXXX")
tar -xzf "$BACKEND_ARCHIVE" -C "$BACKEND_STAGE"
tar -xzf "$FRONTEND_ARCHIVE" -C "$FRONTEND_STAGE"
[[ -f "$BACKEND_STAGE/artisan" ]] || fail "Backend archive does not contain artisan."
[[ -f "$BACKEND_STAGE/composer.lock" ]] || fail "Backend archive does not contain composer.lock."
[[ -f "$BACKEND_STAGE/deployment/.env.production.example" ]] || fail "Backend archive lacks its environment template."
[[ -f "$FRONTEND_STAGE/index.html" ]] || fail "Frontend archive does not contain index.html."

say "Backing up existing Site 2 code/configuration without including secrets or storage"
if find "$BACKEND" -mindepth 1 -maxdepth 1 -print -quit | grep -q .; then
  sudo tar --exclude=.env --exclude=storage --exclude=bootstrap/cache \
    -C "$BACKEND" -czf "$HOME/nssf-site2-backend-code-before-$STAMP.tgz" .
fi
if find "$FRONTEND" -mindepth 1 -maxdepth 1 -print -quit | grep -q .; then
  sudo tar -C "$FRONTEND" -czf "$HOME/nssf-site2-frontend-before-$STAMP.tgz" .
fi
backup_site2_file /etc/php/8.3/fpm/pool.d/nssf-site2.conf
backup_site2_file /etc/nginx/sites-available/nssf-backend-2
backup_site2_file /etc/nginx/sites-available/nssf-frontend-2
backup_site2_file /etc/nginx/sites-enabled/nssf-backend-2
backup_site2_file /etc/nginx/sites-enabled/nssf-frontend-2

say "Installing Site 2 release files while preserving Site 2 .env, storage, and cache"
sudo install -d -o "$USER" -g nssf2 -m 750 "$BACKEND"
sudo install -d -o "$USER" -g www-data -m 755 "$FRONTEND"
sudo tar --exclude=storage --exclude=bootstrap/cache -C "$BACKEND_STAGE" -cf - . \
  | sudo tar -C "$BACKEND" -xf -
sudo tar -C "$FRONTEND_STAGE" -cf - . | sudo tar -C "$FRONTEND" -xf -
[[ -f "$BACKEND/artisan" ]] || fail "Laravel application was not installed."
[[ -f "$FRONTEND/index.html" ]] || fail "Frontend application was not installed."

say "Configuring the Site 2 production environment without displaying credentials"
cd "$BACKEND"
mkdir -p storage/app/private storage/framework/cache storage/framework/sessions \
  storage/framework/views storage/logs bootstrap/cache
if [[ ! -f .env ]]; then
  umask 077
  cp deployment/.env.production.example .env
fi
env_set APP_ENV production
env_set APP_DEBUG false
env_set APP_URL "$BACKEND_URL"
env_set DB_CONNECTION pgsql
env_set DB_HOST "$DB_HOST"
env_set DB_PORT "$DB_PORT"
env_set DB_DATABASE "$DB_NAME"
env_set DB_USERNAME "$DB_USER"
env_set CORS_ALLOWED_ORIGINS "$FRONTEND_ORIGIN"
env_set SESSION_DRIVER file
env_set CACHE_STORE file
env_set QUEUE_CONNECTION sync
env_set FILESYSTEM_DISK local
env_set LOG_CHANNEL daily
env_set LOG_LEVEL warning
if ! site2_password_present; then
  read -r -s -p "PostgreSQL password for $DB_USER (hidden input): " SITE2_DB_PASSWORD
  printf '\n'
  [[ -n "$SITE2_DB_PASSWORD" ]] || fail "A non-empty Site 2 database password is required."
  env_set DB_PASSWORD "$SITE2_DB_PASSWORD"
  unset SITE2_DB_PASSWORD
fi

say "Installing locked production Composer dependencies with limited download parallelism"
COMPOSER_MAX_PARALLEL_HTTP=2 composer install --no-dev --prefer-dist \
  --optimize-autoloader --no-interaction --no-progress
composer check-platform-reqs --no-dev
if ! app_key_present; then
  say "Generating a new Site 2 application key without printing it"
  php artisan key:generate --force --no-interaction
fi

say "Applying secure Site 2 file permissions"
sudo chown -R "$USER":nssf2 "$BACKEND"
sudo find "$BACKEND" -type d -exec chmod 750 {} +
sudo find "$BACKEND" -type f -exec chmod 640 {} +
sudo chmod 755 "$BACKEND" "$BACKEND/public"
sudo find "$BACKEND/public" -type d -exec chmod 755 {} +
sudo find "$BACKEND/public" -type f -exec chmod 644 {} +
sudo chmod 640 "$BACKEND/.env"
sudo chmod -R g+rwX "$BACKEND/storage" "$BACKEND/bootstrap/cache"
sudo find "$BACKEND/storage" "$BACKEND/bootstrap/cache" -type d -exec chmod g+s {} +
sudo chown -R "$USER":www-data "$FRONTEND"
sudo find "$FRONTEND" -type d -exec chmod 755 {} +
sudo find "$FRONTEND" -type f -exec chmod 644 {} +

say "Validating Laravel database target and migrating only site2_db"
artisan config:clear
assert_site2_config
if ! migration_repository_exists; then
  say "Initializing Laravel's migration repository for new site2_db"
  artisan migrate:install
fi
migration_repository_exists || fail "Laravel migration repository could not be initialized in site2_db."
artisan migrate:status
artisan migrate --force --no-interaction
artisan migrate:status
artisan config:cache
artisan route:cache
artisan view:cache
assert_site2_config
sudo -u nssf2 test -w "$BACKEND/storage/logs" \
  || fail "The Site 2 FPM user cannot write Laravel logs."

say "Installing isolated Site 2 PHP-FPM and Nginx configuration"
sudo install -m 640 deployment/php-fpm/nssf-site2.conf /etc/php/8.3/fpm/pool.d/nssf-site2.conf
sudo install -m 644 deployment/nginx/nssf-backend-2.conf /etc/nginx/sites-available/nssf-backend-2
sudo install -m 644 deployment/nginx/nssf-frontend-2.conf /etc/nginx/sites-available/nssf-frontend-2
sudo ln -sfn /etc/nginx/sites-available/nssf-backend-2 /etc/nginx/sites-enabled/nssf-backend-2
sudo ln -sfn /etc/nginx/sites-available/nssf-frontend-2 /etc/nginx/sites-enabled/nssf-frontend-2

say "Validating all FPM and Nginx configuration before graceful reload"
sudo php-fpm8.3 -t
sudo nginx -t
sudo systemctl reload php8.3-fpm
sudo systemctl reload nginx
sudo systemctl is-active --quiet php8.3-fpm || fail "php8.3-fpm is not active after reload."
sudo systemctl is-active --quiet nginx || fail "Nginx is not active after reload."

say "Checking firewall state without changing Site 1 or SSH"
if command -v ufw >/dev/null 2>&1 && sudo ufw status | grep -q '^Status: active'; then
  sudo ufw allow "$FRONTEND_PORT/tcp" comment 'NSSF Site 2 frontend'
  sudo ufw allow "$BACKEND_PORT/tcp" comment 'NSSF Site 2 backend'
  sudo ufw status numbered
else
  printf 'UFW is inactive or unavailable; no firewall rule was changed.\n'
fi

say "Verifying Site 2 HTTP, CORS, database-backed Laravel boot, and Site 1"
sleep 2
SITE2_FRONTEND_CODE=$(http_code "http://127.0.0.1:$FRONTEND_PORT/")
SITE2_ROUTE_CODE=$(http_code "http://127.0.0.1:$FRONTEND_PORT/projects")
SITE2_BACKEND_CODE=$(http_code "http://127.0.0.1:$BACKEND_PORT/up")
SITE1_FRONTEND_AFTER=$(http_code http://127.0.0.1:80/)
SITE1_BACKEND_AFTER=$(http_code http://127.0.0.1:8000/)
[[ "$SITE2_FRONTEND_CODE" == 200 ]] || fail "Site 2 frontend returned HTTP $SITE2_FRONTEND_CODE."
[[ "$SITE2_ROUTE_CODE" == 200 ]] || fail "Site 2 React route returned HTTP $SITE2_ROUTE_CODE."
[[ "$SITE2_BACKEND_CODE" == 200 ]] || fail "Site 2 Laravel health endpoint returned HTTP $SITE2_BACKEND_CODE."
verify_unauthenticated_json_api "http://127.0.0.1:$BACKEND_PORT/api/me"
assert_existing_site "Site 1 frontend after Site 2 deployment" "$SITE1_FRONTEND_AFTER"
assert_existing_site "Site 1 backend after Site 2 deployment" "$SITE1_BACKEND_AFTER"
grep -Rsl --binary-files=text "$BACKEND_URL/api" "$FRONTEND/assets" >/dev/null \
  || fail "Deployed frontend does not contain the expected Site 2 API URL."

CORS_HEADERS=$(mktemp)
curl --silent --show-error --max-time 15 -D "$CORS_HEADERS" -o /dev/null -X OPTIONS \
  "http://127.0.0.1:$BACKEND_PORT/api/me" \
  -H "Origin: $FRONTEND_ORIGIN" \
  -H 'Access-Control-Request-Method: GET' \
  -H 'Access-Control-Request-Headers: authorization,content-type'
grep -qi "^Access-Control-Allow-Origin: $FRONTEND_ORIGIN" "$CORS_HEADERS" \
  || fail "Site 2 CORS preflight did not allow the Site 2 frontend origin."
rm -f -- "$CORS_HEADERS"

say "Checking Site 2 logs without printing their contents"
[[ -d "$BACKEND/storage/logs" ]] || fail "Laravel log directory is missing."
find "$BACKEND/storage/logs" -maxdepth 1 -type f -printf '%f\n'
ss -ltn '( sport = :80 or sport = :8000 or sport = :8080 or sport = :8001 )'
printf '\nSITE 2 DEPLOYMENT COMPLETED SUCCESSFULLY\n'
printf 'Frontend: %s\nBackend:  %s\nAPI:      %s/api\n' \
  "$FRONTEND_ORIGIN" "$BACKEND_URL" "$BACKEND_URL"
printf 'Site 1 checks: frontend=%s->%s backend=%s->%s\n' \
  "$SITE1_FRONTEND_BEFORE" "$SITE1_FRONTEND_AFTER" \
  "$SITE1_BACKEND_BEFORE" "$SITE1_BACKEND_AFTER"
