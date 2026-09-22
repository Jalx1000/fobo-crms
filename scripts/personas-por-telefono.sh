#!/usr/bin/env bash
#
# Consulta la ficha de un contacto por su número de WhatsApp, con sus atributos
# personalizados (Ciudad, Sucursal).
#
#   ./scripts/personas-por-telefono.sh 76616013
#   ./scripts/personas-por-telefono.sh +591 76616013     # el formato da igual
#
# El token es el mismo API key de usuario que usa el agente. Se toma de la
# variable de entorno CRM_TOKEN para no dejarlo escrito en el repo:
#
#   export CRM_TOKEN='...'
#   export CRM_URL='https://kohlberg.sofopolis.com'   # opcional
#
set -euo pipefail

CRM_URL="${CRM_URL:-https://kohlberg.sofopolis.com}"

if [[ -z "${CRM_TOKEN:-}" ]]; then
    echo "Falta CRM_TOKEN. Generá uno en el CRM (Configuración > Usuarios > API key) y exportalo:" >&2
    echo "  export CRM_TOKEN='...'" >&2
    exit 1
fi

if [[ $# -eq 0 ]]; then
    echo "Uso: $0 <telefono>" >&2
    exit 1
fi

# Todo lo que no sea dígito se descarta acá también: así "+591 7661-6013",
# "59176616013" y "76616013" son la misma llamada y el servidor no tiene que
# adivinar qué separadores mandó la terminal.
TELEFONO="$(printf '%s' "$*" | tr -cd '0-9')"

curl -sS --get "${CRM_URL}/api/personas/por-telefono" \
    --data-urlencode "telefono=${TELEFONO}" \
    -H "Authorization: Bearer ${CRM_TOKEN}" \
    -H 'Accept: application/json' \
    -w '\n' \
| { command -v jq >/dev/null 2>&1 && jq . || cat; }
