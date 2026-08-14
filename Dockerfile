FROM php:8.4-apache

# Install MySQL extension
RUN docker-php-ext-install mysqli

# Copy the PHP source code into Apache’s document root
COPY src/ /var/www/html/
