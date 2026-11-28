# The reference site: PhpGedView 4.3.0 exactly as released, on the PHP it was written for. It is here to be
# compared against and to make the "old site" that upgrade tests start from. PHP 5.6 and PhpGedView are both
# long out of support: this is for tests only, holds made-up data only, and listens on 127.0.0.1 only.
# The build context is put together by tests/reference/up.sh.
# Both images are named by digest: php:5.6-apache, and wordpress:4.9-php5.6-apache, which is built on it and
# has the picture library (gd) compiled. This Debian release's package signing keys have expired, so nothing
# can be installed with apt; gd and the two libraries it needs are copied instead.
FROM wordpress@sha256:21cfc4fe4ffebae9b444b923db1d0d5b6c9b70752f6090b55b9601b1da946a36 AS extensions

FROM php@sha256:0a40fd273961b99d8afe69a61a68c73c04bc0caa9de384d3b2dd9e7986eec86d
COPY --from=extensions /usr/local/lib/php/extensions/no-debug-non-zts-20131226/gd.so /usr/local/lib/php/extensions/no-debug-non-zts-20131226/
COPY --from=extensions /usr/lib/x86_64-linux-gnu/libpng16.so.16* /usr/lib/x86_64-linux-gnu/libjpeg.so.62* /usr/lib/x86_64-linux-gnu/
# What a server of the time had: the database driver, pictures, and the calendar functions (without those the
# old release leaves out the Jewish calendar).
RUN docker-php-ext-install pdo_mysql calendar \
 && docker-php-ext-enable gd \
 && php -r 'foreach (array("pdo_mysql", "calendar", "gd") as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "missing $e\n"); exit(1); } } $i = imagecreatetruecolor(2, 2); ob_start(); imagepng($i); ob_end_clean();'
COPY php.ini /usr/local/etc/php/conf.d/zz-reference.ini
COPY app/ /var/www/html/
RUN chown -R www-data:www-data /var/www/html/index /var/www/html/media
