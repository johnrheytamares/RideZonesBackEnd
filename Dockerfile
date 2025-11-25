# Use PHP with Apache

FROM php:8.2-apache

# Install dependencies for Composer and PDO MySQL

RUN apt-get update && apt-get install -y unzip git curl 
&& docker-php-ext-install pdo pdo_mysql 
&& a2enmod rewrite

# Set working directory

WORKDIR /var/www/app

# Copy entire project

COPY . /var/www/app/

# Copy custom Apache config (ensure this file exists in your project root)

COPY docker/000-default.conf /etc/apache2/sites-available/000-default.conf

# Install Composer

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
	&& composer install --no-dev --optimize-autoloader --working-dir=/var/www/app

# Fix permissions

RUN chown -R www-data:www-data /var/www/app \
	&& chmod -R 755 /var/www/app

# Expose port 80

EXPOSE 80

# Start Apache in foreground

CMD ["apache2-foreground"]
