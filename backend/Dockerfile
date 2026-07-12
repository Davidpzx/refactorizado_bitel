FROM php:8.3-fpm-alpine

WORKDIR /app

RUN apk add --no-cache git curl mysql-client \
    libpng-dev libjpeg-turbo-dev freetype-dev libzip-dev

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql bcmath gd zip

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Subida del APK de la app de asistencia (~6-200 MB): los defaults de PHP (2M/8M)
# rechazan el multipart de /v1/app-terminal/subir. Alineado con el max del validador.
RUN { \
        echo 'upload_max_filesize=200M'; \
        echo 'post_max_size=210M'; \
    } > /usr/local/etc/php/conf.d/uploads.ini

COPY . .

RUN composer install --no-dev

# Crear directorios si no existen
RUN mkdir -p /app/storage /app/bootstrap/cache

# AHORA cambiar permisos
RUN chown -R www-data:www-data /app/storage /app/bootstrap/cache

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]