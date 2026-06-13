#Requires -RunAsAdministrator

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
$publicPath = Join-Path $projectRoot "public"
$domain = "safarakealayna.test"
$vhostName = "$domain.conf"
$laragonSites = "C:\laragon\etc\apache2\sites-enabled"
$hostsFile = "$env:SystemRoot\System32\drivers\etc\hosts"

if (-not (Test-Path $publicPath)) {
    Write-Error "public folder not found: $publicPath"
    exit 1
}

if (-not (Test-Path $laragonSites)) {
    Write-Error "Laragon sites-enabled not found. Is Laragon installed?"
    exit 1
}

$vhostContent = @"
<VirtualHost *:80>
    DocumentRoot "$($publicPath -replace '\\','/')"
    ServerName $domain
    ServerAlias $domain
    <Directory "$($publicPath -replace '\\','/')">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
"@

$targetVhost = Join-Path $laragonSites $vhostName
Set-Content -Path $targetVhost -Value $vhostContent -Encoding UTF8
Write-Host "Wrote vhost: $targetVhost"

$hostsEntry = "127.0.0.1`t$domain"
$hosts = Get-Content $hostsFile -ErrorAction Stop
if ($hosts -notcontains $hostsEntry) {
    Add-Content -Path $hostsFile -Value $hostsEntry
    Write-Host "Added hosts entry: $hostsEntry"
} else {
    Write-Host "Hosts entry already present."
}

Write-Host ""
Write-Host "Done. Restart Apache from Laragon, then:"
Write-Host "  1. Set APP_URL=http://$domain in .env"
Write-Host "  2. Set VITE_DEV_PROXY_TARGET=http://$domain in .env"
Write-Host "  3. Run: composer dev:laragon"
Write-Host "  4. Open: http://$domain"
