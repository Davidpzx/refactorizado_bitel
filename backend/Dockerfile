FROM php:8.3-fpm-alpine

WORKDIR /app

# Instalar dependencias
RUN apk add --no-cache \
    git \
    curl \
    libzip \
    mysql-client

# Instalar extensiones PHP (sin zip)
RUN docker-php-ext-install \
    pdo \
    pdo_mysql \
    bcmath

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copiar código
COPY . .

# Instalar dependencias Laravel
RUN composer install --no-dev --optimize-autoloader

# Permisos
RUN chown -R www-data:www-data /app/storage /app/bootstrap/cache

# Exponer puerto
EXPOSE 8000

# Start
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]