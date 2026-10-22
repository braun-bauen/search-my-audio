#!/bin/sh
set -eu

if [ "$1" = supervisord ]; then
    cd /app
    # Runtime mounts may hide directories created in the image.
    mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
    chown -R www-data:www-data storage bootstrap/cache
    # Runtime configuration and database credentials must never enter build layers.
    runuser -u www-data -- php artisan package:discover --ansi
    runuser -u www-data -- php artisan migrate --force
    runuser -u www-data -- php artisan optimize
fi

exec "$@"
