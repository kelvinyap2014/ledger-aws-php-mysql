# ==========================================
# Stage 1: Build Dependencies via Composer
# ==========================================
FROM composer:2 AS vendor-builder

WORKDIR /app

# Copy dependency definition files first to leverage Docker caching layers
COPY composer.json composer.lock ./

# Install production dependencies without dev packages or scripts
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --ansi

# Copy the rest of the application source code
COPY . .

# Generate optimized production autoloader and run compilation scripts
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# ==========================================
# Stage 2: Production Runtime Image (Nginx + PHP-FPM)
# ==========================================
FROM php:8.3-fpm-alpine

WORKDIR /var/www/html

# Install Nginx, system dependencies, and PHP extensions for MySQL & Symfony
RUN apk add --no-cache \
    nginx \
    libzip-dev \
    zip \
    unzip \
    icu-dev \
    oniguruma-dev \
    && docker-php-ext-install \
        pdo_mysql \
        bcmath \
        intl \
        opcache

# Copy application source code and pre-built vendor packages from Stage 1
COPY --from=vendor-builder /app /var/www/html

# Copy custom Nginx virtual host configuration
COPY docker/nginx.conf /etc/nginx/http.d/default.conf

# Copy and prepare the container entrypoint script
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Create Symfony runtime directories with full structure (prod cache needs subdirs)
# Set APP_ENV early so Symfony knows which cache dirs to create
ENV APP_ENV=prod

# Pre-create all cache and log directories with 777 permissions to avoid runtime permission issues
RUN rm -rf var/cache var/log && \
    mkdir -p var/cache/prod var/cache/dev var/log var/tmp && \
    chown -R www-data:www-data var/ && \
    chmod -R 777 var/

# Expose HTTP port 80 for the AWS Application Load Balancer
EXPOSE 80

# Use entrypoint to run migrations and boot services
ENTRYPOINT ["entrypoint.sh"]
CMD ["nginx", "-g", "daemon off;"]
