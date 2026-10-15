#!/bin/sh
# Starts the development server. Safe to run on a fresh checkout.
set -e

if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist
fi

if [ ! -f .env ]; then
    cp .env.example .env
fi

if ! grep -q '^APP_KEY=.\+' .env; then
    php artisan key:generate --no-interaction
fi

exec php artisan serve --host=0.0.0.0 --port=8000
