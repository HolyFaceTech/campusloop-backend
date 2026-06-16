# syntax=docker/dockerfile:1

FROM php:8.2-fpm-alpine AS base

RUN apk add --no-cache \
    bash \
    curl \
    fcgi \
    freetype-dev \
    git \
    icu-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    libwebp-dev \
    libzip-dev \
    nginx \
    oniguruma-dev \
    supervisor \
    unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        exif \
        gd \
        intl \
        mbstring \
        pcntl \
        pdo_mysql \
        zip \
    && rm -rf /var/cache/apk/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

FROM base AS development

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_PROCESS_TIMEOUT=600 \
    COMPOSER_CACHE_DIR=/tmp/composer-cache

RUN git config --global --add safe.directory /var/www/html

RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/entrypoint-worker.sh /usr/local/bin/entrypoint-worker.sh

RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/entrypoint-worker.sh

EXPOSE 80

ENTRYPOINT ["entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

FROM base AS production

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/entrypoint-worker.sh /usr/local/bin/entrypoint-worker.sh

RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/entrypoint-worker.sh

COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80

ENTRYPOINT ["entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
