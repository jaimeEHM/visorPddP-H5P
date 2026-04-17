#!/bin/bash
set -e
cd /var/www/html

mkdir -p storage/bootstrap/cache storage/framework/{sessions,views,cache} bootstrap/cache

if [ ! -d vendor ]; then
  composer install --no-interaction --prefer-dist
fi

ensure_vite_manifest() {
  local manifest="public/build/manifest.json"
  local vite_manifest="public/build/.vite/manifest.json"
  local need_build=0

  if [ "${USE_VITE_BUILD:-0}" = "1" ]; then
    need_build=1
  fi

  if [ ! -f "$manifest" ] && [ ! -f "$vite_manifest" ]; then
    need_build=1
  fi

  if [ "$need_build" != "1" ]; then
    return 0
  fi

  if [ -f package-lock.json ]; then
    npm ci || npm install
  else
    npm install
  fi

  npm install --save-optional \
    @rollup/rollup-linux-arm64-gnu@latest \
    @rollup/rollup-linux-x64-gnu@latest \
    @rollup/rollup-darwin-arm64@latest \
    @rollup/rollup-win32-x64-msvc@latest

  if [ -f package-lock.json ]; then
    npm run build || (npm install && npm run build)
  else
    npm run build
  fi

  if [ ! -f "$manifest" ] && [ -f "$vite_manifest" ]; then
    mkdir -p public/build
    cp "$vite_manifest" "$manifest" || true
  fi
}

ensure_vite_manifest

if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
  php artisan migrate --force
fi

chown -R www-data:www-data storage bootstrap/cache || true

exec apache2-foreground
