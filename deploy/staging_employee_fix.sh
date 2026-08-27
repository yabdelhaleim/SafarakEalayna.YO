#!/usr/bin/env bash
# =============================================================================
#  staging_employee_fix.sh — Targeted deploy of commit c854d59 to staging
#                            (employees module bug-fix, NO test files)
# =============================================================================
#  Purpose:
#      Pull ONLY the source files changed by commit c854d59 to the staging
#      server, without touching any test file. The fix touches 9 files:
#          - app/Http/Controllers/Api/V1/Employee/AttendanceController.php
#          - app/Http/Requests/Employee/StoreEmployeeRequest.php
#          - app/Http/Requests/Employee/UpdateEmployeeRequest.php
#          - resources/js/stores/employeeStore.js
#          - resources/js/views/NotFound.vue
#          - resources/js/views/employees/AttendanceIndex.vue
#          - resources/js/views/employees/EmployeeCreate.vue
#          - resources/js/views/employees/EmployeeIndex.vue
#          - resources/js/views/employees/EmployeeShow.vue
#
#  Usage (on the staging server, as the web user):
#      sudo -u www-data ./deploy/staging_employee_fix.sh
#
#  Pre-conditions:
#      - SSH access to staging server
#      - APP_DIR=/var/www/safarakealayna-staging (matches deploy/staging.sh)
#      - Same Node/PHP/Composer versions as staging.sh expects
# =============================================================================

set -Eeuo pipefail

APP_DIR="/var/www/safarakealayna-staging"
APP_USER="www-data"
APP_GROUP="www-data"
BRANCH="phase-10-tourism-production-audit-hajj-umra"
FIX_COMMIT="c854d59"
LOG_DIR="/var/log/safarakealayna-deploy-staging"

ts() { date '+%Y-%m-%d %H:%M:%S'; }
log() { printf '[%s] %s\n' "$(ts)" "$*"; }
section() { printf '\n\033[1;34m== %s ==\033[0m\n' "$*"; }
ok() { printf '\033[1;32m✔\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m⚠\033[0m %s\n' "$*"; }
die() { printf '\033[1;31m✖ %s\033[0m\n' "$*" >&2; exit 1; }

[ "$(id -un)" = "$APP_USER" ] || die "Run this as $APP_USER (use: sudo -u $APP_USER $0)"

cd "$APP_DIR" || die "Cannot enter $APP_DIR"

mkdir -p "$LOG_DIR" 2>/dev/null || true

section "STEP 1 — Fetch the fix commit (no checkout yet)"
git fetch origin "$BRANCH" || die "git fetch failed"

section "STEP 2 — Verify the fix commit is reachable and contains no test files"
git cat-file -t "$FIX_COMMIT" >/dev/null 2>&1 || die "Commit $FIX_COMMIT not found on remote"
TEST_FILES=$(git show --name-only --pretty=format: "$FIX_COMMIT" | grep -E '^tests/' || true)
if [ -n "$TEST_FILES" ]; then
    die "Commit $FIX_COMMIT unexpectedly contains test files:\n$TEST_FILES"
fi
ok "Commit $FIX_COMMIT confirmed: 0 test files"

section "STEP 3 — Enable maintenance mode"
php artisan down --retry=60 --secret="employee-fix-$(date +%s)" || warn "artisan down failed (continuing)"

section "STEP 4 — Stash any local working-tree changes (defensive)"
if ! git diff --quiet || ! git diff --cached --quiet; then
    warn "Working tree is dirty — stashing before checkout"
    git stash push -u -m "pre-staging-fix-$(ts)" || die "git stash failed"
fi

section "STEP 5 — Checkout the fix commit"
git checkout "$FIX_COMMIT" || die "git checkout $FIX_COMMIT failed"

section "STEP 6 — Rebuild frontend bundle (npm run build)"
npm ci --silent || die "npm ci failed"
npm run build || die "npm run build failed"
ok "Frontend bundle rebuilt"

section "STEP 7 — Refresh Laravel caches"
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan event:clear
php artisan optimize

section "STEP 8 — Storage link (idempotent)"
php artisan storage:link >/dev/null 2>&1 || true

section "STEP 9 — Queue restart (graceful worker reload)"
php artisan queue:restart || warn "queue:restart skipped"

section "STEP 10 — Disable maintenance mode"
php artisan up || warn "artisan up skipped"
ok "Staging is live with fix $FIX_COMMIT"

printf '\n\033[1;32mDone.\033[0m Deploy log: %s/employee-fix-%s.log\n' "$LOG_DIR" "$(date +%Y%m%d_%H%M%S)"
