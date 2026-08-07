# Laravel backend — PHP 8.3 + PostgreSQL (pdo_pgsql)
FROM php:8.3-cli-alpine

# System dependencies required to build the pdo_pgsql extension.
# --network=host: the VM sandbox blocks bridge-network egress during builds.
RUN --network=host apk add --no-cache libpq-dev \
    && docker-php-ext-install pdo_pgsql

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
# run pending migrations, then serve the app.
CMD ["sh", "-c", "composer install --prefer-dist --no-progress --no-interaction \
    && (php artisan key:generate --no-interaction || true) \
    && php artisan migrate --force \
    && php artisan serve --host=0.0.0.0 --port=8000"]
