# syntax=docker/dockerfile:1
FROM php:8.2-fpm-bookworm AS php-base

RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates curl ffmpeg git unzip libfreetype6-dev libjpeg62-turbo-dev \
    libpng-dev libwebp-dev libzip-dev libicu-dev libonig-dev libsqlite3-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" bcmath exif gd intl mbstring opcache pcntl pdo_mysql pdo_sqlite zip \
    && pecl install redis-6.2.0 && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-library-stroll.ini
WORKDIR /var/www/html

FROM php-base AS dependencies
COPY composer.json composer.lock ./
RUN composer install --prefer-dist --no-interaction --no-progress --no-scripts

FROM node:22-bookworm-slim AS frontend
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
# Some Vue components import Ziggy directly from vendor.
COPY --from=dependencies /var/www/html/vendor ./vendor
ARG VITE_APP_NAME=LibraryStroll
ARG VITE_REVERB_APP_KEY=docker-local-key
ARG VITE_REVERB_HOST=localhost
ARG VITE_REVERB_PORT=8081
ARG VITE_REVERB_SCHEME=http
ENV VITE_APP_NAME=$VITE_APP_NAME VITE_REVERB_ENABLED=true \
    VITE_REVERB_APP_KEY=$VITE_REVERB_APP_KEY VITE_REVERB_HOST=$VITE_REVERB_HOST \
    VITE_REVERB_PORT=$VITE_REVERB_PORT VITE_REVERB_SCHEME=$VITE_REVERB_SCHEME
RUN npm run build

FROM php-base AS app
COPY . .
COPY --from=dependencies /var/www/html/vendor ./vendor
COPY --from=frontend /build/public/build ./public/build
RUN mkdir -p storage/app/public storage/app/private storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/framework/testing storage/logs bootstrap/cache \
    && ln -s /var/www/html/storage/app/public public/storage \
    && BROADCAST_CONNECTION=log composer dump-autoload --optimize --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
EXPOSE 9000
CMD ["php-fpm", "-F"]

FROM nginx:1.28-alpine AS web
WORKDIR /var/www/html
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public ./public
