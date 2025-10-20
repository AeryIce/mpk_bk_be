# ---------- Stage 1: Composer (build vendor) ----------
FROM public.ecr.aws/docker/library/composer:2 AS vendor
WORKDIR /app

# copy only composer files first (better cache)
COPY composer.json composer.lock ./
# resolve deps for production
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# now copy the rest of the app (for classmap optimization later if needed)
COPY . .

# ---------- Stage 2: PHP 8.3 + Apache (runtime) ----------
FROM public.ecr.aws/docker/library/php:8.3-apache

# system deps
RUN apt-get update && apt-get install -y \
    git unzip libicu-dev libpq-dev libzip-dev \
    libpng-dev libjpeg-dev libfreetype6-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j$(nproc) pdo_pgsql intl gd zip opcache \
 && rm -rf /var/lib/apt/lists/*

# apache rewrite
RUN a2enmod rewrite

# app dir
WORKDIR /var/www/html

# copy source code
COPY . /var/www/html

# copy vendor from composer stage (biar gak install di runtime image)
COPY --from=vendor /app/vendor /var/www/html/vendor

# composer binary (useful for future post-deploy cmds)
COPY --from=public.ecr.aws/docker/library/composer:2 /usr/bin/composer /usr/bin/composer

# permissions for Laravel
RUN chown -R www-data:www-data storage bootstrap/cache \
 && chmod -R 775 storage bootstrap/cache

# apache docroot -> public
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf \
 && sed -ri -e 's!/var/www/html!/var/www/html/public!g' /etc/apache2/apache2.conf

# railway port
EXPOSE 8080
ENV PORT=8080
RUN sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf

CMD ["apache2-foreground"]
