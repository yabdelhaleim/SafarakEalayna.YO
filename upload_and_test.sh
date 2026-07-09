#!/bin/bash
# Upload + test the Account isolation script

set -e

cd /var/www/safarakealayna

echo "═══════════════════════════════════════════════════════════════"
echo "  Running Test 1+2+3 on staging"
echo "═══════════════════════════════════════════════════════════════"

# Backup .env
cp .env .env.backup_test.$(date +%Y%m%d_%H%M%S)

# Modify .env to staging
sed -i 's/^DB_DATABASE=safarakealayna$/DB_DATABASE=safarakealayna_staging/' .env
echo "Modified .env: DB_DATABASE=$(grep '^DB_DATABASE=' .env | cut -d'=' -f2)"

# Clear cache
php artisan config:clear > /dev/null

# Run the test
DB_DATABASE=safarakealayna_staging \
  php artisan tinker --execute="require '/tmp/test_account_isolation.php';" 2>&1 | tee /tmp/test_output.txt

TEST_RESULT=${PIPESTATUS[0]}

# Restore .env
cp .env.backup_test.* .env
echo ""
echo "Restored .env: DB_DATABASE=$(grep '^DB_DATABASE=' .env | cut -d'=' -f2)"

# Cleanup
php artisan config:clear > /dev/null
rm -f .env.backup_test.*

echo ""
echo "═══════════════════════════════════════════════════════════════"
if [ $TEST_RESULT -eq 0 ]; then
    echo "  Tests complete — see /tmp/test_output.txt"
fi
echo "═══════════════════════════════════════════════════════════════"
