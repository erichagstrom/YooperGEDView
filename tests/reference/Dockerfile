# The reference site: PhpGedView 4.3.0 exactly as released, on the PHP it was written for. It is here to be
# compared against and to make the "old site" that upgrade tests start from. PHP 5.6 and PhpGedView are both
# long out of support: this is for tests only, holds made-up data only, and listens on 127.0.0.1 only.
# The build context is put together by tests/reference/up.sh.
FROM php:5.6-apache
RUN docker-php-ext-install pdo_mysql \
 && php -r 'if (!extension_loaded("pdo_mysql")) { fwrite(STDERR, "missing pdo_mysql\n"); exit(1); }'
COPY php.ini /usr/local/etc/php/conf.d/zz-reference.ini
COPY app/ /var/www/html/
RUN chown -R www-data:www-data /var/www/html/index /var/www/html/media
