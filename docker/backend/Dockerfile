FROM dunglas/frankenphp:php8.3-alpine

RUN install-php-extensions \
    pdo_pgsql \
    intl \
    zip \
    opcache \
    apcu \
    gd

ENV SERVER_NAME=:80
ENV COMPOSER_ALLOW_SUPERUSER=1

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY backend/composer.json backend/composer.lock backend/symfony.lock ./
RUN composer install --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY backend/ .
RUN composer dump-autoload --optimize

COPY docker/backend/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
