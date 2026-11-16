FROM php:8.2-apache

RUN apt-get update && apt-get install -y libpq-dev zip unzip \
    && docker-php-ext-install pdo pdo_pgsql \
    && a2enmod rewrite

RUN echo '<Directory /var/www/html>\nAllowOverride All\nRequire all granted\n</Directory>' > /etc/apache2/conf-available/smartware.conf \
    && a2enconf smartware

# Cho phép upload PDF hóa đơn tới 20MB (mặc định PHP chỉ 2MB) và đủ thời gian chờ OCR
RUN { echo 'upload_max_filesize=20M'; echo 'post_max_size=25M'; echo 'max_execution_time=180'; } \
    > /usr/local/etc/php/conf.d/smartware-uploads.ini

EXPOSE 80
