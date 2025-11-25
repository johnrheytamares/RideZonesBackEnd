# Base PHP + Apache
FROM php:8.3-apache

# Install needed extensions + tools
RUN apt-get update && apt-get install -y \
    libpng-dev libjpeg-dev libfreetype6-dev zip unzip git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd pdo pdo_mysql mysqli \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache rewrite (para gumana ang pretty URLs mo)
RUN a2enmod rewrite

# Composer (kung may composer.json ka)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy lahat ng files
COPY . /var/www/html/

# Set permissions (safe kahit wala yung folders)
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && mkdir -p /var/www/html/storage \
    && mkdir -p /var/www/html/public/uploads \
    && chmod -R 775 /var/www/html/storage \
    && chmod -R 775 /var/www/html/public/uploads \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/public/uploads

# Change Apache document root to /public (importanteng-importante ‘to!)
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

# Composer install (safe kahit wala namang composer.json)
RUN composer install --no-dev --no-interaction --optimize-autoloader --prefer-dist

# Expose port
EXPOSE 80

# Start Apache
CMD ["apache2-foreground"]
