#!/bin/bash
# =============================================================================
# entrypoint.sh — Krayin CRM / Railway (Apache + mod_php bajo supervisor)
#
# Corre como root antes de arrancar supervisor. Orden:
#   1. Preparar storage (en Railway es un volumen: arranca vacío)
#   2. Esperar a la base de datos
#   3. Migraciones
#   4. Caches de Laravel
#   5. Permisos (siempre al final: lo anterior genera archivos como root)
#   6. Arrancar supervisor (apache + queue workers + scheduler)
# =============================================================================
set -euo pipefail

log()   { echo "[entrypoint] $*"; }
error() { echo "[entrypoint][ERROR] $*" >&2; }

APP_ROOT=/var/www/html
cd "$APP_ROOT"

export PORT="${PORT:-8080}"

# Workers y scheduler apagados salvo pedido explícito: este contenedor usa los
# tokens reales de WhatsApp y dos instancias activas duplican los envíos.
export RUN_WORKERS="${RUN_WORKERS:-false}"
log "Puerto: ${PORT} | RUN_WORKERS=${RUN_WORKERS}"

# =============================================================================
# 1. Storage
# =============================================================================
for dir in \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache; do
    mkdir -p "$dir"
done

ln -sfn "$APP_ROOT/storage/app/public" public/storage

# =============================================================================
# 2. Base de datos
# =============================================================================
if [ -n "${DB_HOST:-}" ]; then
    log "Esperando la base de datos en ${DB_HOST}:${DB_PORT:-3306}..."

    # getenv() y no interpolar: una contraseña con comillas rompería el script
    tries=0
    until php -r '
        new PDO(
            sprintf("mysql:host=%s;port=%s", getenv("DB_HOST"), getenv("DB_PORT") ?: "3306"),
            getenv("DB_USERNAME"),
            getenv("DB_PASSWORD"),
            [PDO::ATTR_TIMEOUT => 3]
        );
    ' 2>/dev/null; do
        tries=$((tries + 1))
        if [ "$tries" -ge 30 ]; then
            error "Sin conexión a la base de datos después de 30 intentos."
            exit 1
        fi
        sleep 2
    done
    log "Base de datos disponible."
fi

# =============================================================================
# 3. Migraciones
# =============================================================================
log "Ejecutando migraciones..."
php artisan migrate --force --no-interaction

# =============================================================================
# 4. Caches
# =============================================================================
log "Calentando caches..."
php artisan config:cache --no-interaction
php artisan event:cache --no-interaction
php artisan route:cache --no-interaction

# Una vista rota no debe tumbar todo el CRM: se compila al primer uso y el
# error queda visible en esa pantalla.
php artisan view:cache --no-interaction || log "AVISO: view:cache falló, se sigue sin cache de vistas."

php artisan queue:restart --no-interaction

# =============================================================================
# 5. Permisos
# =============================================================================
chown -R www-data:www-data storage bootstrap/cache
find storage bootstrap/cache -type d -exec chmod 775 {} +
find storage bootstrap/cache -type f -exec chmod 664 {} +

# =============================================================================
# 6. Supervisor
# =============================================================================
log "Iniciando supervisor..."
exec /usr/bin/supervisord -n -c /etc/supervisor/conf.d/supervisord.conf
