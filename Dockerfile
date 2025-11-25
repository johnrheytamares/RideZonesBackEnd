FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql \
    && a2enmod rewrite

WORKDIR /var/www/app

COPY . /var/www/app/

# Update Apache to point to public folder
RUN sed -i 's#/var/www/html#/var/www/app/public#' /etc/apache2/sites-available/000-default.conf \
    && sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

RUN chown -R www-data:www-data /var/www/app \
    && chmod -R 755 /var/www/app

EXPOSE 80
