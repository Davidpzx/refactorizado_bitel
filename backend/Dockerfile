FROM php:8.3-fpm-alpine

WORKDIR /app

RUN apk add --no-cache git curl mysql-client nginx \
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

# OPcache + pool de php-fpm para producción (antes solo se usaba
# `php artisan serve`, sin ninguno de los dos). Ver docker/opcache.ini y
# docker/fpm-pool.conf.
COPY docker/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-www.conf
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

COPY . .

RUN composer install --no-dev

# Crear directorios si no existen
RUN mkdir -p /app/storage /app/bootstrap/cache

# AHORA cambiar permisos
RUN chown -R www-data:www-data /app/storage /app/bootstrap/cache

EXPOSE 8000

CMD ["/usr/local/bin/entrypoint.sh"]
