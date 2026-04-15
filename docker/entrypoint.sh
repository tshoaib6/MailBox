#!/bin/sh
set -e

echo "==> Starting Dnova Email Marketing setup..."

# ── Wait for MySQL ─────────────────────────────────────────────────────────────
echo "--> Waiting for MySQL at ${DB_HOST:-mysql}:${DB_PORT:-3306}..."
until php -r "
    try {
        new PDO('mysql:host=${DB_HOST:-mysql};port=${DB_PORT:-3306};dbname=${DB_DATABASE}', '${DB_USERNAME}', '${DB_PASSWORD}');
        echo 'ok';
    } catch (Exception \$e) {
        exit(1);
    }
" 2>/dev/null | grep -q ok; do
    echo "    MySQL not ready — retrying in 3s..."
    sleep 3
done
echo "--> MySQL is ready."

# ── Laravel bootstrap ──────────────────────────────────────────────────────────
echo "--> Running migrations..."
php artisan migrate --force

echo "--> Fixing storage permissions..."
chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache
chmod -R ug+rw /var/www/storage /var/www/bootstrap/cache

echo "--> Creating storage symlink..."
php artisan storage:link --force 2>/dev/null || true

echo "--> Seeding default users (idempotent)..."
php artisan db:seed --class=DatabaseSeeder --force

echo "--> Caching config, routes, views..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> Setup complete. Starting services..."

# ── Start everything via supervisord ───────────────────────────────────────────
exec /usr/bin/supervisord -n -c /etc/supervisord.conf
