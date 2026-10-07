#!/bin/sh
set -eu
composer install --no-interaction --prefer-dist
php artisan config:clear
if ! grep -q '^APP_KEY=base64:' .env; then
    php artisan key:generate --force
fi
php artisan migrate --force
php artisan saml:bootstrap-local
exec php artisan serve --host=0.0.0.0 --port=8000
