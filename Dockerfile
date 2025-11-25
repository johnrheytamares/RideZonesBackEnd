FROM php:8.3-apache

# Install extensions
RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libfreetype6-dev zip unzip git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd pdo pdo_mysql mysqli \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable rewrite
RUN a2enmod rewrite

# Install Composer FIRST (bago pa mag-COPY ng code)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy code AFTER composer is installed
COPY . /var/www/html/

# Change document root
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

# Create folders + permissions
RUN mkdir -p /var/www/html/storage /var/www/html/public/uploads \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/storage \
    && chmod -R 775 /var/www/html/public/uploads

# Composer install (ngayon sure na makikita na niya ang composer.json)
RUN composer install --no-dev --no-interaction --optimize-autoloader --prefer-dist

EXPOSE 80
CMD ["apache2-foreground"]
