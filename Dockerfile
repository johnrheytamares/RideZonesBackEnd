FROM php:8.2-apache

# Install required system packages + PHP extensions
RUN apt-get update && apt-get install -y \
        unzip \
        git \
        curl \
    && docker-php-ext-install pdo pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

# === CRITICAL FIX FOR RENDER: force mod_rewrite to load + AllowOverride All ===
RUN a2enmod rewrite && \
    echo "LoadModule rewrite_module /usr/lib/apache2/modules/mod_rewrite.so" >> /etc/apache2/apache2.conf && \
    sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Set working directory
WORKDIR /var/www/html

# Composer layer (cached)
COPY composer.json composer.lock ./
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer install --no-dev --optimize-autoloader

# Copy the rest of the application
COPY . /var/www/html/

# Optional: custom vhost (if you have one)
COPY docker/000-default.conf /etc/apache2/sites-available/000-default.conf

# Permissions
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html

EXPOSE 80

CMD ["apache2-foreground"]