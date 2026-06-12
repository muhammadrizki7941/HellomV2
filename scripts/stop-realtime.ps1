$ErrorActionPreference = 'Stop'

try {
  $listen = Get-NetTCPConnection -LocalPort 3001 -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1
  if (!$listen) {
    Write-Host '[realtime] Not running (no listener on :3001)' -ForegroundColor Yellow
    exit 0
  }

  $processId = $listen.OwningProcess
  Write-Host "[realtime] Stopping PID $processId" -ForegroundColor Cyan
  Stop-Process -Id $processId -Force
  Start-Sleep -Milliseconds 300

  $still = Get-NetTCPConnection -LocalPort 3001 -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1
  if ($still) {
    Write-Host '[realtime] Still listening on :3001 (stop failed)' -ForegroundColor Red
    exit 1
  }

  Write-Host '[realtime] Stopped' -ForegroundColor Green
} catch {
  Write-Host "[realtime] Stop failed: $($_.Exception.Message)" -ForegroundColor Red
  exit 1
}
