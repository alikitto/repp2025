# PHP 8.2 + Apache для Railway
FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql mysqli
RUN a2enmod rewrite

ENV APACHE_LISTEN_PORT=3000
RUN sed -ri 's/^Listen 80/Listen ${APACHE_LISTEN_PORT}/' /etc/apache2/ports.conf \
 && sed -ri 's/:80>/:${APACHE_LISTEN_PORT}>/' /etc/apache2/sites-available/000-default.conf

COPY . /var/www/html/
EXPOSE 3000
