# --- Base: PHP 8.3 + Apache ---
FROM php:8.3-apache

# System deps
RUN apt-get update && apt-get install -y \
    git unzip libicu-dev libpq-dev libzip-dev \
    libpng-dev libjpeg-dev libfreetype6-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j$(nproc) pdo_pgsql intl gd zip opcache \
 && rm -rf /var/lib/apt/lists/*

# Enable Apache rewrite
RUN a2enmod rewrite

# Workdir & copy source
WORKDIR /var/www/html
COPY . /var/www/html

# Composer (from official image)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Install PHP deps (production)
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Laravel perms
RUN chown -R www-data:www-data storage bootstrap/cache \
 && chmod -R 775 storage bootstrap/cache

# Apache docroot -> public
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf \
 && sed -ri -e 's!/var/www/html!/var/www/html/public!g' /etc/apache2/apache2.conf

# Railway listens on $PORT
EXPOSE 8080
ENV PORT=8080
RUN sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf

# Start web server
CMD ["apache2-foreground"]
