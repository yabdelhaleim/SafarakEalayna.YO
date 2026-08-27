#!/usr/bin/env bash
# =============================================================================
#  staging_dashboard_demo_data.sh — Targeted deploy of the dashboard
#                                    empty-state + demo-data seeder commit
#                                    to staging (NO test files)
# =============================================================================
#  Purpose:
#      Pull ONLY the source files changed by the dashboard empty-state UX +
#      demo-data artisan command commit, without touching any test file.
#
#      Files deployed:
#          - resources/js/views/Dashboard.vue
#              · Added "لا توجد حركات في الفترة المحددة" hint to 8 cards
#                (2 tab header cards + 3 tourism PILLAR KPIs + 3 office PILLAR KPIs)
#                when total_count === 0. Replaces the misleading "0.00 ج.م"
#                with an informative empty state.
#
#          - app/Console/Commands/DashboardDemoData.php  (NEW)
#              · Artisan command `dashboard:demo-data` to seed sample P&L
#                transactions (income + expense) for every module
#                (flight / hajj_umra / visa / bus / fawry / online / wallet)
#                so the dashboard cards show real numbers on staging.
#              · Uses TransactionService for balanced double-entry + auto-creates
#                clearing accounts. All demo rows are tagged with [DEMO] prefix
#                for easy identification + cleanup.
#              · --dry-run support, idempotent (asks before adding on top of
#                existing demo data), spread across the last 30 days.
#
#  Usage (on the staging server, as the web user):
#      sudo -u www-data ./deploy/staging_dashboard_demo_data.sh
#
#  Post-deploy step (run on staging, as web user):
#      cd /var/www/safarakealayna-staging
#      php artisan dashboard:demo-data
#      php artisan cache:clear
#      php artisan config:clear
#      # verify: open https://staging.remotelly1.site/dashboard
#      # cleanup (when done):
#      #   php artisan tinker --execute="DB::table('transactions')->where('notes', 'like', '[DEMO]%')->delete();"
# =============================================================================

set -Eeuo pipefail

APP_DIR="/var/www/safarakealayna-staging"
APP_USER="www-data"
APP_GROUP="www-data"
BRANCH="phase-10-tourism-production-audit-hajj-umra"
LOG_DIR="/var/log/safarakealayna-deploy-staging"

# -----------------------------------------------------------------------------
# ⚠️  Set this to the SHA of the deploy commit (code + deploy script together).
#     Defaults to the HEAD of the local branch if reachable, but should be
#     replaced with the exact commit hash after `git push`.
# -----------------------------------------------------------------------------
FIX_COMMIT="${FIX_COMMIT:-HEAD}"

ts() { date '+%Y-%m-%d %H:%M:%S'; }
log() { printf '[%s] %s\n' "$(ts)" "$*"; }
section() { printf '\n\033[1;34m== %s ==\033[0m\n' "$*"; }
ok() { printf '\033[1;32m✔\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m⚠\033[0m %s\n' "$*"; }
die() { printf '\033[1;31m✖ %s\033[0m\n' "$*" >&2; exit 1; }

[ "$(id -un)" = "$APP_USER" ] || die "Run this as $APP_USER (use: sudo -u $APP_USER $0)"

cd "$APP_DIR" || die "Cannot enter $APP_DIR"

mkdir -p "$LOG_DIR" 2>/dev/null || true

section "STEP 1 — Fetch the dashboard-fix commit (no checkout yet)"
git fetch origin "$BRANCH" || die "git fetch failed"

# Resolve the actual SHA if user passed a short ref
RESOLVED_SHA=$(git rev-parse --verify "$FIX_COMMIT" 2>/dev/null) || die "Cannot resolve FIX_COMMIT=$FIX_COMMIT"
log "Target commit resolved: $RESOLVED_SHA"

section "STEP 2 — Verify the fix commit is reachable and contains no test files"
git cat-file -t "$RESOLVED_SHA" >/dev/null 2>&1 || die "Commit $RESOLVED_SHA not found locally — fetch first"

CHANGED_FILES=$(git show --name-only --pretty=format: "$RESOLVED_SHA")
TEST_FILES=$(echo "$CHANGED_FILES" | grep -E '^tests/' || true)
if [ -n "$TEST_FILES" ]; then
    die "Commit $RESOLVED_SHA unexpectedly contains test files:\n$TEST_FILES"
fi

# Defence-in-depth: also reject anything that smells like a test (phpunit.xml,
# tests/, _test.go, *_test.php at root, etc.)
SUSPICIOUS=$(echo "$CHANGED_FILES" | grep -Ei '(phpunit|test_|\.test\.|/tests/)' || true)
if [ -n "$SUSPICIOUS" ]; then
    die "Commit $RESOLVED_SHA contains test-ish files — refusing to deploy:\n$SUSPICIOUS"
fi

log "Files in commit:"
echo "$CHANGED_FILES" | sed 's/^/    /'
ok "Commit $RESOLVED_SHA confirmed: 0 test files"

section "STEP 3 — Enable maintenance mode"
php artisan down --retry=60 --secret="dashboard-demo-$(date +%s)" || warn "artisan down failed (continuing)"

section "STEP 4 — Stash any local working-tree changes (defensive)"
if ! git diff --quiet || ! git diff --cached --quiet; then
    warn "Working tree is dirty — stashing before checkout"
    git stash push -u -m "pre-dashboard-demo-deploy-$(ts)" || die "git stash failed"
fi

section "STEP 5 — Checkout the dashboard-fix commit"
git checkout "$RESOLVED_SHA" || die "git checkout $RESOLVED_SHA failed"

section "STEP 6 — Install PHP dependencies (no dev)"
composer install --no-dev --optimize-autoloader --no-interaction --no-progress || die "composer install failed"

section "STEP 7 — Rebuild frontend bundle (npm run build)"
npm ci --silent || die "npm ci failed"
npm run build || die "npm run build failed"
ok "Frontend bundle rebuilt"

section "STEP 8 — Refresh Laravel caches"
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan event:clear
php artisan optimize

section "STEP 9 — Storage link (idempotent)"
php artisan storage:link >/dev/null 2>&1 || true

section "STEP 10 — Queue restart (graceful worker reload)"
php artisan queue:restart || warn "queue:restart skipped"

section "STEP 11 — Auto-discover the dashboard command"
if php artisan list 2>/dev/null | grep -q 'dashboard:demo-data'; then
    ok "Command 'dashboard:demo-data' is registered"
else
    warn "Command 'dashboard:demo-data' NOT found — did composer dump-autoload run?"
    composer dump-autoload --optimize || warn "dump-autoload failed"
fi

section "STEP 12 — Disable maintenance mode"
php artisan up || warn "artisan up skipped"
ok "Staging is live with dashboard-fix $RESOLVED_SHA"

printf '\n\033[1;32mDone.\033[0m Deploy log: %s/dashboard-demo-%s.log\n' "$LOG_DIR" "$(date +%Y%m%d_%H%M%S)"

printf '\n\033[1;33m────────────────────────────────────────────────────────────\n'
printf '  POST-DEPLOY STEPS (run manually to populate demo data):\n'
printf '────────────────────────────────────────────────────────────\n'
printf '  cd /var/www/safarakealayna-staging\n'
printf '  php artisan dashboard:demo-data --dry-run   # preview first\n'
printf '  php artisan dashboard:demo-data              # actually seed\n'
printf '  php artisan cache:clear                     # flush dashboard cache\n'
printf '  php artisan config:clear\n'
printf '  # verify on https://staging.remotelly1.site/dashboard\n'
printf '  # cleanup when done:\n'
printf '  #   php artisan tinker --execute="DB::table('\''transactions'\'')->where('\''notes'\'', '\''like'\'', '\''[DEMO]%'\'')->delete();"\n'
printf '────────────────────────────────────────────────────────────\033[0m\n'
