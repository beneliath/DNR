FROM composer:2 AS dependencies

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --ignore-platform-req=ext-gd \
    --classmap-authoritative

FROM php:8.4-apache

# Install the extensions used by the database and PDF export dependencies.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpng-dev zlib1g-dev \
    && docker-php-ext-install gd mysqli \
    && rm -rf /var/lib/apt/lists/*

# Keep dependencies outside Apache's document root so the development source
# bind mount cannot hide or expose them.
COPY --from=dependencies /app/vendor/ /opt/dnr/vendor/
COPY scripts/create_admin.php /opt/dnr/bin/create_admin.php
COPY scripts/set_password.php /opt/dnr/bin/set_password.php

# Copy the PHP source code into Apache’s document root
COPY src/ /var/www/html/
