# ============================================================================
# scripts/audit_flight_full.ps1 — Windows PowerShell variant of the
# flight full operations audit script.
#
# Same workflow as audit_flight_full.sh but uses scp.exe / ssh.exe from
# OpenSSH (built into Windows 10+).
#
# Usage (PowerShell):
#   $env:STAGING_HOST = "staging.example.com"
#   $env:STAGING_USER = "www-data"
#   $env:STAGING_PATH = "/var/www/safarakEalayna"
#   $env:DB_USERNAME  = "safarak_app"
#   $env:DB_PASSWORD  = "secret"
#   bash scripts/audit_flight_full.ps1
# ============================================================================
$ErrorActionPreference = "Stop"

if (-not $env:STAGING_HOST) { throw "Set STAGING_HOST" }
if (-not $env:STAGING_USER) { throw "Set STAGING_USER" }
if (-not $env:STAGING_PATH) { throw "Set STAGING_PATH" }
if (-not $env:DB_USERNAME)  { throw "Set DB_USERNAME" }

$DB_HOST       = if ($env:DB_HOST) { $env:DB_HOST } else { "127.0.0.1" }
$DB_PORT       = if ($env:DB_PORT) { $env:DB_PORT } else { "3306" }
$DB_PASSWORD   = if ($env:DB_PASSWORD) { $env:DB_PASSWORD } else { "" }
$DB_AUDIT_NAME = if ($env:DB_AUDIT_NAME) { $env:DB_AUDIT_NAME } else { "safarak_flight_audit" }

$LocalTestFile  = "tests\Feature\Flight\FlightFullOperationsAuditTest.php"
$LocalSupportDir = "tests\Feature\Flight\Support"
$LocalPhpunitXml = "phpunit.audit.xml"

$RemoteTestDir  = "$($env:STAGING_PATH)/tests/Feature/Flight"
$RemoteSupportDir = "$($env:STAGING_PATH)/tests/Feature/Flight/Support"
$Ts = Get-Date -Format "yyyyMMdd_HHmmss"
$Log = "/tmp/flight_audit_${Ts}.log"

$DbPasswordArg = if ($DB_PASSWORD) { "-p'$DB_PASSWORD'" } else { "" }

Write-Host "==> [1/5] Creating isolated audit DB: $DB_AUDIT_NAME on $env:STAGING_HOST"
$cmd = "mysql -h $DB_HOST -P $DB_PORT -u $env:DB_USERNAME $DbPasswordArg -e `"CREATE DATABASE IF NOT EXISTS $DB_AUDIT_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`""
ssh "$($env:STAGING_USER)@$env:STAGING_HOST" $cmd

Write-Host "==> [2/5] Uploading test files to $env:STAGING_USER@$env:STAGING_HOST"
scp $LocalTestFile  "$($env:STAGING_USER)@$env:STAGING_HOST`:$RemoteTestDir/FlightFullOperationsAuditTest.php"
scp -r $LocalSupportDir "$($env:STAGING_USER)@$env:STAGING_HOST`:$($env:STAGING_PATH)/tests/Feature/Flight/"
scp $LocalPhpunitXml  "$($env:STAGING_USER)@$env:STAGING_HOST`:$($env:STAGING_PATH)/phpunit.audit.xml"

Write-Host "==> [3/5] Running migrations on $DB_AUDIT_NAME"
$cmd = "cd $($env:STAGING_PATH) && DB_AUDIT_DATABASE=$DB_AUDIT_NAME DB_CONNECTION=mysql_audit php artisan migrate --force 2>&1"
ssh "$($env:STAGING_USER)@$env:STAGING_HOST" $cmd | Tee-Object -FilePath $Log

Write-Host "==> [4/5] Running PHPUnit audit (this can take 30-90s)"
$cmd = "cd $($env:STAGING_PATH) && DB_AUDIT_DATABASE=$DB_AUDIT_NAME DB_CONNECTION=mysql_audit php artisan test --configuration=phpunit.audit.xml --filter=FlightFullOperationsAuditTest 2>&1"
ssh "$($env:STAGING_USER)@$env:STAGING_HOST" $cmd | Tee-Object -Append -FilePath $Log

Write-Host ""
Write-Host "==> [5/5] Audit complete"
Write-Host "    Output saved to: $Log"
Write-Host "    View with:       cat $Log"
Write-Host ""
Write-Host "    DB '$DB_AUDIT_NAME' is now populated. Inspect with:"
Write-Host "    mysql -u $env:DB_USERNAME -h $DB_HOST $DB_AUDIT_NAME -e 'SELECT COUNT(*) FROM flight_bookings;'"
Write-Host ""
Write-Host "    To clean up: bash scripts/audit_flight_cleanup.sh"
