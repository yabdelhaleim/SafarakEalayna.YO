# =====================================================
# Phase 1v2 UPLOAD SCRIPT (PowerShell - one at a time)
# =====================================================

$VPS = "188.68.36.142"
$REMOTE_DIR = "/var/www/safarakealayna/"
$LOCAL_DIR = "C:\travile\SafarakEalayna"
$SSH_OPT = "-o StrictHostKeyChecking=accept-new"

$files = @(
    "app\Models\Flight\AirlineAccount.php",
    "app\Providers\AppServiceProvider.php",
    "app\Services\Flight\AirlineAccountDebitService.php",
    "app\Listeners\ProcessTicketModificationAccounting.php",
    "app\Http\Controllers\Api\V1\Flight\AirlineAccountController.php",
    "test_phase1v2_verification.php"
)

foreach ($file in $files) {
    $localPath = "$LOCAL_DIR\$file"
    Write-Host "[UPLOAD] $file"
    scp $SSH_OPT "$localPath" "root@${VPS}:${REMOTE_DIR}"
    if ($LASTEXITCODE -eq 0) {
        Write-Host "[OK] $file uploaded"
    } else {
        Write-Host "[FAIL] $file"
        exit 1
    }
}

Write-Host ""
Write-Host "[DONE] All 6 files uploaded successfully"
Write-Host ""
Write-Host "[NEXT] SSH into the VPS and follow server-side instructions"
