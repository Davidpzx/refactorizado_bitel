# Stage 1: Build
FROM php:8.3-fpm-alpine AS builder

WORKDIR /app

# Instalar dependencias
RUN apk add --no-cache \
    git \
    curl \
    libzip-dev \
    zip \
    unzip \
    mysql-client

# Instalar extensiones PHP
RUN docker-php-ext-install \
    pdo \
    pdo_mysql \
    zip \
    bcmath

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copiar código
COPY . .

# Instalar dependencias Laravel
RUN composer install --no-dev --optimize-autoloader

# Stage 2: Runtime
FROM php:8.3-fpm-alpine

WORKDIR /app

# Instalar runtime deps
RUN apk add --no-cache \
    libzip \
    mysql-client

# Instalar extensiones PHP
RUN docker-php-ext-install \
    pdo \
    pdo_mysql \
    zip \
    bcmath

# Copiar desde builder
COPY --from=builder /app /app

# Permisos
RUN chown -R www-data:www-data /app/storage /app/bootstrap/cache

# Exponer puerto
EXPOSE 8000

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD php artisan tinker --execute="echo 'ok'" || exit 1

# Start
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]