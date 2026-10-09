#!/usr/bin/env bash
# ============================================================================
# scripts/audit_flight_cleanup.sh
#
# Drops the isolated audit DB (safarak_flight_audit by default) on staging.
# Use this after you've reviewed the audit log and don't need the DB anymore.
#
# Required env vars: same as audit_flight_full.sh
#   STAGING_HOST, STAGING_USER, STAGING_PATH, DB_USERNAME
# Optional:
#   DB_HOST, DB_PORT, DB_PASSWORD, DB_AUDIT_NAME
# ============================================================================
set -euo pipefail

: "${STAGING_HOST:?}"; : "${STAGING_USER:?}"; : "${STAGING_PATH:?}"
: "${DB_USERNAME:?}"

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_PASSWORD="${DB_PASSWORD:-}"
DB_AUDIT_NAME="${DB_AUDIT_NAME:-safarak_flight_audit}"

read -p "Drop database $DB_AUDIT_NAME on $STAGING_HOST? [y/N] " -n 1 -r
echo
[[ $REPLY =~ ^[Yy]$ ]] || { echo "Aborted."; exit 1; }

ssh "$STAGING_USER@$STAGING_HOST" "mysql -h $DB_HOST -P $DB_PORT -u $DB_USERNAME $([[ -n \"$DB_PASSWORD\" ]] && echo \"-p'$DB_PASSWORD'\") -e \"DROP DATABASE IF EXISTS $DB_AUDIT_NAME;\""
echo "==> DB '$DB_AUDIT_NAME' dropped on $STAGING_HOST."
