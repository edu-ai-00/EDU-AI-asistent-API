#!/bin/bash
set -e

php artisan config:clear
php artisan route:clear
php artisan migrate --force

if [ -n "$NIGHTWATCH_TOKEN" ]; then
    echo "Starting Nightwatch agent..."
    php artisan nightwatch:agent &
fi

php artisan serve --host=0.0.0.0 --port=${PORT:-8080}
