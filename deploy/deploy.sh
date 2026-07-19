#!/usr/bin/env bash
# =============================================================================
#  deploy.sh — Production deploy script for "Travel Office System" (Laravel 13)
# =============================================================================
#  Usage (on the server, as the web user):
#      sudo -u www-data ./deploy/deploy.sh
#      sudo -u www-data ./deploy/deploy.sh --branch=release/2026-07 --no-build
#      sudo -u www-data ./deploy/deploy.sh --skip-migrate --dry-run
#
#  Required (already done once on the server):
#      - PHP 8.3+, Composer 2.x, Node 20+, git
#      - Nginx configured to point at $APP_DIR/public
#      - .env in place with APP_KEY generated
#      - Storage + bootstrap/cache writable by the web user
#
#  Behaviour:
#      - git fetch + checkout the target branch (default: current)
#      - composer install --no-dev --optimize-autoloader
#      - npm ci && npm run build (unless --no-build)
#      - php artisan migrate --force (unless --skip-migrate)
#      - clear + warm config/route/view/event cache
#      - php artisan storage:link (idempotent)
#      - queue:restart (graceful worker reload)
#      - reload PHP-FPM (optional, if sudo + reload cmd present)
#      - flips maintenance mode off at the end
# =============================================================================

set -Eeuo pipefail

# ---------- defaults --------------------------------------------------------
APP_DIR_DEFAULT="/var/www/safarakEalayna"
APP_USER_DEFAULT="www-data"
APP_GROUP_DEFAULT="www-data"
PHP_FPM_SERVICE_DEFAULT="php8.3-fpm"
LOG_DIR_DEFAULT="/var/log/safarak-deploy"
DRY_RUN="false"
SKIP_MIGRATE="false"
SKIP_BUILD="false"
BRANCH=""
BACKUP_ENV="true"

# ---------- helpers ---------------------------------------------------------
ts()       { date '+%Y-%m-%d %H:%M:%S'; }
log()      { printf '[%s] %s\n' "$(ts)" "$*"; }
section()  { printf '\n\033[1;34m== %s ==\033[0m\n' "$*"; }
ok()       { printf '\033[1;32m✔\033[0m %s\n' "$*"; }
warn()     { printf '\033[1;33m⚠\033[0m %s\n' "$*"; }
die()      { printf '\033[1;31m✖ %s\033[0m\n' "$*" >&2; exit 1; }

# ---------- usage -----------------------------------------------------------
usage() {
    sed -n '2,30p' "$0" | sed 's/^# \{0,1\}//'
    exit 0
}

# ---------- parse args ------------------------------------------------------
for arg in "$@"; do
    case "$arg" in
        --dry-run)        DRY_RUN="true" ;;
        --skip-migrate)   SKIP_MIGRATE="true" ;;
        --no-build)       SKIP_BUILD="true" ;;
        --no-backup)      BACKUP_ENV="false" ;;
        --branch=*)       BRANCH="${arg#*=}" ;;
        --dir=*)          APP_DIR_DEFAULT="${arg#*=}" ;;
        --user=*)         APP_USER_DEFAULT="${arg#*=}" ;;
        --fpm=*)          PHP_FPM_SERVICE_DEFAULT="${arg#*=}" ;;
        -h|--help)        usage ;;
        *)                die "Unknown argument: $arg  (use --help)" ;;
    esac
done

# ---------- load config (optional) -----------------------------------------
# Drop a `deploy.conf` next to this script to override defaults.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
[[ -f "$SCRIPT_DIR/deploy.conf" ]] && source "$SCRIPT_DIR/deploy.conf"

APP_DIR="${APP_DIR:-$APP_DIR_DEFAULT}"
APP_USER="${APP_USER:-$APP_USER_DEFAULT}"
APP_GROUP="${APP_GROUP:-$APP_USER_DEFAULT}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-$PHP_FPM_SERVICE_DEFAULT}"
LOG_DIR="${LOG_DIR:-$LOG_DIR_DEFAULT}"
LOG_FILE="$LOG_DIR/deploy-$(date '+%Y%m%d-%H%M%S').log"

run() {
    # Run a command, respecting DRY_RUN.
    if [[ "$DRY_RUN" == "true" ]]; then
        printf '   \033[2m[dry-run]\033[0m %s\n' "$*"
    else
        "$@"
    fi
}

# ---------- pre-flight ------------------------------------------------------
section "Pre-flight checks"
mkdir -p "$LOG_DIR"

[[ -d "$APP_DIR" ]]                       || die "APP_DIR not found: $APP_DIR"
[[ -f "$APP_DIR/artisan" ]]               || die "artisan missing — not a Laravel app?"
[[ -f "$APP_DIR/composer.json" ]]         || die "composer.json missing"
[[ -f "$APP_DIR/.env" ]]                  || die ".env missing — copy .env.production.example first"

command -v php      >/dev/null || die "php not installed"
command -v composer >/dev/null || die "composer not installed"
command -v git      >/dev/null || die "git not installed"

PHP_VER="$(php -r 'echo PHP_VERSION;')"
[[ "${PHP_VER%%.*}" == "8" ]] && (( ${PHP_VER##*.} >= 3 )) \
    || die "PHP >= 8.3 required, found $PHP_VER"

if [[ "$SKIP_BUILD" != "true" ]]; then
    command -v node >/dev/null || die "node not installed (required for Vite build)"
    command -v npm  >/dev/null || die "npm not installed"
fi

cd "$APP_DIR"
ok "Environment OK (PHP $PHP_VER, cwd=$APP_DIR, dry_run=$DRY_RUN)"
log "Log file: $LOG_FILE"

# ---------- trap to roll back maintenance on failure -----------------------
cleanup_maintenance() {
    local exit_code=$?
    if [[ $exit_code -ne 0 ]]; then
        warn "Deploy failed (exit=$exit_code). Bringing app back up..."
        php artisan up || true
    fi
    exit $exit_code
}
trap cleanup_maintenance EXIT INT TERM

# ---------- 1. backup .env --------------------------------------------------
if [[ "$BACKUP_ENV" == "true" && "$DRY_RUN" != "true" ]]; then
    section "Backing up .env"
    cp -a .env "$LOG_DIR/env.backup-$(date '+%Y%m%d-%H%M%S')"
    ok ".env snapshot saved"
fi

# ---------- 2. maintenance mode --------------------------------------------
section "Enabling maintenance mode"
run php artisan down --retry=60 --refresh=15

# ---------- 3. git pull -----------------------------------------------------
section "Updating source"
if [[ -n "$BRANCH" ]]; then
    log "Switching to branch: $BRANCH"
    run git fetch --tags --prune
    run git checkout "$BRANCH"
fi
run git fetch --all --prune
run git pull --ff-only
GIT_REV="$(git rev-parse --short HEAD)"
ok "Now at $(git rev-parse --abbrev-ref HEAD) @ $GIT_REV"

# ---------- 4. composer -----------------------------------------------------
section "Installing PHP dependencies"
run composer install \
    --no-interaction \
    --no-dev \
    --prefer-dist \
    --optimize-autoloader \
    --no-progress

# ---------- 5. npm build ----------------------------------------------------
if [[ "$SKIP_BUILD" != "true" ]]; then
    section "Building frontend assets"
    if [[ -f package-lock.json ]]; then
        run npm ci --no-audit --no-fund
    else
        warn "package-lock.json missing — falling back to npm install"
        run npm install --no-audit --no-fund
    fi
    run npm run build
    ok "Assets built"
else
    warn "Skipping frontend build (--no-build)"
fi

# ---------- 6. storage link -------------------------------------------------
section "Ensuring storage symlink"
run php artisan storage:link || true

# ---------- 7. migrate ------------------------------------------------------
if [[ "$SKIP_MIGRATE" != "true" ]]; then
    section "Running database migrations"
    run php artisan migrate --force
else
    warn "Skipping migrations (--skip-migrate)"
fi

# ---------- 8. cache warm-up ------------------------------------------------
section "Clearing and warming caches"
run php artisan config:clear
run php artisan route:clear
run php artisan view:clear
run php artisan event:clear
run php artisan cache:clear

run php artisan config:cache
run php artisan route:cache
run php artisan view:cache
run php artisan event:cache
ok "Caches warmed"

# ---------- 9. queue restart (graceful) ------------------------------------
section "Restarting queue workers"
run php artisan queue:restart || warn "queue:restart failed (workers may already be down)"
ok "Workers will recycle on next loop"

# ---------- 10. permissions -------------------------------------------------
section "Fixing filesystem permissions"
if [[ "$DRY_RUN" != "true" ]]; then
    chown -R "$APP_USER":"$APP_GROUP" storage bootstrap/cache public/build 2>/dev/null || true
    find storage bootstrap/cache -type d -exec chmod 775 {} +
    find storage bootstrap/cache -type f -exec chmod 664 {} +
fi
ok "Permissions applied"

# ---------- 11. reload PHP-FPM (if available) ------------------------------
section "Reloading PHP-FPM"
if command -v systemctl >/dev/null && systemctl list-unit-files "${PHP_FPM_SERVICE}.service" >/dev/null 2>&1; then
    run sudo systemctl reload "$PHP_FPM_SERVICE" || warn "FPM reload failed (continuing)"
    ok "PHP-FPM reloaded"
else
    warn "systemctl / $PHP_FPM_SERVICE not found — skipping FPM reload"
fi

# ---------- 12. disable maintenance -----------------------------------------
section "Disabling maintenance mode"
run php artisan up
ok "App is live again"

# ---------- summary ---------------------------------------------------------
section "Deploy complete"
log "Revision:    $GIT_REV"
log "PHP:         $PHP_VER"
log "Branch:      $(git rev-parse --abbrev-ref HEAD)"
log "Migrate:     $([[ "$SKIP_MIGRATE" == "true" ]] && echo skipped || echo yes)"
log "Frontend:    $([[ "$SKIP_BUILD" == "true" ]] && echo skipped || echo built)"
log "Log file:    $LOG_FILE"
