FROM composer:2@sha256:4d71c3c2109c61d5415544264b59ad4087e4c5b7244481723664138fd36d5040 AS dependencies

WORKDIR /app
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_CACHE_DIR=/tmp/composer-cache composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --ignore-platform-req=ext-gd \
    --ignore-platform-req=ext-mysqli \
    --classmap-authoritative

FROM php:8.4-apache@sha256:5f8050825b2f3de4efb0d81149c86643a9ee9c0a74ed4595ca2ad69ebfeb35fb

# Install the extensions used by the database and PDF export dependencies,
# then retain only libraries referenced by the compiled modules.
RUN dnr_saved_apt_mark="$(apt-mark showmanual)" \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev libfreetype6-dev libjpeg62-turbo-dev libonig-dev libpng-dev libwebp-dev zlib1g-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" curl gd mbstring mysqli opcache \
    && a2enmod headers proxy proxy_http deflate expires \
    && a2disconf other-vhosts-access-log \
    && sed -ri '/^[[:space:]]*CustomLog[[:space:]]/s/^/# /' /etc/apache2/sites-available/*.conf \
    && apt-mark auto '.*' >/dev/null \
    && apt-mark manual $dnr_saved_apt_mark \
    && find /usr/local/lib/php/extensions -type f -name '*.so' -exec ldd '{}' ';' \
        | awk '/=>/ { library = $(NF - 1); if (index(library, "/usr/local/") == 1) next; sub("^/(usr/)?", "", library); print library }' \
        | sort -u \
        | xargs -r dpkg-query --search \
        | cut -d: -f1 \
        | sort -u \
        | xargs -r apt-mark manual \
    && apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false \
    && rm -rf /var/lib/apt/lists/*

COPY docker/apache-security.conf /etc/apache2/conf-available/zz-dnr-security.conf
COPY docker/php-production.ini /usr/local/etc/php/conf.d/dnr-production.ini
RUN a2enconf zz-dnr-security \
    && apachectl configtest

# Keep dependencies outside Apache's document root so the development source
# bind mount cannot hide or expose them.
COPY --from=dependencies /app/vendor/ /opt/dnr/vendor/
COPY VERSION /opt/dnr/VERSION
RUN install -d -m 0755 /opt/dnr/bin
COPY --chmod=0644 scripts/create_admin.php /opt/dnr/bin/create_admin.php
COPY --chmod=0644 scripts/set_password.php /opt/dnr/bin/set_password.php
COPY --chmod=0644 scripts/cli_input.php /opt/dnr/bin/cli_input.php
COPY --chmod=0755 scripts/password_cli_entrypoint.sh /usr/local/bin/dnr-password-cli
RUN ln -s /usr/local/bin/dnr-password-cli /usr/local/bin/dnr-create-admin \
    && ln -s /usr/local/bin/dnr-password-cli /usr/local/bin/dnr-set-password
COPY --chmod=0644 scripts/migrate_passwords.php /opt/dnr/bin/migrate_passwords.php
COPY --chmod=0644 scripts/check_schema.php /opt/dnr/bin/check_schema.php
COPY --chmod=0644 scripts/check_config.php /opt/dnr/bin/check_config.php
COPY --chmod=0644 scripts/process_geocode_queue.php /opt/dnr/bin/process_geocode_queue.php
COPY --chmod=0644 scripts/process_inbound_mail.php /opt/dnr/bin/process_inbound_mail.php
COPY --chmod=0644 scripts/process_email_outbox.php /opt/dnr/bin/process_email_outbox.php
COPY --chmod=0644 scripts/restore_database.php /opt/dnr/bin/restore_database.php
COPY --chmod=0644 scripts/prune_audit_log.php /opt/dnr/bin/prune_audit_log.php
COPY --chmod=0644 scripts/seed_standard_tasks.php /opt/dnr/bin/seed_standard_tasks.php
COPY --chmod=0755 docker/mattermost-secret-entrypoint.sh /usr/local/bin/dnr-mattermost-secret-entrypoint
COPY migrations/ /opt/dnr/migrations/
RUN install -d -m 0755 /opt/dnr/config
COPY --chmod=0644 deployments/moed/application.yaml /opt/dnr/config/application.yaml

# Copy the PHP source code into Apache’s document root
COPY src/ /var/www/html/

# Add immutable source metadata last so changing only the commit does not
# invalidate the expensive dependency and PHP-extension build layers.
ARG DNR_BUILD_COMMIT=""
ARG DNR_BUILD_TIMESTAMP=""
ENV DNR_BUILD_COMMIT=${DNR_BUILD_COMMIT} \
    DNR_BUILD_TIMESTAMP=${DNR_BUILD_TIMESTAMP}
