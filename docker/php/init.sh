#!/bin/sh
set -eu

if [ -z "${APP_KEY:-}" ]; then
    echo 'APP_KEY is missing. Run scripts/docker.ps1 init first.' >&2
    exit 1
fi

php artisan migrate --force --no-interaction
php artisan db:seed --force --no-interaction
echo 'Database initialized. Existing application data was preserved.'
