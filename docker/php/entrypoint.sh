#!/usr/bin/env sh
set -e

cd /var/www/html

if [ ! -f .env ]; then
  cp .env.example .env
fi

mkdir -p \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs \
  bootstrap/cache

chmod -R 775 storage bootstrap/cache || true
chown -R www-data:www-data storage bootstrap/cache || true

while ! pg_isready -h "${DB_HOST:-db}" -p "${DB_PORT:-5432}" -U "${DB_USERNAME:-waveflow}" >/dev/null 2>&1; do
  sleep 1
done

rm -f bootstrap/cache/*.php || true

php artisan storage:link || true
php artisan migrate --force

if [ "${APP_SEED:-true}" = "true" ]; then
  php artisan db:seed --force
fi

exec "$@"
