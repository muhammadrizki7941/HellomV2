param(
    [string]$Url = "http://127.0.0.1:8000/admin/cashier",
    [switch]$AppMode
)

$chromePaths = @(
    "$Env:ProgramFiles\Google\Chrome\Application\chrome.exe",
    "$Env:ProgramFiles (x86)\Google\Chrome\Application\chrome.exe"
)

$chromeExe = $chromePaths | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $chromeExe) {
    Write-Error "Google Chrome tidak ditemukan di komputer ini."
    exit 1
}

if ($AppMode) {
    $args = @(
        "--app=$Url",
        "--start-fullscreen",
        "--no-first-run",
        "--disable-infobars"
    )
} else {
    $args = @(
        "--kiosk", $Url,
        "--no-first-run",
        "--disable-infobars"
    )
}

Start-Process -FilePath $chromeExe -ArgumentList $args | Out-Null
Write-Host "Chrome dijalankan dalam mode fullscreen kasir: $Url"
Write-Host "Keluar kiosk: Alt+F4"
