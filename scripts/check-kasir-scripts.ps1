$ErrorActionPreference = 'Stop'

$tokens1 = $null
$errors1 = $null
[System.Management.Automation.Language.Parser]::ParseFile("$PSScriptRoot\start-kasir-all.ps1", [ref]$tokens1, [ref]$errors1) | Out-Null

$tokens2 = $null
$errors2 = $null
[System.Management.Automation.Language.Parser]::ParseFile("$PSScriptRoot\stop-kasir-all.ps1", [ref]$tokens2, [ref]$errors2) | Out-Null

$allErrors = @()
if ($errors1) { $allErrors += $errors1 }
if ($errors2) { $allErrors += $errors2 }

if ($allErrors.Count -gt 0) {
    $allErrors | ForEach-Object { Write-Host $_.Message -ForegroundColor Red }
    exit 1
}

Write-Host 'OK' -ForegroundColor Green
