# syntax=docker/dockerfile:1

FROM php:8.5.12-fpm-bookworm AS php-base

RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl libicu-dev libonig-dev libpq-dev libzip-dev \
        nginx supervisor unzip util-linux \
    && docker-php-ext-install -j"$(nproc)" bcmath intl mbstring pcntl pdo_mysql pdo_pgsql zip \
    && rm -rf /var/lib/apt/lists/* \
    && php -r 'exit(PHP_VERSION === "8.5.12" ? 0 : 1);'

WORKDIR /app
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf

FROM php-base AS vendor
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY composer.json composer.lock ./
# Coolify: enable Docker BuildKit secrets and make these two variables build-only.
# Authentication exists only for this RUN; it is never written to auth.json.
RUN --mount=type=secret,id=FLUX_USERNAME,required=true \
    --mount=type=secret,id=FLUX_LICENSE_KEY,required=true \
    export COMPOSER_AUTH="$(php -r 'echo json_encode(["http-basic" => ["composer.fluxui.dev" => ["username" => trim(file_get_contents("/run/secrets/FLUX_USERNAME")), "password" => trim(file_get_contents("/run/secrets/FLUX_LICENSE_KEY"))]]]);')" \
    && composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader \
    && composer clear-cache
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts \
    && composer check-platform-reqs --no-dev

FROM node:22.23.4-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
# Tailwind scans Flux's vendor templates when producing the CSS bundle.
COPY --from=vendor /app /app
RUN npm run build

FROM php-base AS production
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr
COPY --from=vendor /app /app
COPY --from=assets /app/public/build /app/public/build
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/app.conf
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/app-entrypoint
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && nginx -t && php-fpm -t \
    && composer check-platform-reqs --no-dev
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD curl --fail --silent http://127.0.0.1/up > /dev/null || exit 1
ENTRYPOINT ["app-entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/app.conf"]
