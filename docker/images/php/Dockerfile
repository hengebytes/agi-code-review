FROM ghcr.io/roadrunner-server/roadrunner:2023.3 AS roadrunner

FROM php:8.3-alpine as builder

MAINTAINER Ivan Ternovtsiy <it@hengebytes.com>

ENV PHPIZE_DEPS $PHPIZE_DEPS icu-dev libxslt-dev libzip-dev \
    libpng-dev libjpeg-turbo-dev freetype-dev libwebp-dev libavif-dev imagemagick-dev
# intl - icu-dev
# xsl - libxslt-dev
# zip - libzip-dev
# gd - freetype-dev libpng-dev libjpeg-turbo-dev freetype-dev libwebp-dev libavif-dev

RUN apk add --no-cache --update linux-headers

# custom compile additional modules
RUN apk add --no-cache --virtual .phpize-deps $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-avif --with-webp \
    && docker-php-ext-install pcntl intl xsl exif pdo_mysql soap opcache zip bcmath sockets \
    && wget -O /usr/local/bin/pickle https://github.com/FriendsOfPHP/pickle/releases/latest/download/pickle.phar \
    && chmod +x /usr/local/bin/pickle \
    && pickle install -n igbinary && docker-php-ext-enable igbinary \
    && echo "--enable-redis-igbinary" > redis-options.txt \
    && pickle install --with-configure-options redis-options.txt redis && docker-php-ext-enable redis \
    && pickle install -n imagick && docker-php-ext-enable imagick \
    && rm -rf redis-options.txt \
    && rm -rf /usr/local/bin/pickle \
    && apk del -f .phpize-deps

# install shared libs required for custom modules
RUN apk add --no-cache icu libxslt libpng libjpeg-turbo freetype libwebp libavif libzip imagemagick libgomp
# intl - icu
# xsl - libxslt
# zip - libzip
# gd - freetype libpng libjpeg-turbo freetype libwebp libavif

# ----------------------------------- ABOVE - same as in final, BELOW - build specific ------------------------------- #

RUN apk add --update git openssh-client

RUN curl -sS https://getcomposer.org/installer | php \
    && mv composer.phar /usr/local/bin/composer \
    && mkdir -p -m 0600 /root/.ssh && ssh-keyscan gitlab.com >> /root/.ssh/known_hosts

WORKDIR /app

COPY backend/composer.* /app/
RUN --mount=type=ssh set -xe && \
    composer install --no-dev --no-scripts --prefer-dist --optimize-autoloader --classmap-authoritative

COPY backend/.rr.dev.yaml backend/.rr.yaml /app/

#RUN ./vendor/bin/rr get-binary --location /usr/local/bin

COPY backend/bin /app/bin
COPY backend/config /app/config
#COPY migrations /app/migrations
COPY backend/.env backend/composer.json backend/composer.lock /app/
COPY backend/public /app/public
COPY backend/src /app/src
COPY backend/templates /app/templates
COPY backend/translations /app/translations

RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

FROM php:8.3-alpine

MAINTAINER Ivan Ternovtsiy <it@hengebytes.com>

ENV PHPIZE_DEPS $PHPIZE_DEPS linux-headers icu-dev libxslt-dev libzip-dev \
    libpng-dev libjpeg-turbo-dev freetype-dev libwebp-dev libavif-dev imagemagick-dev
# intl - icu-dev
# xsl - libxslt-dev
# zip - libzip-dev
# gd - freetype-dev libpng-dev libjpeg-turbo-dev freetype-dev libwebp-dev libavif-dev

# custom compile additional modules
RUN apk add --no-cache --virtual .phpize-deps $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-avif --with-webp \
    && docker-php-ext-install pcntl intl xsl exif pdo_mysql soap opcache zip bcmath sockets \
    && wget -O /usr/local/bin/pickle https://github.com/FriendsOfPHP/pickle/releases/latest/download/pickle.phar \
    && chmod +x /usr/local/bin/pickle \
    && pickle install -n igbinary && docker-php-ext-enable igbinary \
    && echo "--enable-redis-igbinary" > redis-options.txt \
    && pickle install --with-configure-options redis-options.txt redis && docker-php-ext-enable redis \
    && pickle install -n imagick && docker-php-ext-enable imagick \
    && rm -rf redis-options.txt \
    && rm -rf /usr/local/bin/pickle \
    && apk del -f .phpize-deps

# install shared libs required for custom modules
RUN apk add --no-cache icu libxslt libpng libjpeg-turbo freetype libwebp libavif libzip imagemagick libgomp
# intl - icu
# xsl - libxslt
# zip - libzip
# gd - freetype libpng libjpeg-turbo freetype libwebp libavif

COPY docker/images/php/php.ini /usr/local/etc/php/conf.d/custom-php.ini

WORKDIR /app

# + for using this image as the cron daemon
COPY docker/images/php/crontab /etc/crontabs/www-data
# - for using this image as the cron daemon

COPY backend/templates /app/templates
COPY backend/.rr.dev.yaml backend/.rr.yaml /app/
COPY --from=builder /app/vendor /app/vendor
COPY --from=roadrunner /usr/bin/rr /usr/local/bin/rr
COPY backend/migrations /app/migrations
COPY backend/translations /app/translations
COPY backend/.env backend/composer.json backend/composer.lock /app/
COPY --from=builder /app/bin /app/bin
COPY --from=builder /app/public /app/public
COPY backend/config /app/config
COPY backend/src /app/src

RUN mkdir -p /app/var && chown -R 82:82 /app/var

USER www-data
EXPOSE 8080

CMD ["rr", "serve"]
