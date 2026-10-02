FROM php:8.2-apache

RUN apt-get update && apt-get install -y libpq-dev zip unzip \
    && docker-php-ext-install pdo pdo_pgsql \
    && a2enmod rewrite

RUN echo '<Directory /var/www/html>\nAllowOverride All\nRequire all granted\n</Directory>' > /etc/apache2/conf-available/smartware.conf \
    && a2enconf smartware

EXPOSE 80
