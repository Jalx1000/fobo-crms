#!/bin/bash
# =============================================================================
# entrypoint.sh - Krayin CRM / Railway
#
# Se ejecuta como root antes de arrancar supervisor.
# Orden:
#   1. Parchear nginx.conf con el $PORT que Railway asigna
#   2. Crear directorios de runtime necesarios
#   3. Esperar a que la base de datos esté disponible
#   4. Ejecutar migraciones (--force en producción)
#   5. Calentar caches de Laravel
#   6. Crear symlink de storage
#   7. Arrancar supervisor (que levanta nginx, php-fpm, worker, scheduler)
# =============================================================================

set -e

# Color para logs
log() { echo "[entrypoint] $*"; }
error() { echo "[entrypoint][ERROR] $*" >&2; }

# =============================================================================
# 1. Puerto Railway
# =============================================================================
PORT="${PORT:-8080}"
log "Usando puerto: $PORT"

# Reemplazar placeholder en nginx.conf
sed -i "s/__PORT__/${PORT}/g" /etc/nginx/nginx.conf

# =============================================================================
# 2. Directorios de runtime y permisos
# =============================================================================
log "Preparando directorios de runtime..."

# Directorios que necesita Laravel para funcionar
for dir in \
    /var/www/html/storage/app/public \
    /var/www/html/storage/framework/cache/data \
    /var/www/html/storage/framework/sessions \
    /var/www/html/storage/framework/views \
    /var/www/html/storage/logs \
    /var/www/html/bootstrap/cache \
    /tmp/nginx_client_body \
    /tmp/nginx_proxy \
    /tmp/nginx_fastcgi \
    /tmp/nginx_uwsgi \
    /tmp/nginx_scgi; do
    mkdir -p "$dir"
done

chown -R www-data:www-data \
    /var/www/html/storage \
    /var/www/html/bootstrap/cache

chmod -R 775 \
    /var/www/html/storage \
    /var/www/html/bootstrap/cache

# Directorio para el socket de php-fpm
mkdir -p /run
chown www-data:www-data /run

# =============================================================================
# 3. Esperar a que MySQL esté disponible
# =============================================================================
if [ -n "$DB_HOST" ] && [ "$DB_CONNECTION" != "sqlite" ]; then
    DB_HOST_VAL="${DB_HOST:-127.0.0.1}"
    DB_PORT_VAL="${DB_PORT:-3306}"
    MAX_TRIES=30
    TRIES=0

    log "Esperando a que la DB esté disponible en ${DB_HOST_VAL}:${DB_PORT_VAL}..."

    until php -r "
        \$conn = @new PDO(
            '${DB_CONNECTION}:host=${DB_HOST_VAL};port=${DB_PORT_VAL};dbname=${DB_DATABASE}',
            '${DB_USERNAME}',
            '${DB_PASSWORD}'
        );
        echo 'ok';
    " 2>/dev/null | grep -q 'ok'; do
        TRIES=$((TRIES + 1))
        if [ "$TRIES" -ge "$MAX_TRIES" ]; then
            error "No se pudo conectar a la DB después de ${MAX_TRIES} intentos. Abortando."
            exit 1
        fi
        log "  Intento ${TRIES}/${MAX_TRIES} - reintentando en 2s..."
        sleep 2
    done

    log "Base de datos disponible."
fi

# =============================================================================
# 4. Migraciones
# =============================================================================
log "Ejecutando migraciones..."
cd /var/www/html

# --force es necesario en APP_ENV=production
php artisan migrate --force --no-interaction || {
    error "Las migraciones fallaron."
    exit 1
}

# =============================================================================
# 5. Caches de Laravel (config, rutas, vistas, eventos)
# =============================================================================
log "Calentando caches de Laravel..."

php artisan config:cache    --no-interaction
php artisan route:cache     --no-interaction
php artisan view:cache      --no-interaction
php artisan event:cache     --no-interaction
php artisan queue:restart   --no-interaction

# =============================================================================
# 6. Storage symlink (public/storage -> storage/app/public)
# =============================================================================
log "Creando symlink de storage..."

# Solo crear si no existe ya
if [ ! -L "/var/www/html/public/storage" ]; then
    php artisan storage:link --no-interaction || true
fi

# =============================================================================
# 7. Arrancar supervisor
# =============================================================================
log "Iniciando supervisor (nginx + php-fpm + queue-worker + scheduler)..."
exec /usr/bin/supervisord -n -c /etc/supervisor/conf.d/supervisord.conf
