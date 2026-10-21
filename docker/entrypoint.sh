#!/bin/bash
# =============================================================================
# entrypoint.sh - Krayin CRM / Railway (Apache + mod_php)
#
# Corre como root y deja todo lo que escribe Laravel en manos de www-data
# (si artisan corre como root, los logs/caches quedan con dueño root y
# Apache responde "Permission denied").
#   1. Puerto de Railway
#   2. Volumen persistente para storage/app
#   3. Esperar a MySQL
#   4. Migraciones + seed inicial si la BD está vacía
#   5. Caches de Laravel y storage:link
#   6. Scheduler en segundo plano y Apache en primer plano
# =============================================================================
set -e

log() { echo "[entrypoint] $*"; }
artisan() { su -s /bin/sh www-data -c "php /var/www/html/artisan $* --no-interaction"; }

cd /var/www/html

# 1. Apache lee ${PORT} desde ports.conf y el vhost
export PORT="${PORT:-80}"
log "Puerto: $PORT"

# Garantiza un solo MPM (prefork) aunque la imagen llegue con otro habilitado
rm -f /etc/apache2/mods-enabled/mpm_event.* /etc/apache2/mods-enabled/mpm_worker.*
[ -e /etc/apache2/mods-enabled/mpm_prefork.load ] || a2enmod mpm_prefork >/dev/null

# 2. Uploads (storage/app) en un volumen para que sobrevivan deploys. Dos formas:
#    - Railway: el volumen se monta en otra ruta ($RAILWAY_VOLUME_MOUNT_PATH, o
#      $STORAGE_VOLUME_PATH en otra plataforma) y storage/app pasa a ser un enlace.
#    - EasyPanel / docker: el volumen se monta directo en storage/app.
#    En los dos, el volumen llega con dueño root y Laravel escribe como www-data:
#    sin el chown de abajo, subir un archivo da "Unable to create a directory".
VOLUMEN="${STORAGE_VOLUME_PATH:-$RAILWAY_VOLUME_MOUNT_PATH}"
if [ -n "$VOLUMEN" ] && [ -d "$VOLUMEN" ]; then
    if [ ! -d "$VOLUMEN/app" ]; then
        log "Inicializando storage/app en el volumen $VOLUMEN"
        cp -a storage/app "$VOLUMEN/app"
    fi
    rm -rf storage/app
    ln -s "$VOLUMEN/app" storage/app
fi

mkdir -p storage/app/public storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache
# storage/app/ con barra: si es un enlace al volumen, el chown entra al volumen.
chown -R www-data:www-data storage/app/ storage/framework storage/logs bootstrap/cache

# 3. Esperar a MySQL
if [ -n "$DB_HOST" ]; then
    log "Esperando a MySQL en ${DB_HOST}:${DB_PORT:-3306}..."
    for i in $(seq 1 30); do
        if php -r 'try { new PDO("mysql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT") ?: 3306), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); exit(0); } catch (Throwable $e) { exit(1); }'; then
            log "MySQL disponible."
            break
        fi
        [ "$i" -eq 30 ] && { log "MySQL no responde, abortando."; exit 1; }
        sleep 2
    done
fi

# 4. Migraciones y seed (solo la primera vez, con la tabla de usuarios vacía)
artisan migrate --force

USERS=$(php -r 'try { $p = new PDO("mysql:host=".getenv("DB_HOST").";port=".(getenv("DB_PORT") ?: 3306).";dbname=".getenv("DB_DATABASE"), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); echo $p->query("SELECT COUNT(*) FROM ".getenv("DB_PREFIX")."users")->fetchColumn(); } catch (Throwable $e) { echo "err"; }')
if [ "$USERS" = "0" ]; then
    log "BD sin usuarios: ejecutando db:seed"
    artisan db:seed --force
fi

# Krayin (CanInstall) da la app por no instalada si no hay .env y manda todo
# al instalador web; acá las variables vienen de Railway y la BD ya está lista.
touch storage/installed
chown www-data:www-data storage/installed

# 5. Caches y symlink público
artisan optimize:clear
artisan config:cache
artisan view:cache
# public/ es de root: el symlink lo crea root (equivale a storage:link)
ln -sfn /var/www/html/storage/app/public public/storage

# 6. Scheduler (inbound-emails:process cada 5 min), worker de colas y Apache
su -s /bin/sh www-data -c 'while true; do php /var/www/html/artisan schedule:run --no-interaction 2>&1 | grep -v "No scheduled commands are ready to run"; sleep 60; done' &

# Con QUEUE_CONNECTION=sync no hay nada que consumir. WhatsApp encola en su propia
# cola ("whatsapp"); timeout < retry_after (90 s) para que un job no se duplique.
if [ "${QUEUE_CONNECTION:-sync}" != "sync" ]; then
    log "Worker de colas: ${QUEUE_CONNECTION} (${WHATSAPP_QUEUE:-whatsapp},default)"
    su -s /bin/sh www-data -c "while true; do php /var/www/html/artisan queue:work ${QUEUE_CONNECTION} --queue=${WHATSAPP_QUEUE:-whatsapp},default --tries=3 --backoff=10 --timeout=80 --sleep=1 --max-time=3600 2>&1; sleep 2; done" &
fi

log "Iniciando: $*"
exec "$@"
