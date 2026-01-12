FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql mysqli \
 && a2enmod rewrite \
 && a2dismod mpm_event mpm_worker || true \
 && a2enmod mpm_prefork

# Railway обычно прокидывает PORT, но оставим твой вариант:
ENV APACHE_LISTEN_PORT=3000

RUN sed -ri "s/^Listen 80/Listen ${APACHE_LISTEN_PORT}/" /etc/apache2/ports.conf \
 && sed -ri "s/:80>/:${APACHE_LISTEN_PORT}>/" /etc/apache2/sites-available/000-default.conf

COPY . /var/www/html/
EXPOSE 3000
