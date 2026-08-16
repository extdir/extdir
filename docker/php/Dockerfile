# Local development image only. Production runs on Uberspace shared hosting,
# which provides its own PHP-FPM — see docs/brief.md §5. Nothing here is deployed.
FROM php:8.3-fpm-alpine

# Battle-tested extension installer rather than hand-rolled docker-php-ext-install
# invocations; it resolves the alpine build deps for us.
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions \
        intl \
        pdo_mysql \
        zip \
        opcache \
        apcu \
        sockets \
        pcntl \
    && apk add --no-cache git bash icu-data-full

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Match the host UID so files written inside the container stay editable outside it.
ARG UID=1000
RUN adduser -D -u ${UID} -s /bin/bash app

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-extdir.ini

WORKDIR /app
USER app
