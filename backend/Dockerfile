# syntax=docker/dockerfile:1.7

ARG PHP_VERSION=8.3

FROM php:${PHP_VERSION}-fpm-bookworm AS php-base

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    PATH="/var/www/html/vendor/bin:${PATH}"

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        libcurl4-openssl-dev \
        libonig-dev \
        libpq-dev \
        libsqlite3-dev \
        libxml2-dev \
        unzip \
    && docker-php-ext-install -j"$(nproc)" \
        curl \
        dom \
        mbstring \
        opcache \
        pcntl \
        pdo_pgsql \
        pdo_sqlite \
        posix \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# fileinfo is compiled into the official PHP image already.
RUN php -m | grep -qx fileinfo

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /var/www/html

FROM php-base AS vendor-development

COPY composer.json composer.lock ./
RUN composer install \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --no-scripts

FROM php-base AS vendor-production

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

FROM php-base AS application-base

COPY . .
COPY docker/php.ini /usr/local/etc/php/conf.d/99-auditseo.ini
COPY docker/entrypoint.sh /usr/local/bin/auditseo-entrypoint

RUN chmod +x /usr/local/bin/auditseo-entrypoint \
    && sed -ri '/^(user|group)\s*=/d' /usr/local/etc/php-fpm.d/www.conf \
    && mkdir -p \
        bootstrap/cache \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && if [ ! -e public/storage ]; then ln -s ../storage/app/public public/storage; fi \
    && chown -R www-data:www-data bootstrap/cache storage

ENTRYPOINT ["auditseo-entrypoint"]
CMD ["php-fpm", "-F"]

FROM application-base AS development

COPY --from=vendor-development --chown=www-data:www-data /var/www/html/vendor ./vendor
RUN APP_ENV=local composer dump-autoload --optimize --no-interaction

USER www-data

FROM application-base AS production

COPY --from=vendor-production --chown=www-data:www-data /var/www/html/vendor ./vendor
RUN APP_ENV=local composer dump-autoload \
        --no-dev \
        --classmap-authoritative \
        --no-interaction \
    && chown -R www-data:www-data bootstrap/cache storage

USER www-data

FROM nginx:1.28-alpine AS nginx

WORKDIR /var/www/html
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=production /var/www/html/public ./public
RUN mkdir -p storage/app/public \
    && if [ ! -e public/storage ]; then ln -s ../storage/app/public public/storage; fi
