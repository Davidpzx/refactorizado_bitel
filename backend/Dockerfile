FROM php:8.3-fpm-alpine

WORKDIR /app

RUN apk add --no-cache git curl mysql-client \
    libpng-dev libjpeg-turbo-dev freetype-dev libzip-dev

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql bcmath gd zip

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY . .

RUN composer install --no-dev

# Crear directorios si no existen
RUN mkdir -p /app/storage /app/bootstrap/cache

# AHORA cambiar permisos
RUN chown -R www-data:www-data /app/storage /app/bootstrap/cache

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]