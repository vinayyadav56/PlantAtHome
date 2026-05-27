FROM php:8.1-fpm-alpine

# System deps
RUN apk add --no-cache     nginx     supervisor     curl     libpng-dev     libzip-dev     icu-dev     oniguruma-dev     g++     make     autoconf

# PHP extensions
RUN docker-php-ext-configure intl  && docker-php-ext-install pdo pdo_mysql mbstring exif gd intl zip opcache bcmath

# Composer
COPY --from=composer:2.6 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy app
COPY . .

# Install PHP dependencies (no dev)
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Permissions
RUN chown -R www-data:www-data storage bootstrap/cache  && chmod -R 775 storage bootstrap/cache

# Nginx config
COPY .railway/nginx.conf /etc/nginx/nginx.conf

# Supervisor config
COPY .railway/supervisord.conf /etc/supervisord.conf

# Startup script
COPY .railway/start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 80

CMD ["/start.sh"]
