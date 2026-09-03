FROM php:8.4-apache@sha256:5f8050825b2f3de4efb0d81149c86643a9ee9c0a74ed4595ca2ad69ebfeb35fb

# The public edge contains only Apache's reverse-proxy configuration. It does
# not contain application source, Composer dependencies, migrations, database
# clients, or application secrets.
RUN a2enmod headers proxy proxy_http deflate expires \
    && a2disconf other-vhosts-access-log \
    && rm -rf /var/www/html/*

COPY docker/apache-security.conf /etc/apache2/conf-available/zz-dnr-security.conf
COPY docker/apache-ingress.conf /etc/apache2/conf-enabled/zz-dnr-ingress.conf
COPY --chmod=0755 docker/development-ingress-entrypoint.sh /usr/local/bin/dnr-development-ingress-entrypoint

RUN a2enconf zz-dnr-security \
    && apachectl configtest
