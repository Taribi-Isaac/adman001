#!/usr/bin/env bash
# ADMAN production deploy — run on the production host as user `adman`.
# Invoked by GitHub Actions over SSH, or manually for emergency deploys.
#
# Safety:
# - Never prints .env or secret values
# - Never runs git clean (preserves .env, storage, untracked backups)
# - Never overwrites .env
# - Deploys only an explicit commit SHA (expected: origin/main tip)
# - Uses flock so two deploys cannot run concurrently on the server
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/adman}"
LOCK_FILE="${LOCK_FILE:-/tmp/adman-production-deploy.lock}"
DEPLOY_SHA="${DEPLOY_SHA:-}"
SKIP_MIGRATE="${SKIP_MIGRATE:-0}"
HEALTH_URL="${HEALTH_URL:-https://adman.raslordeckltd.com}"

log() { printf '[deploy] %s\n' "$*"; }
die() { printf '[deploy] ERROR: %s\n' "$*" >&2; exit 1; }

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || die "missing command: $1"
}

assert_safe_tree() {
  [[ -f .env ]] || die ".env missing — refusing to deploy"
  local mode
  mode="$(stat -c '%a' .env)"
  [[ "$mode" == "600" ]] || log "WARNING: .env mode is ${mode} (expected 600); preserving file, not rewriting contents"

  # Refuse if .env is tracked (would risk secret exposure / overwrite via git)
  if git ls-files --error-unmatch .env >/dev/null 2>&1; then
    die ".env is tracked by git — refusing to deploy"
  fi
}

sync_code() {
  local sha="$1"
  log "Fetching origin..."
  git fetch --prune origin

  git rev-parse --verify "${sha}^{commit}" >/dev/null 2>&1 \
    || die "commit ${sha} not found after fetch"

  local current
  current="$(git rev-parse HEAD)"
  log "Current HEAD: ${current}"
  log "Target SHA:   ${sha}"

  # Move main to the approved SHA. reset --hard affects tracked files only;
  # untracked paths (.env, storage uploads, *.bak) are preserved because we
  # never run git clean.
  git checkout -B main "${sha}"
  git reset --hard "${sha}"

  [[ "$(git rev-parse HEAD)" == "$(git rev-parse "${sha}^{commit}")" ]] \
    || die "HEAD does not match target after sync"

  if [[ -n "$(git status --porcelain)" ]]; then
    log "Working tree has untracked/local files (expected for .env / backups):"
    # Show paths only — never dump file contents
    git status --porcelain | awk '{print $1, $2}'
  fi
}

install_deps() {
  log "composer install --no-dev..."
  composer install --no-dev --optimize-autoloader --no-interaction --no-ansi

  log "npm ci + build..."
  npm ci --no-audit --no-fund
  npm run build
}

laravel_optimize() {
  log "Laravel caches + migrate..."
  php artisan storage:link --force >/dev/null 2>&1 || php artisan storage:link || true

  if [[ "${SKIP_MIGRATE}" == "1" ]]; then
    log "SKIP_MIGRATE=1 — skipping migrate"
  else
    php artisan migrate --force --no-interaction
  fi

  # Least-privilege config cache (Task 026): FPM reads 640 cache; .env stays 600
  umask 027
  php artisan config:cache --no-ansi
  php artisan route:cache --no-ansi
  php artisan view:cache --no-ansi

  # Restore .env mode in case any tooling touched it
  chmod 600 .env
  chown adman:www-data .env || true

  if [[ -f bootstrap/cache/config.php ]]; then
    sudo chown adman:www-data bootstrap/cache/config.php
    sudo chmod 640 bootstrap/cache/config.php
  fi
  if [[ -f bootstrap/cache/routes-v7.php ]]; then
    sudo chown adman:www-data bootstrap/cache/routes-v7.php
    sudo chmod 640 bootstrap/cache/routes-v7.php
  fi

  # Keep storage/bootstrap dirs writable by deploy user + FPM; do not rewrite
  # private upload file modes beyond ownership. Some paths are www-data-owned.
  sudo chown -R adman:www-data storage bootstrap/cache
  find storage bootstrap/cache -type d -exec chmod 775 {} \;
  [[ -f bootstrap/cache/config.php ]] && sudo chmod 640 bootstrap/cache/config.php
  [[ -f bootstrap/cache/routes-v7.php ]] && sudo chmod 640 bootstrap/cache/routes-v7.php
  [[ -f bootstrap/cache/config.php ]] && sudo chown adman:www-data bootstrap/cache/config.php
  [[ -f bootstrap/cache/routes-v7.php ]] && sudo chown adman:www-data bootstrap/cache/routes-v7.php
  chmod 600 .env
  chown adman:www-data .env || true
}

restart_runtime() {
  log "Reloading PHP-FPM and restarting Horizon..."
  sudo systemctl reload php8.4-fpm
  # Graceful Horizon recycle; systemd unit restarts the master
  php artisan horizon:terminate --no-ansi || true
  sleep 2
  sudo systemctl restart adman-horizon
  sudo systemctl is-active --quiet adman-horizon || die "Horizon not active after restart"
  sudo systemctl is-active --quiet adman-scheduler.timer || die "Scheduler timer not active"
}

verify_health() {
  log "Health verification..."
  local code

  code="$(curl -sS -o /dev/null -w '%{http_code}' "${HEALTH_URL}/up")"
  [[ "$code" == "200" ]] || die "/up returned ${code}"

  code="$(curl -sS -o /dev/null -w '%{http_code}' "${HEALTH_URL}/login")"
  [[ "$code" == "200" ]] || die "/login returned ${code}"

  php artisan adman:production-check --no-ansi
  php artisan adman:production-check --strict --no-ansi
  php artisan horizon:status --no-ansi

  # Config presence only — never print secret values
  php artisan tinker --execute='
    echo "resend=".(filled(config("services.resend.key"))?"yes":"no")."\n";
    echo "openai=".(filled(config("adman.ai.api_key"))?"yes":"no")."\n";
    echo "whatsapp_token=".(filled(config("adman.whatsapp.access_token"))?"yes":"no")."\n";
    echo "whatsapp_enabled=".(config("adman.whatsapp.enabled")?"true":"false")."\n";
    echo "app_debug=".(config("app.debug")?"true":"false")."\n";
    echo "env_mode=".substr(sprintf("%o", fileperms(base_path(".env"))), -3)."\n";
  '

  log "Deployed commit: $(git rev-parse HEAD)"
  log "Health OK"
}

main() {
  require_cmd git
  require_cmd composer
  require_cmd npm
  require_cmd php
  require_cmd curl
  require_cmd flock

  [[ -n "${DEPLOY_SHA}" ]] || die "DEPLOY_SHA is required (full commit SHA from origin/main)"
  [[ "$(id -un)" == "adman" ]] || die "must run as adman (got $(id -un))"

  exec 9>"${LOCK_FILE}"
  if ! flock -n 9; then
    die "another production deploy holds ${LOCK_FILE}"
  fi

  cd "${APP_DIR}"
  assert_safe_tree
  sync_code "${DEPLOY_SHA}"
  install_deps
  laravel_optimize
  restart_runtime
  verify_health
  log "SUCCESS"
}

main "$@"
