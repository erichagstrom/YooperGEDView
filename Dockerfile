# YooperGEDView on the official PHP 8.3 image. Development status: see docs/ROADMAP.md before exposing this anywhere.
FROM composer:2 AS libraries
WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --ignore-platform-reqs

FROM php:8.3-apache
RUN apt-get update \
 && apt-get install -y --no-install-recommends libfreetype6-dev libjpeg62-turbo-dev libpng-dev libzip-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" gd pdo_mysql zip \
 && rm -rf /var/lib/apt/lists/* \
 && a2enmod headers \
 && sed -ri 's#/var/www/html#/var/www/yooper/app#g' /etc/apache2/sites-available/000-default.conf

COPY docker/apache.conf /etc/apache2/conf-enabled/zz-yooper.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-yooper.ini
COPY docker/config.settings.inc docker/build-config.php /usr/local/share/yooper/
WORKDIR /var/www/yooper
COPY --from=libraries /build/vendor ./vendor
COPY src/ ./src/
COPY app/ ./app/
# Settings come from the environment (docker/config.settings.inc). Code is owned by root so the web server
# cannot change it; only index/ (settings, logs, exports) and media/ (uploads) are writable.
RUN php /usr/local/share/yooper/build-config.php app/config.php /usr/local/share/yooper/config.settings.inc \
 && php -l app/config.php \
 && chown -R root:root /var/www/yooper && chmod -R go-w /var/www/yooper \
 && chown -R www-data:www-data app/index app/media

# The health check asks Apache for a static file: it must not depend on a tree being loaded.
HEALTHCHECK --interval=30s --timeout=10s --retries=5 CMD curl -fsS -o /dev/null http://127.0.0.1/GPL.txt
