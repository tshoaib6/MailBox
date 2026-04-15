FROM php:8.3-fpm-alpine AS base

# ─── System packages ──────────────────────────────────────────────────────────
RUN apk add --no-cache \
        git \
        curl \
        unzip \
        nginx \
        supervisor \
        # GD
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        # ZIP / XML / intl
        libzip-dev \
        libxml2-dev \
        icu-dev \
        oniguruma-dev \
        # process tools
        shadow

# ─── PHP extensions ───────────────────────────────────────────────────────────
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        bcmath \
        mbstring \
        gd \
        zip \
        xml \
        pcntl \
        intl \
        opcache

# Redis (PECL)
RUN apk add --no-cache --virtual .phpize-deps $PHPIZE_DEPS \
 && pecl install redis \
 && docker-php-ext-enable redis \
 && apk del .phpize-deps

# ─── PHP config ───────────────────────────────────────────────────────────────
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php/php.ini     /usr/local/etc/php/conf.d/custom.ini

# ─── Composer ─────────────────────────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# ─── Install PHP dependencies ─────────────────────────────────────────────────
# Copy lockfiles first for better layer caching
COPY composer.json composer.lock* ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --ignore-platform-reqs

# ─── Copy application source ──────────────────────────────────────────────────
COPY . .

# ─── Apply our vendor patches ─────────────────────────────────────────────────
# These files were modified to add smtp2go (type 8) support.
COPY docker/vendor-patches/mettle/sendportal-core/src/Models/EmailServiceType.php \
     vendor/mettle/sendportal-core/src/Models/EmailServiceType.php
COPY docker/vendor-patches/mettle/sendportal-core/src/Services/QuotaService.php \
     vendor/mettle/sendportal-core/src/Services/QuotaService.php

# ─── Finalise autoloader ──────────────────────────────────────────────────────
RUN composer dump-autoload --no-dev --optimize

# Publish vendor assets (JS/CSS) using a temporary key — real key set at runtime
RUN APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
    APP_URL=http://localhost \
    DB_CONNECTION=sqlite \
    DB_DATABASE=:memory: \
    php artisan vendor:publish --force --tag=public --no-interaction 2>/dev/null || true

# ─── Permissions ──────────────────────────────────────────────────────────────
RUN chown -R www-data:www-data /var/www \
 && chmod -R 775 /var/www/storage \
 && chmod -R 775 /var/www/bootstrap/cache

# ─── Nginx ────────────────────────────────────────────────────────────────────
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf
# Remove default nginx welcome page
RUN rm -f /etc/nginx/http.d/default.conf.default 2>/dev/null || true

# ─── Supervisor ───────────────────────────────────────────────────────────────
COPY docker/supervisor/supervisord.conf /etc/supervisord.conf

# ─── Entrypoint ───────────────────────────────────────────────────────────────
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
