param(
    [ValidateSet('edge', 'chrome')]
    [string]$Browser = 'edge',
    [string]$Url = 'http://127.0.0.1:8000/admin/cashier',
    [switch]$AppMode
)

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$backendDir = Join-Path $root 'backend'
$scriptsDir = Join-Path $root 'scripts'

function Write-Info($message) {
    Write-Host "[kasir-all] $message" -ForegroundColor Cyan
}

function Write-Ok($message) {
    Write-Host "[kasir-all] $message" -ForegroundColor Green
}

function Write-WarnMsg($message) {
    Write-Host "[kasir-all] $message" -ForegroundColor Yellow
}

if (!(Test-Path $backendDir)) {
    throw "Backend folder not found: $backendDir"
}

Write-Info 'Memulai realtime server...'
$realtimeScript = Join-Path $scriptsDir 'start-realtime.ps1'
if (!(Test-Path $realtimeScript)) {
    throw "Script tidak ditemukan: $realtimeScript"
}

& powershell -NoProfile -ExecutionPolicy Bypass -File $realtimeScript

Write-Info 'Memeriksa Laravel server (port 8000)...'
$laravelListener = Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1
if (!$laravelListener) {
    $outLog = Join-Path $backendDir 'storage\logs\laravel-serve.out.log'
    $errLog = Join-Path $backendDir 'storage\logs\laravel-serve.err.log'

    try { if (Test-Path $outLog) { Remove-Item $outLog -Force } } catch {}
    try { if (Test-Path $errLog) { Remove-Item $errLog -Force } } catch {}

    Write-Info 'Menjalankan php artisan serve --port=8000 ...'
    Start-Process -FilePath 'php' `
        -WorkingDirectory $backendDir `
        -ArgumentList @('artisan', 'serve', '--host=127.0.0.1', '--port=8000') `
        -RedirectStandardOutput $outLog `
        -RedirectStandardError $errLog `
        -WindowStyle Hidden | Out-Null

    $ready = $false
    for ($i = 0; $i -lt 30; $i++) {
        Start-Sleep -Milliseconds 400
        try {
            $res = Invoke-WebRequest -Uri 'http://127.0.0.1:8000' -UseBasicParsing -TimeoutSec 2
            if ($res.StatusCode -ge 200) {
                $ready = $true
                break
            }
        } catch {
            # wait until ready
        }
    }

    if (!$ready) {
        Write-WarnMsg 'Laravel belum merespons di port 8000. Tetap lanjut buka browser kiosk.'
    } else {
        Write-Ok 'Laravel server aktif di http://127.0.0.1:8000'
    }
} else {
    Write-Ok "Laravel sudah aktif di :8000 (PID $($laravelListener.OwningProcess))"
}

$kioskScript = if ($Browser -eq 'chrome') {
    Join-Path $scriptsDir 'start-kasir-kiosk-chrome.ps1'
} else {
    Join-Path $scriptsDir 'start-kasir-kiosk-edge.ps1'
}

if (!(Test-Path $kioskScript)) {
    throw "Script kiosk tidak ditemukan: $kioskScript"
}

Write-Info "Membuka mode kasir fullscreen ($Browser)..."
if ($AppMode) {
    & powershell -NoProfile -ExecutionPolicy Bypass -File $kioskScript -Url $Url -AppMode
} else {
    & powershell -NoProfile -ExecutionPolicy Bypass -File $kioskScript -Url $Url
}

Write-Ok 'Selesai. Kasir siap dipakai.'
