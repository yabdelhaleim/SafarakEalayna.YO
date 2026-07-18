<#
.SYNOPSIS
  Fix legacy module_type values in test files for liquidity accounts.
  
  The AccountModuleContract requires:
    - Liquidity (cashbox/wallet/bank): module_type must be 'office' or 'tourism'
    - Subject (customer/supplier): module_type must be a specific module (bus, fawry, etc.)
  
  This script scans test files and for EACH Account::create() block that is a liquidity type,
  maps the specific module_type to its division-level equivalent.

  Tourism modules (flights, hajj_umra, visas) -> 'tourism'
  Office modules (bus, fawry, online, wallet_transfer) -> 'office'
  general (when used on cashbox/bank/wallet) -> 'office'
#>

$testPath = "c:\travile\SafarakEalayna\tests"
$files = Get-ChildItem -Path $testPath -Recurse -Include "*.php"

# Map of old specific module_type to new division-level
# For liquidity accounts only
$tourismModules = @('flights', 'hajj_umra', 'visas')
$officeModules  = @('bus', 'fawry', 'online', 'wallet_transfer', 'general')

$totalFixed = 0

foreach ($file in $files) {
    $content = Get-Content -Path $file.FullName -Raw -Encoding UTF8
    $original = $content
    
    # We need to be context-aware: only replace module_type in blocks
    # that have type=cashbox/bank/wallet (liquidity)
    # Strategy: use regex to find Account create blocks and check their type
    
    # Pattern: find lines with "'module_type' => 'X'" that are inside blocks
    # also containing "'type' => 'cashbox'" or "'type' => 'bank'" or "'type' => 'wallet'"
    # or AccountType::Cashbox / AccountType::Bank / AccountType::Wallet
    
    # Split into account creation blocks roughly (between Account::create([ and ]);)
    # This is tricky in multiline regex. Use a simpler per-line approach:
    # Look for lines that explicitly set legacy module_type in context of known liquidity test fixtures.
    
    # Simple approach: in test files, replace specific module_type values on lines
    # that appear WITHIN a block that also has a cashbox/bank/wallet type line nearby.
    # We do this block by block.
    
    # Find all Account creation blocks
    $blockPattern = "Account::(?:query\(\)->|factory\(\)->)?create\(\[([^\]]+(?:\[[^\]]*\][^\]]*)*)\]\)"
    
    $modified = [regex]::Replace($content, $blockPattern, {
        param($match)
        $block = $match.Value
        
        # Check if this block is a liquidity account type
        $isLiquidity = ($block -match "'type'\s*=>\s*'(cashbox|bank|wallet)'" -or 
                        $block -match "AccountType::(Cashbox|Bank|Wallet)")
        
        if (-not $isLiquidity) {
            return $block  # Don't modify non-liquidity blocks
        }
        
        # Replace tourism-division specific modules
        foreach ($mod in $tourismModules) {
            $block = $block -replace "'module_type'\s*=>\s*'$mod'", "'module_type' => 'tourism'"
        }
        
        # Replace office-division specific modules
        foreach ($mod in $officeModules) {
            $block = $block -replace "'module_type'\s*=>\s*'$mod'", "'module_type' => 'office'"
        }
        
        return $block
    })
    
    if ($modified -ne $original) {
        Set-Content -Path $file.FullName -Value $modified -Encoding UTF8 -NoNewline
        $totalFixed++
        Write-Host "Fixed: $($file.Name)"
    }
}

Write-Host "`nTotal files fixed: $totalFixed"
