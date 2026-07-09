#!/bin/bash
set -e
cd /var/www/safarakealayna

cp .env .env.backup_string_test.$(date +%Y%m%d_%H%M%S)
sed -i 's/^DB_DATABASE=safarakealayna$/DB_DATABASE=safarakealayna_staging/' .env
php artisan config:clear > /dev/null

DB_DATABASE=safarakealayna_staging \
  php artisan tinker --execute="require '/tmp/test_string_cast.php';" 2>&1 | tee /tmp/string_test_output.txt

cp .env.backup_string_test.* .env
php artisan config:clear > /dev/null
rm -f .env.backup_string_test.*

echo ""
echo "═══════════════════════════════════════════════════════════════"
echo "  .env restored: DB_DATABASE=$(grep '^DB_DATABASE=' .env | cut -d'=' -f2)"
echo "═══════════════════════════════════════════════════════════════"