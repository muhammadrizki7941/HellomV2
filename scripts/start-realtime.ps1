$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$workDir = Join-Path $root 'realtime'
$node = 'node'

$outLog = Join-Path $workDir 'realtime.out.log'
$errLog = Join-Path $workDir 'realtime.err.log'

if (!(Test-Path $workDir)) {
  throw "Realtime folder not found: $workDir"
}

# Ensure dependencies exist
if (!(Test-Path (Join-Path $workDir 'node_modules'))) {
  Write-Host '[realtime] node_modules missing, running npm install...' -ForegroundColor Yellow
  Push-Location $workDir
  try {
    npm install
  } finally {
    Pop-Location
  }
}

# If already listening, do nothing
try {
  $existing = Get-NetTCPConnection -LocalPort 3001 -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1
  if ($existing) {
    Write-Host "[realtime] Already listening on :3001 (PID $($existing.OwningProcess))" -ForegroundColor Green
    exit 0
  }
} catch {
  # ignore
}

# Rotate logs
try { if (Test-Path $outLog) { Remove-Item $outLog -Force } } catch {}
try { if (Test-Path $errLog) { Remove-Item $errLog -Force } } catch {}

Write-Host '[realtime] Starting Socket.IO server...' -ForegroundColor Cyan
Start-Process -FilePath $node `
  -WorkingDirectory $workDir `
  -ArgumentList 'server.js' `
  -RedirectStandardOutput $outLog `
  -RedirectStandardError $errLog `
  -WindowStyle Hidden

Start-Sleep -Seconds 1
$listen = Get-NetTCPConnection -LocalPort 3001 -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1
if (!$listen) {
  Write-Host '[realtime] FAILED to listen on :3001' -ForegroundColor Red
  if (Test-Path $errLog) {
    Write-Host '--- realtime.err.log ---' -ForegroundColor DarkGray
    Get-Content $errLog -ErrorAction SilentlyContinue | Select-Object -First 200
  }
  exit 1
}

Write-Host "[realtime] Listening on :3001 (PID $($listen.OwningProcess))" -ForegroundColor Green
