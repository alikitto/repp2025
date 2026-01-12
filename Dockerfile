FROM php:8.2-apache

RUN docker-php-ext-install pdo pdo_mysql mysqli \
 && a2enmod rewrite \
 && rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.* \
 && a2enmod mpm_prefork \
 && echo "MPM enabled links:" \
 && ls -la /etc/apache2/mods-enabled | grep mpm || true

ENV APACHE_LISTEN_PORT=3000
RUN sed -ri "s/^Listen 80/Listen ${APACHE_LISTEN_PORT}/" /etc/apache2/ports.conf \
 && sed -ri "s/:80>/:${APACHE_LISTEN_PORT}>/" /etc/apache2/sites-available/000-default.conf

COPY . /var/www/html/
EXPOSE 3000
