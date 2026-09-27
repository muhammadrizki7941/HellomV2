#!/usr/bin/env bash
# Deploy/update Hellom on the VPS (aaPanel). Idempotent: safe to re-run.
#
#   cd /www/wwwroot/hellomspace.com && bash deploy/deploy.sh
#
# Options (env vars):
#   BRANCH=main          git branch to deploy
#   SKIP_MIGRATE=1       do not run migrations
#   SKIP_PULL=1          build the currently checked-out commit (rollback)
#
# Never runs destructive commands (no migrate:fresh / db:wipe).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BRANCH="${BRANCH:-main}"

step() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m[warn] %s\033[0m\n' "$*"; }

cd "$ROOT"

[ -f backend/.env ] || { echo "backend/.env is missing (copy backend/.env.example and fill it in)"; exit 1; }
[ -f frontend/.env.production ] || warn "frontend/.env.production not found: VITE_HELLOM_API_BASE falls back to 127.0.0.1"
[ -f realtime/.env ] || warn "realtime/.env not found: realtime will use the default secret"

if [ "${SKIP_PULL:-0}" != "1" ]; then
  step "git pull ($BRANCH)"
  git pull --ff-only origin "$BRANCH"
else
  warn "SKIP_PULL=1: deploying the current checkout ($(git rev-parse --short HEAD))"
fi

step "backend: composer install"
cd "$ROOT/backend"
# Stale package-discovery caches break artisan after packages are removed; they are regenerated.
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php
composer install --no-dev --optimize-autoloader --no-interaction

if [ "${SKIP_MIGRATE:-0}" != "1" ]; then
  step "backend: migrate"
  php artisan migrate --force
fi

step "backend: caches"
php artisan config:cache
if ! php artisan route:cache; then
  warn "route:cache failed (duplicate route names?); continuing without route cache"
  php artisan route:clear
fi
php artisan view:cache
php artisan queue:restart || true

step "frontend: build → backend/public/hellom"
cd "$ROOT/frontend"
npm ci --include=dev --no-audit --no-fund
npm run build

step "realtime: install + (re)start via PM2"
cd "$ROOT/realtime"
npm ci --omit=dev --no-audit --no-fund
cd "$ROOT"
pm2 startOrReload deploy/ecosystem.config.js --update-env
pm2 save

step "done"
echo "Check: https://hellomspace.com  ·  pm2 status  ·  crontab -l | grep schedule:run"
