#!/usr/bin/env bash
# ============================================================================
# scripts/audit_flight_full.sh
#
# Flight Full Operations Audit — upload test files to staging, run against
# isolated MySQL DB (safarak_flight_audit), and capture the report to a
# timestamped log on the staging server (viewable with `cat`).
#
# SAFETY: creates a SEPARATE database on the staging MySQL server. The test
# runs inside DatabaseTransactions so nothing persists after the run. The DB
# can be dropped afterwards with scripts/audit_flight_cleanup.sh.
#
# Required environment variables:
#   STAGING_HOST    staging hostname (e.g. staging.safarakealayna.com)
#   STAGING_USER    SSH user (e.g. www-data)
#   STAGING_PATH    remote project root (e.g. /var/www/safarakEalayna)
#   DB_USERNAME     MySQL user (must have CREATE DATABASE on the host)
#
# Optional:
#   DB_HOST         MySQL host (default 127.0.0.1)
#   DB_PORT         MySQL port (default 3306)
#   DB_PASSWORD     MySQL password (or rely on ~/.my.cnf on the remote)
#   DB_AUDIT_NAME   audit DB name (default safarak_flight_audit)
#
# Usage:
#   STAGING_HOST=staging.example.com \
#   STAGING_USER=www-data \
#   STAGING_PATH=/var/www/safarakEalayna \
#   DB_USERNAME=safarak_app \
#   DB_PASSWORD=secret \
#   bash scripts/audit_flight_full.sh
# ============================================================================
set -euo pipefail

: "${STAGING_HOST:?Set STAGING_HOST}"
: "${STAGING_USER:?Set STAGING_USER}"
: "${STAGING_PATH:?Set STAGING_PATH}"
: "${DB_USERNAME:?Set DB_USERNAME}"

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_PASSWORD="${DB_PASSWORD:-}"
DB_AUDIT_NAME="${DB_AUDIT_NAME:-safarak_flight_audit}"

LOCAL_TEST_FILE="tests/Feature/Flight/FlightFullOperationsAuditTest.php"
LOCAL_SUPPORT_DIR="tests/Feature/Flight/Support"
LOCAL_PHPUNIT_XML="phpunit.audit.xml"

REMOTE_TEST_DIR="$STAGING_PATH/tests/Feature/Flight"
REMOTE_SUPPORT_DIR="$STAGING_PATH/tests/Feature/Flight/Support"
TS=$(date +%Y%m%d_%H%M%S)
LOG="/tmp/flight_audit_${TS}.log"

echo "==> [1/5] Creating isolated audit DB: $DB_AUDIT_NAME on $STAGING_HOST"
ssh "$STAGING_USER@$STAGING_HOST" "mysql -h $DB_HOST -P $DB_PORT -u $DB_USERNAME $([[ -n \"$DB_PASSWORD\" ]] && echo \"-p'$DB_PASSWORD'\") -e \"CREATE DATABASE IF NOT EXISTS $DB_AUDIT_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\""

echo "==> [2/5] Uploading test files to $STAGING_USER@$STAGING_HOST:$REMOTE_TEST_DIR"
scp "$LOCAL_TEST_FILE" "$STAGING_USER@$STAGING_HOST:$REMOTE_TEST_DIR/FlightFullOperationsAuditTest.php"
scp -r "$LOCAL_SUPPORT_DIR" "$STAGING_USER@$STAGING_HOST:$STAGING_PATH/tests/Feature/Flight/"
scp "$LOCAL_PHPUNIT_XML" "$STAGING_USER@$STAGING_HOST:$STAGING_PATH/phpunit.audit.xml"

echo "==> [3/5] Running migrations on $DB_AUDIT_NAME"
ssh "$STAGING_USER@$STAGING_HOST" "cd $STAGING_PATH && \
  DB_AUDIT_DATABASE=$DB_AUDIT_NAME DB_CONNECTION=mysql_audit \
  php artisan migrate --force 2>&1" 2>&1 | tee "$LOG"

echo "==> [4/5] Running PHPUnit audit (this can take 30-90s)"
ssh "$STAGING_USER@$STAGING_HOST" "cd $STAGING_PATH && \
  DB_AUDIT_DATABASE=$DB_AUDIT_NAME DB_CONNECTION=mysql_audit \
  php artisan test --configuration=phpunit.audit.xml \
  --filter=FlightFullOperationsAuditTest 2>&1" 2>&1 | tee -a "$LOG"

echo ""
echo "==> [5/5] Audit complete"
echo "    Output saved to: $LOG"
echo "    View with:       cat $LOG"
echo ""
echo "    DB '$DB_AUDIT_NAME' is now populated. Inspect with:"
echo "    mysql -u $DB_USERNAME -h $DB_HOST $DB_AUDIT_NAME -e 'SELECT COUNT(*) FROM flight_bookings;'"
echo ""
echo "    To clean up the audit DB: bash scripts/audit_flight_cleanup.sh"
