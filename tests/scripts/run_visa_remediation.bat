@echo off
setlocal
set APP_ENV=stress
set DB_CONNECTION=mysql
set DB_HOST=127.0.0.1
set DB_PORT=3306
set DB_DATABASE=safarak_stress
set DB_USERNAME=root
set DB_PASSWORD=

php -d memory_limit=512M tests\scripts\visa_remediation_regression_20260815.php
