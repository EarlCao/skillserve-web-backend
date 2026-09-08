# Laravel backend — PHP 8.3 + PostgreSQL (pdo_pgsql)
FROM php:8.3-cli-alpine

# System dependencies and PHP extensions:
#   pdo_pgsql        — PostgreSQL driver
#   gd               — image processing (medialibrary, intervention/image, dompdf, phpspreadsheet)
#   zip              — archive support (maatwebsite/excel, spatie/laravel-backup)
#   exif             — image metadata (spatie/laravel-medialibrary)
#   pcntl            — process signals (laravel/reverb WebSocket server)
# --network=host: the VM sandbox blocks bridge-network egress during builds.
RUN --network=host apk add --no-cache \
        libpq-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install pdo_pgsql gd zip exif pcntl

# Composer (PHP package manager)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Run as a non-root user whose UID (1000) matches the host user,
# so files written to the bind-mounted source keep compatible ownership.
ARG UID=1000
RUN addgroup -g ${UID} -S app && adduser -u ${UID} -S app -G app
USER app

WORKDIR /var/www/html

# Install dependencies at build time (used when running without a bind mount)
COPY --chown=app:app composer.json composer.lock ./
RUN --network=host composer install --prefer-dist --no-progress --no-interaction --no-scripts \
    && composer dump-autoload --optimize --no-scripts

COPY --chown=app:app . .

EXPOSE 8000

# On start: sync dependencies, generate an app key only if missing,
# run pending migrations, start the scheduler and queue worker in the
# background, then serve the app.
CMD ["sh", "-c", "composer install --prefer-dist --no-progress --no-interaction \
    && (php artisan key:generate --no-interaction || true) \
    && php artisan migrate --force \
    && (php artisan schedule:work > /dev/null 2>&1 &) \
    && (php artisan queue:work --queue=default --sleep=3 --tries=3 --timeout=90 > /dev/null 2>&1 &) \
    && php artisan serve --host=0.0.0.0 --port=8000"]
