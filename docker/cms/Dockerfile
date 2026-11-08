FROM composer:2 AS vendor
WORKDIR /app
COPY packages /packages
COPY apps/cms/ .
COPY shared/php /shared/php
RUN composer install --no-interaction --prefer-dist --no-dev --no-scripts \
    && composer dump-autoload --optimize --classmap-authoritative

FROM node:22-alpine AS assets
WORKDIR /work
COPY apps/cms ./apps/cms
COPY packages ./packages
WORKDIR /work/apps/cms
RUN npm ci && npm run build

FROM php:8.2-fpm-alpine
RUN apk add --no-cache icu-dev postgresql-dev git bash openssh-client curl \
    && docker-php-ext-install intl opcache pdo_pgsql
RUN apk add --no-cache $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

WORKDIR /var/www/cms
COPY --from=vendor /app/vendor ./vendor
COPY apps/cms/ .
COPY --from=assets /work/apps/cms/public/build ./public/build
COPY shared/php /var/www/shared/php
RUN mkdir -p var/cache var/log var/starter var/connector var/ui && chown -R www-data:www-data var \
    && echo "error_log=/var/www/cms/var/log/php.log" > /usr/local/etc/php/conf.d/cms-error-log.ini

USER www-data
EXPOSE 9000
CMD ["php-fpm"]
