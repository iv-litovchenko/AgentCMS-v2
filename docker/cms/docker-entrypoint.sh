#!/bin/sh
set -e

mkdir -p /var/www/cms/var/cache /var/www/cms/var/log /var/www/cms/var/starter /var/www/cms/var/connector /var/www/cms/var/ui
chown -R www-data:www-data /var/www/cms/var

exec docker-php-entrypoint "$@"