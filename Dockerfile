FROM php:8.1-fpm

# Install system dependencies + nginx
RUN apt-get update && apt-get install -y \
    nginx \
    libpng-dev \
    libzip-dev \
    libicu-dev \
    g++ \
    zip \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-configure intl \
 && docker-php-ext-configure gd \
 && docker-php-ext-install \
        pdo_mysql \
        mbstring \
        exif \
        gd \
        intl \
        zip \
        bcmath \
        opcache

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install PHP dependencies (separate layer for caching)
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Copy application code
COPY . .

# Run post-install scripts (package discovery)
RUN composer run-script post-autoload-dump || true

# Permissions
RUN chown -R www-data:www-data storage bootstrap/cache \
 && chmod -R 775 storage bootstrap/cache

# Startup script
COPY docker/start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 8080

CMD ["/start.sh"]
