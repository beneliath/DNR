FROM php:8.5.12-apache@sha256:e25ab14ef2cfec3bab9dd66c6cf871691146f428fbd72241b1a2c38fcacf92f5

# The public edge contains the reverse proxy and a read-only deployment notice.
# It has no business routes, Composer dependencies, migrations, database clients,
# or application secrets.
RUN apt-get update \
    && apt-get upgrade -y \
    && apt-mark auto $PHPIZE_DEPS \
    && apt-get autoremove -y --purge \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod headers proxy proxy_http deflate expires rewrite \
    && a2disconf other-vhosts-access-log \
    && rm -rf /var/www/html/*

COPY docker/apache-security.conf /etc/apache2/conf-available/zz-dnr-security.conf
COPY docker/apache-ingress.conf /etc/apache2/conf-enabled/zz-dnr-ingress.conf
COPY --chmod=0755 docker/development-ingress-entrypoint.sh /usr/local/bin/dnr-development-ingress-entrypoint
COPY src/deployment_status.php src/deployment_notice_helpers.php src/maintenance.php src/maintenance_guard.php /var/www/html/
COPY src/assets/js/maintenance-timer.js /var/www/html/assets/js/maintenance-timer.js
# Account ingress serves its qualified static scripts itself. A compromised PHP
# container must not supply executable code under the shared browser origin.
COPY src/assets/ /opt/dnr/account-assets/

RUN a2enconf zz-dnr-security \
    && apachectl configtest
