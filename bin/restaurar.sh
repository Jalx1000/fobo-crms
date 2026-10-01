#!/bin/bash
# Aplica database/sql/imprimir/90-restaurar-borrado-14-09.sql contra la base de producción
# (una transacción: si algo choca, rollback). Uso: bash bin/restaurar.sh
set -e
cd "$(dirname "$0")/.."
M=$(docker ps -q -f name=heaven_imprimir.1)
PW=$(docker exec "$M" sh -c 'printf %s "$MYSQL_ROOT_PASSWORD"')
docker cp database/sql/imprimir/90-restaurar-borrado-14-09.sql "$M":/tmp/restaurar.sql
docker exec "$M" sh -c "mysql -uroot -p'$PW' --default-character-set=utf8mb4 kohlberg < /tmp/restaurar.sql" 2>&1 | grep -v 'Using a password'
