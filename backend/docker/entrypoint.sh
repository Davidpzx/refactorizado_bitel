#!/bin/sh
set -e

# Caches de Laravel para producción. Se usa `|| true` porque un fallo aquí
# (p.ej. .env incompleto en el primer arranque) no debe tumbar el contenedor:
# Laravel funciona sin cache, solo más lento.
php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

# Arranca php-fpm y nginx, ambos en foreground pero como jobs del propio shell
# (con &). El shell base de esta imagen es BusyBox ash, que NO soporta
# `wait -n` (es una feature de bash >= 4.3), así que se vigila con un loop de
# `kill -0`: si cualquiera de los dos procesos muere, el script termina -> el
# contenedor sale con error -> Docker/Dokploy lo reinicia (restart policy).
php-fpm -F &
FPM_PID=$!
nginx -g 'daemon off;' &
NGINX_PID=$!

while kill -0 "$FPM_PID" 2>/dev/null && kill -0 "$NGINX_PID" 2>/dev/null; do
    sleep 2
done

echo "entrypoint: php-fpm o nginx terminaron, apagando el contenedor" >&2
kill -TERM "$FPM_PID" "$NGINX_PID" 2>/dev/null || true
exit 1
