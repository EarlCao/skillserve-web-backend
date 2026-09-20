#!/bin/sh
# Render start-up: one container runs the API, Reverb WebSockets, the queue
# worker and the scheduler behind nginx on Render's public $PORT.
set -e

# Generate an app key only when the platform did not provide one.
if [ -z "$APP_KEY" ]; then
    php artisan key:generate --no-interaction --force
fi

# The app publishes broadcasts to Reverb inside this container. Clients
# (web admin, mobile app) connect through the public host on 443 instead.
export REVERB_HOST=127.0.0.1 REVERB_PORT=8080 REVERB_SCHEME=http
export REVERB_SERVER_HOST=127.0.0.1 REVERB_SERVER_PORT=8080

php artisan config:cache
php artisan route:cache
php artisan view:cache
# Profile photos are written to the `public` disk and served through
# public/storage, which is gitignored and so absent from a fresh container.
php artisan storage:link --force
php artisan migrate --force
php artisan db:seed-if-empty

# Keep a background process alive: restart it shortly after it exits.
supervise() {
    (while true; do "$@" || true; sleep 1; done) > /dev/null 2>&1 &
}

# Wait until something accepts connections on 127.0.0.1:$1 (max $2 seconds).
wait_for_port() {
    tries=0
    until php -r 'exit(@fsockopen("127.0.0.1", (int) $argv[1], $e, $m, 1) ? 0 : 1);' "$1"; do
        tries=$((tries + 1))
        if [ "$tries" -ge "$2" ]; then
            echo "Port $1 did not open within $2s; starting nginx anyway." >&2
            return 0
        fi
        sleep 1
    done
}

# PHP's built-in server directly (what `artisan serve` wraps): `artisan serve`
# ignores PHP_CLI_SERVER_WORKERS unless --no-reload is passed, and it hops to
# another port when 8000 is still held by a dying process.
# The router script treats the working directory as public/.
supervise sh -c 'cd public && exec php -S 127.0.0.1:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'
supervise php artisan reverb:start --host=127.0.0.1 --port=8080
supervise php artisan queue:work --queue=default --sleep=1 --tries=3 --timeout=90 --max-time=3600
supervise php artisan schedule:work

# Open the public port only once the app and Reverb are ready, so Render
# never routes requests to a server that is still starting.
wait_for_port 8000 60
wait_for_port 8080 30

sed "s/__PORT__/${PORT:-10000}/" deploy/render/nginx.conf > /tmp/nginx.conf
exec nginx -e /dev/stderr -c /tmp/nginx.conf -g 'daemon off;'
