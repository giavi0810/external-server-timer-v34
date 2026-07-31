FROM node:22-alpine AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY resources ./resources
COPY public ./public
COPY vite.config.js ./

RUN npm run build

FROM alpine:3.22 AS fsync-helper

RUN apk add --no-cache build-base
COPY docker/fsync-dir.c /src/fsync-dir.c
RUN cc -O2 -Wall -Wextra -o /usr/local/bin/fsync-dir /src/fsync-dir.c

FROM php:8.4-fpm-alpine AS app

# Set working directory
WORKDIR /var/www/html

# Install system dependencies
RUN apk add --no-cache \
    postgresql-dev \
    libpng-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    curl \
    oniguruma-dev \
    $PHPIZE_DEPS

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_pgsql pgsql bcmath gd zip opcache mbstring

# Install PECL redis
RUN pecl install redis && docker-php-ext-enable redis

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Install PHP dependencies before copying the application to improve build cache.
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader

# Copy PHP custom config
COPY docker/php/local.ini /usr/local/etc/php/conf.d/local.ini
COPY --from=fsync-helper /usr/local/bin/fsync-dir /usr/local/bin/fsync-dir

# Copy project code
COPY . .

# Copy the production frontend assets from the Node build stage.
COPY --from=frontend /app/public/build ./public/build

# Finish Laravel discovery and prepare all runtime-writable directories.
RUN composer dump-autoload \
        --no-dev \
        --classmap-authoritative \
        --no-interaction \
    && mkdir -p \
        storage/app/public \
        storage/app/freshdesk-spool/temporary \
        storage/app/freshdesk-spool/ready \
        storage/app/freshdesk-spool/enqueued \
        storage/app/freshdesk-spool/processing \
        storage/app/freshdesk-spool/committed-gc \
        storage/app/freshdesk-spool/quarantine \
        storage/app/rocketchat-audit/temporary \
        storage/app/rocketchat-audit/pending \
        storage/app/rocketchat-audit/ready \
        storage/app/rocketchat-audit/processing \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && ln -sfn ../storage/app/public public/storage \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 9000

CMD ["php-fpm"]

FROM nginx:alpine AS webserver

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
