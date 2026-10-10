# Spec: API mobile de WhatsApp (token + Swagger)

**Estado:** aprobada con respuestas · **Fecha:** 2026-10-10

## Objetivo

Que un asesor pueda **atender WhatsApp desde la app mobile** con lo mismo que
hoy hace en la bandeja del CRM web: ver sus conversaciones, leer el hilo,
responder (texto, media, ubicación, plantilla), tomar y soltar derivaciones,
apagar la IA, dejar notas y abrir un pedido desde el chat.

Hoy no se puede. La bandeja (`InboxController`, `ChatController`,
`QuickReplyController`) vive bajo `web + user`: sesión y CSRF. Los únicos
endpoints con token (`AgentController`) son del **bot de IA**, con otro
contrato y otro actor.

**Para quién:** los asesores (rol Encargado) y administradores, desde la app.
**Para quién también:** el equipo mobile, que consume el contrato desde Swagger
(`/api/documentation`) sin leer PHP.

## Supuestos (corregir antes de implementar)

1. La app se autentica con el login que ya existe: `POST /api/v1/login`
   (email, password, `device_name`) → token Sanctum. No se crea otro login.
2. Un token = un usuario real del CRM. El **actor** de cada acción (quién
   respondió, quién tomó la derivación, quién dejó la nota) es ese usuario,
   no el bot. Esto es distinto de la ruta del agente.
3. El acceso se gobierna con el **mismo permiso ACL `whatsapp`** que la web.
   Sin permiso → 403 JSON, igual que hoy.
4. La app ve **las conversaciones que las limitaciones del usuario le
   permiten** (decidido 2026-10-10, ver "Visibilidad"). Misma deduplicación
   por últimos 8 dígitos y mismos filtros que la web.
5. Tiempo real por **polling** con el cursor `since` que ya usa la bandeja.
   Push (FCM) queda fuera de esta entrega.
6. Las respuestas JSON son **las mismas que ya devuelve la bandeja**. Se
   documentan tal cual; no se rediseñan.

## Decisión de arquitectura

El módulo usa `auth()->guard('user')` en 15 lugares (bouncer, "asignadas a mí",
autor del mensaje, actor del handoff). Con un token Sanctum ese guard está
vacío: `bouncer()->hasPermission()` devuelve `false` y todo daría 403.

**Elegido:** un middleware `AuthenticateMobileUser` que, después de
`auth:sanctum`, pone al usuario del token en el guard `user` **sólo para esa
request** (`Auth::guard('user')->setUser($user)`, sin sesión ni cookie). Las
rutas mobile apuntan a controladores delgados en `Http/Controllers/Api/Mobile/`
que traducen `{id}` de la URL a lo que espera la bandeja y delegan.

**Descartado:** reescribir la lógica en controladores API propios. Duplica el
SQL de la bandeja (dedupe, ciudad, paginación) y los dos contratos se separan
con el primer cambio.

```php
// Http/Middleware/AuthenticateMobileUser.php — la idea, no la versión final
public function handle(Request $request, Closure $next)
{
    // auth:sanctum ya validó el token; el resto del módulo lee el guard "user".
    Auth::guard('user')->setUser($request->user('sanctum'));

    return $next($request);
}
```

## Visibilidad (decidido 2026-10-10)

"Según las limitaciones que tengas como usuario". Krayin ya tiene esa
limitación: `users.view_permission` (`global` | `group` | `individual`),
resuelta por `bouncer()->getAuthorizedUserIds()` y aplicada hoy a los leads
(`LeadDataGrid`, `LeadController`). La bandeja de WhatsApp **no la aplica**:
un asesor `individual` ve en WhatsApp conversaciones de leads que en el
listado de leads no puede ver.

Regla, con `$ids = bouncer()->getAuthorizedUserIds()`:

| `$ids` | Ve |
|---|---|
| `null` (global) | Todas, como hoy |
| lista (group / individual) | Las que cumplan **alguna**: (a) `assigned_user_id ∈ $ids`; (b) tiene un lead (por `lead_id` o por `person_id`) con `leads.user_id ∈ $ids`; (c) está en la bolsa: `handoff_state = requested` y sin asignado, para poder tomarla |

(b) cuenta leads abiertos **y cerrados**, igual que `LeadDataGrid` (decidido
2026-10-10): el asesor ve en WhatsApp los mismos clientes que en su listado.
Sin usuario autenticado el scope no devuelve nada.

Lo que no entra en (a)–(c) —p. ej. un contacto nuevo sin lead ni derivación—
no lo ve un usuario restringido. Es la misma postura que con los leads: sin
dueño autorizado, no se muestra.

- La regla vive en **un solo scope** (`Conversation::scopeVisibleTo($ids)`) y
  se aplica al listado, al contador y a **todo endpoint que reciba `{id}`**.
  Pedir por id una conversación no visible → **404** (no 403: no confirmar
  que existe).
- Como la app delega en los mismos controladores, **la web también pasa a
  respetarla**. Para usuarios `global` no cambia nada; para los restringidos
  cierra el hueco de arriba. Es intencional: la limitación es del usuario,
  no del dispositivo.

## Endpoints

Prefijo `api/v1/mobile/whatsapp` · middleware
`api`, `throttle:mobile-api`, `auth:sanctum`, `whatsapp.mobile-user`, `whatsapp.access`.

| # | Método | Ruta | Delega en | Para qué |
|---|---|---|---|---|
| — | POST | `/api/v1/login` | rest-api (existente) | Obtener token. Sólo se documenta |
| — | DELETE | `/api/v1/logout` | rest-api (existente) | Revocar token. Sólo se documenta |
| 1 | GET | `/me` | nuevo | Usuario, rol, `can_whatsapp` |
| 2 | GET | `/conversations` | `ChatController@conversations` | Listado paginado + filtros |
| 3 | GET | `/conversations/{id}/messages?since=` | `InboxController@thread` | Hilo / incremental; marca leído |
| 4 | POST | `/conversations/{id}/messages` | `InboxController@send` | Texto (reply opcional) |
| 5 | POST | `/conversations/{id}/media` | `InboxController@sendMedia` | Multipart: imagen, audio, video, doc |
| 6 | POST | `/conversations/{id}/location` | `InboxController@sendLocation` | Ubicación |
| 7 | GET | `/templates` | `InboxController@templates` | Plantillas aprobadas |
| 8 | POST | `/conversations/{id}/template` | `InboxController@sendTemplate` | Fuera de la ventana de 24 h |
| 9 | PATCH | `/conversations/{id}/agent` | `InboxController@toggleAgent` | Encender/apagar IA |
| 10 | POST | `/conversations/{id}/handoff/claim` | `InboxController@claimHandoff` | Tomar la conversación |
| 11 | POST | `/conversations/{id}/handoff/resolve` | `InboxController@resolveHandoff` | Soltarla |
| 12 | POST | `/conversations/{id}/notes` | `InboxController@storeNote` | Nota interna |
| 13 | POST | `/conversations/{id}/link-person` | `ChatController@linkPerson` | Vincular contacto |
| 14 | GET | `/persons/{id}` | `ChatController@person` | Ficha + leads |
| 15 | POST | `/persons/{id}/leads` | `ChatController@storeLead` | Abrir pedido |
| 16 | GET | `/stages` · `/cities` · `/products` | `ChatController@*` | Catálogos para selectores |
| 17 | GET | `/quick-replies` | `QuickReplyController@index` | Sólo lectura |
| 18 | GET | `/unread-count` | nuevo | Badge de la app: `{conversations, messages, pool, mine}`, con la visibilidad aplicada |

## Fuera de alcance, explícitamente

- **Webhook y `whatsapp/media/{file}`:** públicos, los consume el proveedor.
- **Rutas del agente IA** (`/api/v1/whatsapp/conversations/...`): no se tocan;
  siguen en su tag de Swagger.
- **CRUD de respuestas rápidas:** administración, queda en la web.
- **`handoff` (pedir derivación):** es la acción del bot; la persona usa claim/resolve.
- **Push notifications (FCM):** fase aparte.
- **Cambiar el formato de las respuestas JSON.**

## Rate limiting

`config/agent_api.php` asume que *"sólo las máquinas tienen token"*. Con la app
eso deja de ser cierto: sin cambios, cada asesor caería en el bucket generoso
del bot (600/min) o en el de IP (60/min) según `AGENT_API_USERS`.

Se agrega un limiter **`mobile-api`** propio, por usuario
(`MOBILE_API_RATE_LIMIT`, default 120/min: polling cada 3 s ≈ 20/min + acciones).
Confirmado 2026-10-10.

## Token

**No se toca la duración**: ya está definida (`config/sanctum.php`). La app
usa el token que entrega el login existente, sin cambios en su emisión ni su
vencimiento.

## Swagger

- Anotaciones `@OA` en `packages/Webkul/Whatsapp/src/Http/Controllers/Api/Mobile/`
  (el directorio padre `Api/` ya está en `l5-swagger.annotations`).
- Tag **"WhatsApp Mobile"**, `security={{"bearerAuth":{}}}` (esquema ya declarado).
- Schemas: `MobileConversation`, `MobileMessage`, `MobileTemplate`,
  `ValidationError`, `Forbidden`.
- Cada endpoint documenta 200/201, 401 (sin token), 403 (sin permiso),
  404 (conversación inexistente) y 422 cuando valida input.

## Comandos

```bash
php artisan test tests/Feature/WhatsappMobileApiTest.php
./vendor/bin/pint packages/Webkul/Whatsapp/src/Http/Controllers/Api/Mobile/<archivo>.php   # nunca sobre directorios
php artisan l5-swagger:generate
php artisan route:list --path=api/v1/mobile
```

## Estructura

```
packages/Webkul/Whatsapp/src/
  Http/Controllers/Api/Mobile/   → controladores delgados + @OA
  Http/Middleware/AuthenticateMobileUser.php
  Routes/api.php                 → grupo mobile
app/Providers/RouteServiceProvider.php → limiter mobile-api
config/agent_api.php (o mobile_api.php) → límite configurable
tests/Feature/WhatsappMobileApiTest.php
```

## Testing

Pest, en `tests/Feature/`, contra la DB real (sin `RefreshDatabase`, como el
resto de la suite: `DatabaseTransactions` o begin/rollBack). La suite está en
verde, así que un fallo nuevo es de esta entrega.

Por endpoint como mínimo: **401** sin token, **403** con token de un rol sin
`whatsapp`, **feliz** con token válido. Además:

- El actor de un `claim`/`send`/`note` es el usuario del token.
- Con un token, el guard `user` no persiste entre requests (no queda sesión).
- Los endpoints del agente IA siguen pasando `WhatsappContratoAgenteTest`.
- Visibilidad: un usuario `individual` no ve en el listado ni en el contador
  una conversación de un lead ajeno, y recibe 404 al pedirla por id (hilo,
  envío, claim, nota); sí ve la de su lead, la asignada a él y la de la bolsa.
  Un usuario `global` ve todo.
- El envío al proveedor se falsea (gateway fake), nunca sale un WhatsApp real.

## Boundaries

- **Siempre:** test por endpoint antes de pasar al siguiente; Pint archivo por
  archivo; mensajes de error en español rioplatense como el resto del módulo.
- **Preguntar antes:** migraciones; cambiar respuestas que también usa la
  bandeja web; tocar el limiter `agent-api`; agregar dependencias.
- **Nunca:** editar `vendor/krayin/rest-api`; enviar mensajes reales en tests;
  abrir rutas sin `auth:sanctum`; exponer el token en logs.

## Criterios de éxito

1. Con un token de un Encargado, la app puede listar, leer, responder,
   tomar/soltar y anotar en una conversación, y todo queda a nombre de ese usuario.
2. Sin token → 401; con token sin permiso `whatsapp` → 403 JSON.
3. `/api/documentation` muestra el tag "WhatsApp Mobile" con los 18 endpoints,
   ejemplos de request/response y el botón Authorize funcionando con Bearer.
4. La bandeja web y la API del agente no cambian de comportamiento
   (sus tests siguen igual que antes), salvo la visibilidad para usuarios
   restringidos, que es el cambio buscado.
6. Un usuario `individual`/`group` nunca recibe datos de una conversación
   fuera de su alcance, ni por listado ni por id.
5. El polling de un asesor no comparte bucket con el bot ni con otros asesores.

## Preguntas abiertas

Ninguna. Resueltas el 2026-10-10: visibilidad según `view_permission` del
usuario; 120/min confirmado; duración del token sin cambios; se agrega
`/unread-count`.
