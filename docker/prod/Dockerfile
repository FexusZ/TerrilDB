# Image de production : l'interface est compilée dans un premier étage, PHP est servi par FrankenPHP (HTTP clair sur
# 8080 : le TLS est terminé en amont par le reverse proxy de l'hôte).
FROM node:26-alpine AS front
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts
COPY tsconfig.json vite.config.ts ./
COPY assets assets
RUN npm run build

FROM dunglas/frankenphp:1-php8.5 AS app
RUN install-php-extensions pdo_pgsql pdo_mysql intl zip opcache
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV APP_ENV=prod \
    COMPOSER_ALLOW_SUPERUSER=1 \
    XDG_CONFIG_HOME=/tmp \
    XDG_DATA_HOME=/tmp
WORKDIR /app

COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress

COPY .env ./
COPY bin bin
COPY config config
COPY migrations migrations
COPY public public
COPY src src
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
    && rm -f /usr/bin/composer
COPY --from=front /app/public/app public/app

COPY docker/prod/php.ini $PHP_INI_DIR/conf.d/zz-terrildb.ini
COPY docker/prod/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/prod/entrypoint.sh /usr/local/bin/terrildb-entrypoint

RUN mkdir -p var/cache var/log /data/sessions \
    && chown -R www-data:www-data var /data/sessions \
    && chmod +x /usr/local/bin/terrildb-entrypoint

USER www-data
EXPOSE 8080
CMD ["terrildb-entrypoint"]
