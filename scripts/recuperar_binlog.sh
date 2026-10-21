#!/usr/bin/env bash
#
# Lee el binlog de MySQL hasta justo antes de la importación del dump del 14/10/2026
# y guarda las filas perdidas (contactos, leads, conversaciones, mensajes…) en un JSON.
#
# SOLO LECTURA: se conecta a la base como réplica. No modifica ningún dato.
# Requiere que /tmp/binlog (script + librerías) ya esté copiado dentro del
# contenedor de Laravel.
#
# Uso: bash scripts/recuperar_binlog.sh
set -euo pipefail

DB_CONTAINER="heaven_ponce_de_leon.1.zdvzsdqse3fqaeoz43ce96vn8"
APP_CONTAINER="$(docker ps --format '{{.Names}}' | grep ponce_de_leon_laravel | head -1)"

if [ -z "$APP_CONTAINER" ]; then
    echo "No encontré el contenedor de Laravel." >&2
    exit 1
fi

if ! docker exec "$APP_CONTAINER" test -f /tmp/binlog/leer_binlog.py; then
    echo "Falta /tmp/binlog/leer_binlog.py dentro de $APP_CONTAINER (¿se reinició el contenedor?)." >&2
    exit 1
fi

PW="$(docker exec "$DB_CONTAINER" printenv MYSQL_ROOT_PASSWORD)"

echo "Leyendo binlog.000093 desde $APP_CONTAINER…"

docker exec -e PW="$PW" "$APP_CONTAINER" sh -c '
    cd /tmp/binlog &&
    PYTHONPATH=/tmp/binlog/libs timeout 600 python3 leer_binlog.py heaven_ponce_de_leon "$PW" /tmp/binlog/recuperado.json 2>&1 \
        | grep -v -i "warn\|deprecat" | tail -25
'

# Copia del resultado fuera del contenedor, junto al respaldo del binlog.
mkdir -p ~/respaldo-binlog
docker cp "$APP_CONTAINER":/tmp/binlog/recuperado.json ~/respaldo-binlog/recuperado.json
echo "Resultado copiado a ~/respaldo-binlog/recuperado.json"
