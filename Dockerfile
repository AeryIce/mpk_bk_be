# Dockerfile (Laravel + PHP 8.3 + GD + PostgreSQL)
FROM php:8.3-cli

# 1) system deps
RUN apt-get update && apt-get install -y \
    git unzip libpq-dev \
    libjpeg-dev libpng-dev libwebp-dev libfreetype6-dev \
    libzip-dev \
 && rm -rf /var/lib/apt/lists/*

# 2) PHP extensions
RUN docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
 && docker-php-ext-install -j$(nproc) gd pdo_pgsql zip opcache

# 3) Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app
COPY . /app

# 4) Composer install (boleh no-dev kalau mau)
RUN composer install --no-interaction --prefer-dist --optimize-autoloader

# 5) Jangan cache config/route di build-time (ENV Railway belum ada saat build)
#    -> HAPUS baris berikut dari versi lama:
# RUN php artisan config:cache && php artisan route:cache

# Default ENV supaya gak jatuh ke sqlite & database cache
ENV CACHE_DRIVER=file \
    SESSION_DRIVER=file \
    QUEUE_CONNECTION=sync

# Railway
ENV PORT=8080
EXPOSE 8080

# 6) Start: clear caches di runtime (ENV sudah terpasang dari Railway)
CMD ["/bin/sh", "-lc", "php artisan config:clear && php artisan route:clear && php artisan view:clear && php artisan serve --host=0.0.0.0 --port=8080"]
