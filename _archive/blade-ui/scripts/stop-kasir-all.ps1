param(
    [ValidateSet('edge', 'chrome', 'all')]
    [string]$Browser = 'all',
    [string]$Url = 'http://127.0.0.1:8000/admin/cashier'
)

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$scriptsDir = Join-Path $root 'scripts'

function Write-Info($message) {
    Write-Host "[kasir-stop] $message" -ForegroundColor Cyan
}

function Write-Ok($message) {
    Write-Host "[kasir-stop] $message" -ForegroundColor Green
}

function Write-WarnMsg($message) {
    Write-Host "[kasir-stop] $message" -ForegroundColor Yellow
}

function Stop-KioskBrowserProcesses {
    param(
        [string]$TargetBrowser,
        [string]$TargetUrl
    )

    $names = switch ($TargetBrowser) {
        'edge' { @('msedge.exe') }
        'chrome' { @('chrome.exe') }
        default { @('msedge.exe', 'chrome.exe') }
    }

    $stopped = 0
    foreach ($name in $names) {
        $procs = Get-CimInstance Win32_Process -Filter "Name = '$name'" -ErrorAction SilentlyContinue
        foreach ($p in ($procs | Where-Object {
            ($_.CommandLine -match '--kiosk') -or
            ($_.CommandLine -match '--edge-kiosk-type=fullscreen') -or
            ($_.CommandLine -match '--app=')
        })) {
            try {
                Stop-Process -Id $p.ProcessId -Force -ErrorAction Stop
                $stopped++
            } catch {
                Write-WarnMsg "Gagal stop $name PID $($p.ProcessId): $($_.Exception.Message)"
            }
        }
    }

    if ($stopped -gt 0) {
        Write-Ok "Proses kiosk browser dihentikan: $stopped"
    } else {
        Write-Info 'Tidak ada proses kiosk browser yang ditemukan.'
    }
}

Write-Info 'Menghentikan browser kiosk...'
Stop-KioskBrowserProcesses -TargetBrowser $Browser -TargetUrl $Url

Write-Info 'Menghentikan Laravel server di port 8000...'
$laravelListen = Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1
if ($laravelListen) {
    try {
        Stop-Process -Id $laravelListen.OwningProcess -Force -ErrorAction Stop
        Write-Ok "Laravel dihentikan (PID $($laravelListen.OwningProcess))"
    } catch {
        Write-WarnMsg "Gagal menghentikan Laravel PID $($laravelListen.OwningProcess): $($_.Exception.Message)"
    }
} else {
    Write-Info 'Laravel tidak sedang listening di port 8000.'
}

Write-Info 'Menghentikan realtime server...'
$realtimeStopScript = Join-Path $scriptsDir 'stop-realtime.ps1'
if (Test-Path $realtimeStopScript) {
    & powershell -NoProfile -ExecutionPolicy Bypass -File $realtimeStopScript
} else {
    Write-WarnMsg "Script stop realtime tidak ditemukan: $realtimeStopScript"
}

Write-Ok 'Semua proses kasir selesai dihentikan.'
