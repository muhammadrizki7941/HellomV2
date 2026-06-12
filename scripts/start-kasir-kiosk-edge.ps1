param(
    [string]$Url = "http://127.0.0.1:8000/admin/cashier",
    [switch]$AppMode
)

$edgePaths = @(
    "$Env:ProgramFiles (x86)\Microsoft\Edge\Application\msedge.exe",
    "$Env:ProgramFiles\Microsoft\Edge\Application\msedge.exe"
)

$edgeExe = $edgePaths | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $edgeExe) {
    Write-Error "Microsoft Edge tidak ditemukan di komputer ini."
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
        "--edge-kiosk-type=fullscreen",
        "--no-first-run",
        "--disable-infobars"
    )
}

Start-Process -FilePath $edgeExe -ArgumentList $args | Out-Null
Write-Host "Edge dijalankan dalam mode fullscreen kasir: $Url"
Write-Host "Keluar kiosk: Alt+F4"
