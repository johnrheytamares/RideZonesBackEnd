FROM php:8.2-apache

# Install PDO MySQL
RUN docker-php-ext-install pdo pdo_mysql

# Enable mod_rewrite
RUN a2enmod rewrite

# Set working directory (non-public root)
WORKDIR /var/www/app

# Copy entire project
COPY . /var/www/app/

# Apache DocumentRoot update
RUN sed -i 's#/var/www/html#/var/www/app/public#' /etc/apache2/sites-available/000-default.conf

# Allow .htaccess
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Fix permissions
RUN chown -R www-data:www-data /var/www/app \
    && chmod -R 755 /var/www/app

EXPOSE 80
