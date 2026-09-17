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
php artisan migrate --force
php artisan db:seed-if-empty

# Keep a background process alive: restart it whenever it exits.
supervise() {
    (while true; do "$@" || true; sleep 5; done) > /dev/null 2>&1 &
}

supervise php artisan serve --host=127.0.0.1 --port=8000
supervise php artisan reverb:start --host=127.0.0.1 --port=8080
supervise php artisan queue:work --queue=default --sleep=1 --tries=3 --timeout=90 --max-time=3600
supervise php artisan schedule:work

sed "s/__PORT__/${PORT:-10000}/" deploy/render/nginx.conf > /tmp/nginx.conf
exec nginx -e /dev/stderr -c /tmp/nginx.conf -g 'daemon off;'
