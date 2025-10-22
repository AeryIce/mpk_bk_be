# ---------- Stage 1: Composer (build vendor tanpa script) ----------
FROM public.ecr.aws/docker/library/composer:2 AS vendor
WORKDIR /app
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY composer.json composer.lock ./
# Penting: --no-scripts mencegah artisan dipanggil saat build (belum ada artisan)
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

# ---------- Stage 2: PHP 8.3 + Apache (runtime) ----------
FROM public.ecr.aws/docker/library/php:8.3-apache

# Lib & ekstensi PHP yang diperlukan
# (NOTE: libonig-dev Wajib agar mbstring sukses compile)
RUN apt-get update && apt-get install -y \
    git unzip libicu-dev libpq-dev libzip-dev \
    libpng-dev libjpeg-dev libfreetype6-dev libonig-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl gd zip opcache mbstring bcmath \
 && rm -rf /var/lib/apt/lists/*

# Aktifkan rewrite + izinkan .htaccess di /public + prioritas index.php
RUN a2enmod rewrite && \
    printf "<Directory /var/www/html/public>\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>\n" > /etc/apache2/conf-available/laravel.conf && \
    a2enconf laravel && \
    sed -i 's/DirectoryIndex .*/DirectoryIndex index.php index.html/' /etc/apache2/mods-enabled/dir.conf

# Apache hardening ringan
RUN printf "ServerTokens Prod\nServerSignature Off\n" > /etc/apache2/conf-available/hardening.conf && a2enconf hardening

# PHP production tweaks (WITA, batasan wajar, OPcache)
RUN printf "date.timezone=Asia/Jakarta\n\
memory_limit=256M\n\
upload_max_filesize=16M\n\
post_max_size=16M\n\
opcache.enable=1\n\
opcache.enable_cli=1\n\
opcache.validate_timestamps=0\n\
opcache.max_accelerated_files=20000\n\
opcache.memory_consumption=192\n\
opcache.interned_strings_buffer=16\n" > /usr/local/etc/php/conf.d/zz-prod.ini

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
