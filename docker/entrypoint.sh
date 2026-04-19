#!/bin/sh
set -e

echo "==> Starting Ares IRC Services initialization"

# 1. Ensure .env.local exists
if [ ! -f /app/.env.local ]; then
    echo "==> Creating .env.local from template"

    if [ ! -f /app/.env ]; then
        echo "ERROR: /app/.env not found"
        exit 1
    fi

    cp /app/.env /app/.env.local
fi

# 2. Generate APP_SECRET if it's missing or has default value
if ! grep -q "^APP_SECRET=." /app/.env.local 2>/dev/null; then
    # APP_SECRET is missing or empty
    echo "==> Generating APP_SECRET (missing)"
    SECRET=$(php -r 'echo bin2hex(random_bytes(16));')
    echo "APP_SECRET=${SECRET}" >> /app/.env.local
    echo "==> APP_SECRET generated: ${SECRET:0:8}..."
elif grep -q "^APP_SECRET=changeme" /app/.env.local 2>/dev/null; then
    echo "==> Generating APP_SECRET (replacing default)"
    SECRET=$(php -r 'echo bin2hex(random_bytes(16));')

    grep -v "^APP_SECRET=" /app/.env.local > /tmp/env.tmp || true
    echo "APP_SECRET=${SECRET}" >> /tmp/env.tmp
    cat /tmp/env.tmp > /app/.env.local
    rm /tmp/env.tmp

    echo "==> APP_SECRET generated: ${SECRET:0:8}..."
fi

# 3. Sync .env.local with new keys from .env
/app/docker/sync-env.sh

# 4. Run composer install
echo "==> Running composer install"
composer install -n --no-dev --optimize-autoloader --classmap-authoritative --no-scripts

# 5. Run database migrations
echo "==> Running database migrations"
php bin/console doctrine:migrations:migrate -n

# 6. Start IRC services
echo "==> Starting Ares IRC Services"
exec php bin/console irc:connect
