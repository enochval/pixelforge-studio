# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Base: PHP and the extensions this application actually needs.
#
#   pdo_pgsql  Postgres driver
#   bcmath     arbitrary-precision arithmetic — used to convert published rate
#              strings into integer micro-dollars without touching a float
#   pcntl      lets queue workers handle signals, so they shut down cleanly
#              mid-job instead of being killed
#   opcache    avoids recompiling the framework on every request
# ---------------------------------------------------------------------------
FROM php:8.3-cli-alpine AS base

RUN apk add --no-cache postgresql-dev linux-headers $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql bcmath pcntl opcache \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1

# ---------------------------------------------------------------------------
# Development: the source tree is bind-mounted rather than copied, so the image
# carries the toolchain and nothing else. Dependencies are installed into the
# mount by `make install`, which runs composer inside this container.
# ---------------------------------------------------------------------------
FROM base AS development

RUN apk add --no-cache git bash

COPY docker/php/development.ini /usr/local/etc/php/conf.d/zz-app.ini

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

# ---------------------------------------------------------------------------
# PHP dependencies, resolved once and shared by the asset and runtime stages.
# ---------------------------------------------------------------------------
FROM base AS vendor

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader

# ---------------------------------------------------------------------------
# Asset build — separate stage so Node never reaches the runtime image.
#
# Ziggy is imported from vendor/ by the Inertia entrypoint, so the JavaScript
# build genuinely depends on a PHP package. Only that one directory is copied
# in, rather than the whole vendor tree, to keep this layer small.
# ---------------------------------------------------------------------------
FROM node:20-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY vite.config.js tailwind.config.js postcss.config.js ./
COPY resources ./resources
COPY --from=vendor /app/vendor/tightenco/ziggy ./vendor/tightenco/ziggy
RUN npm run build

# ---------------------------------------------------------------------------
# Production: no Composer dev dependencies, no Node, compiled assets only, and
# an authoritative classmap. The hosting platform builds its own image, so this
# stage exists to keep the production build verifiable locally and in CI rather
# than assumed.
# ---------------------------------------------------------------------------
FROM base AS production

COPY docker/php/production.ini /usr/local/etc/php/conf.d/zz-app.ini

COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && addgroup -g 1000 app \
    && adduser -u 1000 -G app -s /bin/sh -D app \
    && chown -R app:app storage bootstrap/cache

USER app

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
