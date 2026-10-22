#!/usr/bin/env bash
set -euo pipefail

image=${1:?Usage: smoke-test.sh IMAGE}
container="search-my-audio-smoke-${RANDOM}"
cleanup() {
    result=$?
    if (( result != 0 )); then docker logs "$container" || true; fi
    docker rm -f "$container" >/dev/null 2>&1 || true
    exit "$result"
}
trap cleanup EXIT

docker run --rm "$image" php -r 'exit(PHP_VERSION === "8.5.12" ? 0 : 1);'
docker run --rm "$image" php-fpm -v | grep -F 'PHP 8.5.12'
docker run --rm "$image" composer check-platform-reqs --no-dev
docker run --rm "$image" sh -c 'test -f public/build/manifest.json && test ! -e .env && test ! -e auth.json'

docker run -d --name "$container" \
    -e APP_KEY="base64:$(openssl rand -base64 32)" \
    -e DB_CONNECTION=sqlite -e DB_DATABASE=/app/storage/testing.sqlite \
    -e CACHE_STORE=array -e SESSION_DRIVER=array \
    "$image" sh -c 'touch /app/storage/testing.sqlite; exec app-entrypoint supervisord -c /etc/supervisor/conf.d/app.conf'

ready=false
for (( attempt=0; attempt<60; attempt++ )); do
    if docker exec "$container" curl --fail --silent http://127.0.0.1/up >/dev/null; then
        ready=true
        break
    fi
    sleep 2
done
test "$ready" = true
processes=$(docker exec "$container" supervisorctl -c /etc/supervisor/conf.d/app.conf status)
printf '%s\n' "$processes"
test "$(printf '%s\n' "$processes" | grep -c RUNNING)" -eq 14
! printf '%s\n' "$processes" | grep -Eq 'FATAL|BACKOFF|EXITED'
docker exec "$container" php artisan migrate:status --no-ansi
test "$(docker exec "$container" curl --silent --output /dev/null --write-out '%{http_code}' http://127.0.0.1/.env)" = 403
test "$(docker exec "$container" curl --silent --output /dev/null --write-out '%{http_code}' http://127.0.0.1/arbitrary.php)" = 404
