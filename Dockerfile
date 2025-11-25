# Use PHP with Apache

FROM php:8.2-apache

# Install dependencies for Composer and PDO MySQL

RUN apt-get update && apt-get install -y unzip git curl \
&& docker-php-ext-install pdo pdo_mysql \
&& a2enmod rewrite

# Set working directory

WORKDIR /var/www/app

# Copy entire project

COPY . /var/www/app/

# Install Composer

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
&& composer install --no-dev --optimize-autoloader --working-dir=/var/www/app

# Update Apache DocumentRoot to public folder and allow .htaccess overrides

RUN sed -i 's#/var/www/html#/var/www/app/public#' /etc/apache2/sites-available/000-default.conf \
&& sed -i '/<Directory /var/www/>/,/</Directory>/c<Directory /var/www/app/public>\n    AllowOverride All\n    Require all granted\n</Directory>' /etc/apache2/sites-available/000-default.conf

# Ensure rewrite rules work via .htaccess in public folder

# Place your mod_rewrite rules inside /var/www/app/public/.htaccess

# Example .htaccess content:

# <IfModule mod_rewrite.c>

# RewriteEngine On

# RewriteCond %{REQUEST_FILENAME} !-f

# RewriteCond %{REQUEST_FILENAME} !-d

# RewriteRule ^(.*)$ index.php?/$1 [QSA,L]

# </IfModule>

# Fix permissions

RUN chown -R www-data:www-data /var/www/app \
&& chmod -R 755 /var/www/app

# Expose port 80

EXPOSE 80

# Start Apache in foreground

CMD ["apache2-foreground"]
