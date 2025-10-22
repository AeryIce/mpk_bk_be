# ---------- Stage 1: Composer (build vendor tanpa script) ----------
FROM public.ecr.aws/docker/library/composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
# Penting: --no-scripts mencegah artisan jalan di stage ini
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

# ---------- Stage 2: PHP 8.3 + Apache (runtime) ----------
FROM public.ecr.aws/docker/library/php:8.3-apache

# Lib & ekstensi PHP yang kita pakai
RUN apt-get update && apt-get install -y \
    git unzip libicu-dev libpq-dev libzip-dev \
    libpng-dev libjpeg-dev libfreetype6-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j$(nproc) pdo_pgsql intl gd zip opcache \
 && rm -rf /var/lib/apt/lists/*

# Mod rewrite untuk Laravel di Apache
RUN a2enmod rewrite

WORKDIR /var/www/html

# Copy seluruh source app
COPY . /var/www/html

# Bawa vendor hasil stage 1 + composer binary
COPY --from=vendor /app/vendor /var/www/html/vendor
COPY --from=public.ecr.aws/docker/library/composer:2 /usr/bin/composer /usr/bin/composer

# Permission storage & cache
RUN chown -R www-data:www-data storage bootstrap/cache \
 && chmod -R 775 storage bootstrap/cache

# Set document root ke /public dan port 8080 (Railway)
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf \
 && sed -ri -e 's!/var/www/html!/var/www/html/public!g' /etc/apache2/apache2.conf \
 && sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf

EXPOSE 8080
ENV PORT=8080

# Aman: hanya rebuild autoload (tanpa script artisan)
RUN composer dump-autoload -o

CMD ["apache2-foreground"]
