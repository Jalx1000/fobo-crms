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
#   6. Dejar un solo MPM en Apache
#   7. Arrancar supervisor (apache + queue workers + scheduler)
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

# Sin este marcador Krayin se considera no instalado y redirige todo a /install
# con la base real conectada: el respaldo que consulta la base exige un .env, y
# acá la configuración llega por variables de entorno. El volumen arranca vacío.
touch storage/installed

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

# route:cache falla hoy porque mail-routes.php se registra dos veces y Laravel
# rechaza el nombre duplicado. Sin cache las rutas funcionan igual, solo se
# resuelven en cada request (es como corre Easypanel).
php artisan route:cache --no-interaction || log "AVISO: route:cache falló, se sigue sin cache de rutas."

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
# 6. Apache: un solo MPM
#
# En la imagen construida solo está mpm_prefork, pero en Railway Apache arrancaba
# con AH00534 (More than one MPM loaded). Se corrige acá porque el problema
# aparece en runtime; mod_php exige prefork.
# =============================================================================
log "MPM encontrados: $(ls /etc/apache2/mods-enabled | grep -E '^mpm_.*\.load$' | tr '\n' ' ')"
rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.*
apache2ctl -t

# =============================================================================
# 7. Supervisor
# =============================================================================
log "Iniciando supervisor..."
exec /usr/bin/supervisord -n -c /etc/supervisor/conf.d/supervisord.conf
