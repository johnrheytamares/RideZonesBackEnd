FROM php:8.2-apache

RUN apt-get update && apt-get install -y unzip git curl \
    && docker-php-ext-install pdo pdo_mysql \
    && a2enmod rewrite

WORKDIR /var/www/html

# COPY PROJECT FILES
COPY . /var/www/html/

# DEBUG: show files after COPY
RUN echo "===== DEBUG: Listing project files in /var/www/html =====" \
    && ls -la /var/www/html \
    && echo "========================================================="

# Copy Apache config
COPY docker/000-default.conf /etc/apache2/sites-available/000-default.conf

# DEBUG: show Apache config exists
RUN echo "===== DEBUG: Apache config loaded =====" \
    && cat /etc/apache2/sites-available/000-default.conf \
    && echo "======================================="

# Install Composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer install --no-dev --optimize-autoloader --working-dir=/var/www/html

# DEBUG: show vendor folder
RUN echo "===== DEBUG: Vendor folder =====" \
    && ls -la /var/www/html/vendor \
    && echo "================================"

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

EXPOSE 80

CMD ["apache2-foreground"]
