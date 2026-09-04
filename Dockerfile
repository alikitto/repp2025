FROM php:8.2-apache

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && docker-php-ext-install pdo pdo_mysql mysqli \
 && a2enmod rewrite

ENV APACHE_LISTEN_PORT=3000
RUN sed -ri "s/^Listen 80/Listen ${APACHE_LISTEN_PORT}/" /etc/apache2/ports.conf \
 && sed -ri "s/:80>/:${APACHE_LISTEN_PORT}>/" /etc/apache2/sites-available/000-default.conf

COPY . /var/www/html/
COPY deploy/apache-deny.conf /etc/apache2/conf-available/tutor-deny.conf
RUN a2enconf tutor-deny

# data/ исключён из образа через .dockerignore; нужен www-data на запись
# для снимков pre-restore и (при генерации из CLI) для data/app_secret
RUN mkdir -p /var/www/html/data \
 && chown www-data:www-data /var/www/html/data \
 && chmod 700 /var/www/html/data

# custom entrypoint to fix Railway MPM conflict
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh
CMD ["/usr/local/bin/docker-entrypoint.sh"]

EXPOSE 3000
