#!/bin/bash
# Helper script: Run --apply on staging with .env modification + guaranteed restore
# Usage: bash run_staging_safely.sh

set -e  # exit on error

cd /var/www/safarakealayna

echo "════════════════════════════════════════════════════════════════"
echo "  Safe staging runner — .env will be restored automatically"
echo "════════════════════════════════════════════════════════════════"

# ① Save original .env
cp .env .env.backup_pre_staging.$(date +%Y%m%d_%H%M%S)
echo "✓ Backed up .env to .env.backup_pre_staging.$(date +%Y%m%d_%H%M%S)"

# ② Modify .env: DB_DATABASE → staging
sed -i 's/^DB_DATABASE=safarakealayna$/DB_DATABASE=safarakealayna_staging/' .env
echo "✓ Modified .env: DB_DATABASE=safarakealayna → safarakealayna_staging"

# ③ Verify the change
NEW_DB=$(grep '^DB_DATABASE=' .env | cut -d'=' -f2)
echo "✓ Verified: DB_DATABASE=$NEW_DB"

if [ "$NEW_DB" != "safarakealayna_staging" ]; then
    echo "✗ FAILED: Could not change DB_DATABASE. Restoring .env..."
    cp .env.backup_pre_staging.* .env
    exit 1
fi

# ④ Clear caches (important!)
php artisan config:clear
echo "✓ Cleared config cache"

# ⑤ Run --apply
echo ""
echo "════════════════════════════════════════════════════════════"
echo "  Running --apply on staging..."
echo "════════════════════════════════════════════════════════════"
DB_DATABASE=safarakealayna_staging \
  php artisan tinker --execute='$argv=["--apply", "--db=safarakealayna_staging"]; require "/tmp/phase3b_v3_writeoff_7desyncs.php";' 2>&1 | tee /tmp/apply_staging.txt

APPLY_RESULT=${PIPESTATUS[0]}

# ⑥ RESTORE .env (always — even on failure)
echo ""
echo "════════════════════════════════════════════════════════════"
echo "  Restoring .env to original state..."
echo "════════════════════════════════════════════════════════════"
# Find the most recent backup and restore from it
LATEST_BACKUP=$(ls -t .env.backup_pre_staging.* 2>/dev/null | head -1)
if [ -n "$LATEST_BACKUP" ]; then
    cp "$LATEST_BACKUP" .env
    echo "✓ .env restored from $LATEST_BACKUP"
else
    echo "⚠️  No backup found — .env may already be in correct state"
fi

# ⑦ Verify the restore
RESTORED_DB=$(grep '^DB_DATABASE=' .env | cut -d'=' -f2)
echo "✓ Verified: DB_DATABASE=$RESTORED_DB"

if [ "$RESTORED_DB" != "safarakealayna" ]; then
    echo "✗ FAILED: .env not properly restored!"
    exit 1
fi

# Final cleanup
php artisan config:clear
echo "✓ Final config cache cleared"

# ⑧ Final result
echo ""
echo "════════════════════════════════════════════════════════════"
if [ $APPLY_RESULT -eq 0 ]; then
    echo "  ✅ APPLY SUCCEEDED on staging"
    echo "  .env has been restored to production setting"
    echo "  Next: verify with mysql queries, then run on production"
else
    echo "  ❌ APPLY FAILED on staging (exit code: $APPLY_RESULT)"
    echo "  .env has been restored to production setting"
    echo "  Check /tmp/apply_staging.txt for details"
fi
echo "════════════════════════════════════════════════════════════"

exit $APPLY_RESULT
