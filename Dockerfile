# Dockerfile (Laravel + PHP 8.3 + GD + PostgreSQL)
FROM php:8.3-cli

# 1) system deps
RUN apt-get update && apt-get install -y \
    git unzip libpq-dev \
    libjpeg-dev libpng-dev libwebp-dev libfreetype6-dev \
    libzip-dev \
 && rm -rf /var/lib/apt/lists/*

# 2) PHP extensions: gd (untuk PhpSpreadsheet), pdo_pgsql, zip, opcache
RUN docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
 && docker-php-ext-install -j$(nproc) gd pdo_pgsql zip opcache

# 3) Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app
COPY . /app

# 4) Install deps
RUN composer install --no-interaction --prefer-dist --optimize-autoloader

# 5) Optimize Laravel
RUN php artisan config:cache && php artisan route:cache

# Railway
ENV PORT=8080
EXPOSE 8080

# 6) Start
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8080"]
