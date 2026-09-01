# Historial de pedidos por teléfono

Endpoint para que el agente de WhatsApp (Sofía) responda `get_pedidos`: dado un número de
teléfono, devuelve **todos** los pedidos de ese cliente en una sola llamada.

## `GET /api/pedidos/por-telefono`

| | |
|---|---|
| **Auth** | `Authorization: Bearer <token Sanctum>` |
| **Parámetro** | `telefono` (también acepta `phone`) |
| **Rate limit** | bucket del agente — ver [Límites](#límites) |

```bash
curl -sS -G 'https://kohlberg.sofopolis.com/api/pedidos/por-telefono' \
  --data-urlencode 'telefono=59176616013' \
  -H "Authorization: Bearer $AGENT_TOKEN" \
  -H 'Accept: application/json'
```

### El formato del número no importa

Los tres devuelven el mismo resultado, sin importar cómo esté guardado el número en el CRM:

```bash
--data-urlencode 'telefono=59177493380'    # con código de país
--data-urlencode 'telefono=77493380'       # sin código de país
--data-urlencode 'telefono=+591 77493380'  # con "+" y espacios
```

El matching es **simétrico**: normaliza a dígitos y compara por sufijo en ambas direcciones
(`Webkul\Core\Services\PhoneNumber::same()`), así que un `59176616013` entrante encuentra a la
persona guardada como `76616013` y viceversa. Es el mismo `PersonRepository::findByPhone()` que
usa el webhook de WhatsApp, para que ambos caminos coincidan siempre en quién es el cliente.

### Respuesta `200`

```json
{
  "telefono": "+59177493380",
  "persona": {
    "id": 517,
    "nombre": "Omar - Omar Sanchez",
    "contact_numbers": [{ "label": "work", "value": "59177493380" }]
  },
  "pedidos": [
    {
      "id": 660,
      "titulo": "Omar",
      "descripcion": "Promo Varietales",
      "monto": 239,
      "estado": 1,
      "etapa": "Cliente sin interés",
      "pipeline": "Cochabamba",
      "asesor": "Gabriel Muñoz",
      "creado_en": "2026-08-15T09:27:13-04:00",
      "cerrado_en": null,
      "productos": [
        {
          "producto_id": 43,
          "nombre": "Promo Varietales",
          "cantidad": 1,
          "precio": 239,
          "subtotal": 239
        }
      ]
    }
  ],
  "total": 4
}
```

Los pedidos vienen ordenados por `creado_en` **descendente** (el más reciente primero).
`etapa` es el nombre de la etapa del pipeline (ver etapas de kohlberg: la inicial es
"No atendido"), y `pipeline` es la ciudad.

### Contratos de error

| Situación | Código | Cuerpo |
|---|---|---|
| Número desconocido | `200` | `persona: null`, `pedidos: []`, `total: 0` |
| Menos de 7 dígitos, o sin `telefono` | `400` | `{"message": "El parámetro telefono es requerido y debe tener al menos 7 dígitos."}` |
| Sin token / token inválido o vencido | `401` | `{"message": "No autenticado."}` |
| Bucket de rate limit agotado | `429` | `{"message": "Demasiadas solicitudes..."}` + header `Retry-After` |

Un número que todavía no compró **no es un error**: devuelve `200` con lista vacía. Es más
simple de manejar para el agente que distinguir un 404 de un fallo real.

## Límites

El endpoint vive bajo el grupo `api`, cuyo limiter reconoce al agente por traer un
**Bearer token de Sanctum** (la UI del CRM usa sesión, nunca token) y le da un bucket propio
por usuario.

| Variable | Default | Qué controla |
|---|---|---|
| `AGENT_API_RATE_LIMIT` | `600` | req/min para tráfico con token |
| `API_RATE_LIMIT` | `60` | req/min para el resto de `/api` |
| `AGENT_API_USERS` | *(vacío)* | vacío = todo token; o lista de ids/emails separados por coma |

Ante un `429`, respetar el header **`Retry-After`** (segundos). No reintentar antes.

## Alternativa sin este endpoint

Si hace falta resolverlo con los endpoints genéricos de `krayin/rest-api`, son **dos llamadas**
y la primera es frágil:

```bash
# 1) teléfono → person_id
curl -sS -G 'https://kohlberg.sofopolis.com/api/v1/contacts/persons/search' \
  --data-urlencode 'search=contact_numbers:76616013' \
  --data-urlencode 'searchFields=contact_numbers:like' \
  -H "Authorization: Bearer $AGENT_TOKEN" -H 'Accept: application/json'

# 2) pedidos de esa persona
curl -sS -G 'https://kohlberg.sofopolis.com/api/v1/leads' \
  --data-urlencode 'person_id=517' \
  --data-urlencode 'pagination=0' \
  -H "Authorization: Bearer $AGENT_TOKEN" -H 'Accept: application/json'
```

Dos advertencias sobre ese camino:

- El paso 1 es un **LIKE crudo** sobre el JSON de `contact_numbers`: hay que pasarle el número
  **corto** (`76616013`). Buscar `59176616013` no encuentra a quien esté guardado sin código de
  país — que es justamente el caso que parte el historial del cliente.
- `GET /api/v1/contacts/persons?search=...` **no sirve**: ese `index` convierte cada query param
  en un `whereIn` sobre una columna del mismo nombre y devuelve `500`.

## Implementación

| Archivo | Rol |
|---|---|
| `app/Http/Controllers/Api/LeadLookupController.php` | El endpoint |
| `routes/api.php` | Ruta `api.pedidos.por-telefono`, dentro del grupo `auth:sanctum` |
| `packages/Webkul/Contact/src/Repositories/PersonRepository.php` | `findByPhone()` / `findByContactNumbers()` |
| `packages/Webkul/Core/src/Services/PhoneNumber.php` | Normalización compartida |
| `tests/Feature/LeadLookupByPhoneTest.php` | Cobertura (6 tests) |
