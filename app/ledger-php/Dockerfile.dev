FROM php:8.3-fpm

# Install system dependencies and required PHP extensions (pdo_mysql and bcmath)
RUN apt-get update && apt-get install -y \
    libzip-dev \
    zip \
    unzip \
    && docker-php-ext-install pdo_mysql bcmath

WORKDIR /var/www/html
