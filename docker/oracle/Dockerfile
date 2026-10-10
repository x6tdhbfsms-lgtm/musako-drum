# syntax=docker/dockerfile:1
FROM php:8.5-fpm-bookworm@sha256:dd198c828c224e479bdaa184cdcf04a28c73d186bf5a885493bf9cc78d7196ef AS php-base
RUN apt-get update && apt-get install -y --no-install-recommends \
    libicu-dev libzip-dev libonig-dev libxml2-dev libfcgi-bin unzip curl procps \
    && docker-php-ext-install -j2 pdo_mysql mbstring intl zip pcntl bcmath \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html

FROM composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS composer-bin
FROM node:24-bookworm-slim@sha256:d6aa754f16b3197301076f047b5def2f02ea1dbbc2ca920407d46d7ec7f87b20 AS node-bin

FROM php-base AS build
COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer
COPY --from=node-bin /usr/local/bin/node /usr/local/bin/node
COPY --from=node-bin /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && npm install --global npm@11.6.2
COPY . .
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader \
    && npm ci && npm run build \
    && composer check-platform-reqs --no-dev

FROM php-base AS app
COPY --from=build --chown=www-data:www-data /var/www/html /var/www/html
RUN rm -rf node_modules tests .agents .github docs docker \
    && mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && ln -s /var/www/html/storage/app/public public/storage \
    && chown -R www-data:www-data storage bootstrap/cache
COPY docker/oracle/php.ini /usr/local/etc/php/conf.d/zz-musako.ini
COPY docker/oracle/fpm.conf /usr/local/etc/php-fpm.d/zz-musako.conf
COPY --chmod=755 docker/oracle/entrypoint.sh /usr/local/bin/musako-entrypoint
USER www-data
ENTRYPOINT ["musako-entrypoint"]
CMD ["php-fpm", "-F"]

FROM caddy:2-alpine@sha256:d8542f48d34a9cf4e4c11a478865229840e87e4c96ea3f439101f31a5d35f75f AS web
COPY --from=app /var/www/html/public /var/www/html/public
COPY docker/oracle/Caddyfile /etc/caddy/Caddyfile
