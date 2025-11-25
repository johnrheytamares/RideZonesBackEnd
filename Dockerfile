FROM php:8.2-apache

# Install system packages, PHP extensions, and enable mod_rewrite

RUN apt-get update && apt-get install -y unzip git curl \
    && docker-php-ext-install pdo pdo_mysql \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Set working directory

WORKDIR /var/www/html

# Copy only composer files first for caching

COPY composer.json composer.lock ./

# Install Composer globally

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer install --no-dev --optimize-autoloader --working-dir=/var/www/html

# Copy the rest of the project files

COPY . /var/www/html/

# Copy Apache config

COPY docker/000-default.conf /etc/apache2/sites-available/000-default.conf

# Ensure Apache allows .htaccess overrides

RUN sed -i '/<Directory \/var\/www\/html>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Set proper permissions

RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html

EXPOSE 80

CMD ["apache2-foreground"]
