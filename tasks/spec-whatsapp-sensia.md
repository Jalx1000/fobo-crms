# Spec: Puesta en marcha del módulo WhatsApp en Sensia

Estado: **fase 1 (specify) — pendiente de aprobación**
Rama: `feat/whatsapp`
Fecha: 2026-09-10

## Objetivo

El módulo `Webkul\Whatsapp` (WhatsApp Cloud API: inbox, envío, media, plantillas,
derivación a asesor, agente IA) fue portado desde kohlberg a Sensia. Los archivos ya
están copiados y verificados; **el módulo todavía no funciona**.

Este spec cubre lo que falta para que un asesor pueda abrir una conversación real y
contestarla, y para que una derivación llegue a una persona de carne y hueso.

**Usuario:** Gustavo Ortiz (rol `Encargado`, único asesor cargado) y los 3 usuarios
con rol `Administrador`.

**Éxito:** un mensaje entrante al +591 75526696 aparece en el inbox de Sensia, el
asesor lo contesta, la respuesta llega al cliente, y una derivación notifica por mail
a alguien que efectivamente puede abrir el chat.

## Estado verificado

Relevado sobre el código y la base de Sensia el 2026-09-10.

### Ya hecho (fase de instalación, completada)

| Área | Estado |
|---|---|
| `packages/Webkul/Whatsapp/` | 82 archivos, byte-idénticos al origen |
| Registro | autoload PSR-4, `config/concord.php`, `config/app.php`, provider |
| Rutas | 29 de WhatsApp + 3 del agente, confirmadas en `route:list` |
| Integración | `Core\Services\PhoneNumber`, dedup en `PersonRepository::findByPhone`, rate limiting `agent-api`, pestañas en `leads/view` y `persons/view` |
| Assets | `npm ci` + `vite build` OK; Tailwind escanea las vistas del módulo |
| Almacenamiento | `whatsapp/{inbound,outbound}` con dueño `www-data`, `public/storage` enlazado |
| Guardarraíl de tests | `tests/TestCase.php` + `tests/Pest.php` traídos (no existían en Sensia) |
| Tests | 65 pasan (lógica pura); 56 fallan sólo por tablas inexistentes |

### Datos reales de Sensia (evidencia de las decisiones de abajo)

```
Pipelines:  1 Palpalá (default) · 3 Barrio Alto Comedero · 4 San Salvador de Jujuy
Atributo:   cliente_ciudad = id 70, select, entity persons  → existe
Roles:      1 Administrador (all, 3 usuarios) · 2 Encargado (custom, 1 usuario)
Encargado:  Gustavo Ortiz <gortiz@sensia.com.ar>
Volumen:    2 leads, 2 personas
Leads:      Palpalá → Danitza Cuellar (rol 1) · San Salvador de Jujuy → Gustavo Ortiz (rol 2)
Moneda:     APP_CURRENCY=ARS   (kohlberg usaba BOB)
Cola:       QUEUE_CONNECTION=sync
```

## Problemas abiertos

### P1 — El rol que atiende WhatsApp no puede abrir WhatsApp

`whatsapp.handoff.advisor_roles = ['Encargado']` deriva al rol 2, pero los permisos
del rol 2 **no incluyen `whatsapp`**, y `EnsureWhatsappAccess` le devuelve 403.
Los roles `permission_type = 'all'` no están afectados: bouncer() les concede todo.

Migración esqueleto ya escrita en
`packages/Webkul/Whatsapp/src/Database/Migrations/2026_09_10_000011_add_whatsapp_permission_to_roles.php`,
con el predicado `atiendeWhatsapp()` sin implementar a propósito (ver Preguntas abiertas).

### P2 — Las derivaciones se pierden en silencio

`CityRouter::advisorFor()` deduce el asesor de quién es dueño de los leads de esa
ciudad, filtrando por `advisor_roles`. Con los datos reales:

| Ciudad (pipeline) | Dueño de sus leads | Rol | ¿Asesor? |
|---|---|---|---|
| San Salvador de Jujuy | Gustavo Ortiz | 2 Encargado | ✅ |
| Palpalá | Danitza Cuellar | 1 Administrador | ❌ excluido a propósito |
| Barrio Alto Comedero | *sin leads* | — | ❌ nada que deducir |

Las que fallan caen al pool, y `whatsapp.handoff.pool_email` está vacío → no sale
ningún mail. La conversación queda marcada como derivada y nadie se entera.

En kohlberg la heurística funcionaba porque había ~60 leads repartidos entre 7
ciudades con Encargados. Sensia arranca vacío: no hay de dónde deducir.

### P3 — Config heredada de kohlberg que no aplica

- `handoff.exclude_cities = ['Sin ciudad']` — ese pipeline no existe en Sensia. Inocuo, pero es ruido.
- `ui.currency_symbols = ['BOB' => 'Bs']` — Sensia es ARS. ICU resuelve ARS como `$` sin ayuda, así que no rompe; la entrada BOB queda muerta.
- `handoff.city_agents = []` — vacío a propósito en kohlberg (un mapa a mano se desactualiza). En Sensia, con 3 ciudades y 1 asesor, el mapa explícito puede ser la opción correcta.

### P4 — Credenciales incompletas

`.env` tiene los IDs dados (`WHATSAPP_PHONE_NUMBER_ID=1053595847846688`,
`WHATSAPP_WABA_ID=2421753778236455`) y **5 placeholders `CAMBIAR_`**:
`WHATSAPP_TOKEN`, `WHATSAPP_APP_SECRET`, `WHATSAPP_VERIFY_TOKEN`,
`WHATSAPP_AGENT_WEBHOOK_URL`, `WHATSAPP_AGENT_TOKEN`.

Sin `WHATSAPP_APP_SECRET` el webhook rechaza **todo** el tráfico entrante (firma
HMAC `X-Hub-Signature-256`). Sin `WHATSAPP_TOKEN` no se envía nada.

### P5 — Las tablas no existen

Las 8 migraciones del módulo están pendientes. Verificado: son **las únicas**
pendientes en toda la app, así que `migrate` no toca nada existente.

## Tech Stack

Sin dependencias nuevas. Laravel 10 / PHP 8.2, Krayin sobre Concord, Pest 2,
Tailwind + Vite. El módulo usa `guzzlehttp/guzzle` (ya presente) contra Graph API
`v21.0`.

## Comandos

```bash
# Todo corre dentro del contenedor: la base sólo resuelve en la red Docker.
C=heaven_sensia-laravel.1.8wsxdn0iyeaar353w36a9gbvn

# Migraciones
docker exec -w /code $C php artisan migrate --force

# Tests del módulo
docker exec -w /code $C ./vendor/bin/pest --filter="Whatsapp"

# Diagnóstico del gateway y URL del webhook
docker exec -w /code $C php artisan whatsapp:webhook-info
docker exec -w /code $C php artisan whatsapp:templates --fresh

# Estilo
./vendor/bin/pint

# Assets (tras tocar vistas del módulo)
docker exec -w /code/packages/Webkul/Admin $C bash -lc '. ~/.nvm/nvm.sh; npm run build'
```

## Estructura

```
packages/Webkul/Whatsapp/src/
  Config/        whatsapp.php · acl.php · menu.php
  Gateways/      contrato + CloudApi/ + Kommo/ + Dto/
  Services/      CityRouter · ConversationResolver · Handoff · InteractiveParser · TemplateCatalog
  Http/          Controllers/ (inbox, chat, media, webhook, Api/AgentController) + Middleware/
  Jobs/          IngestInboundPayload · SendMessage · DownloadInboundMedia · NotifyAgent · NotifyHandoff
  Database/Migrations/
tasks/           specs (este archivo)
tests/Feature/   Whatsapp*.php
```

## Estilo

El del módulo portado: comentarios en español que explican **por qué**, con la
evidencia que motivó la decisión. No se reescribe el estilo del código traído.

```php
/*
 * Un pipeline o etapa que no existe cae al valor por defecto, no a un 404.
 *
 * findOrFail() acá respondía 404 al GUARDAR un formulario que se había abierto
 * bien, que es una respuesta que no le sirve a nadie: la ruta existe, el
 * problema es un dato.
 */
```

## Estrategia de testing

Pest 2, `tests/Feature/`. **La suite corre contra la base real** (`phpunit.xml` no
sobreescribe `DB_*`); el guardarraíl de `tests/TestCase.php` aborta
`TRUNCATE`/`DELETE sin WHERE`/`DROP` sobre tablas de negocio antes de llegar al motor.

- Los tests nuevos borran **sólo por id** lo que ellos crearon.
- Los 16 tests portados deben pasar tras la migración.
- P1 y P2 necesitan tests nuevos: hoy no hay cobertura de "el asesor derivado puede abrir el chat".

## Límites

- **Siempre:** correr `pest --filter=Whatsapp` antes de commitear; verificar `migrate:status` antes de migrar; crear directorios de `storage` como `www-data`.
- **Preguntar antes:** correr migraciones contra producción; cambiar `advisor_roles` o `city_agents`; tocar credenciales; commitear o pushear.
- **Nunca:** commitear el `.env`; poner credenciales reales en este archivo ni en tests; borrar tablas de negocio desde un test; apuntar `WHATSAPP_AGENT_WEBHOOK_URL` al n8n de kohlberg.

## Criterios de éxito

1. `migrate:status` no muestra migraciones de WhatsApp pendientes.
2. `pest --filter="Whatsapp"` pasa completo (121 tests, 0 fallas).
3. Gustavo Ortiz (rol `Encargado`) abre `/admin/whatsapp` sin 403 y ve la pestaña WhatsApp en un lead.
4. Un usuario de un rol sin el permiso recibe 403 en `/admin/whatsapp/thread` (no sólo la pestaña oculta).
5. `whatsapp:webhook-info` reporta `Seguridad: firma HMAC (fuerte)` con el app secret real.
6. Meta valida el webhook (GET de verificación responde el challenge).
7. Un mensaje entrante real crea conversación + persona y se ve en el inbox.
8. Una respuesta saliente llega al teléfono del cliente.
9. **Toda** ciudad de Sensia resuelve a un asesor con permiso `whatsapp`, o el pool notifica a una casilla real. Ninguna derivación queda sin destinatario.

## Decisiones tomadas (2026-09-10)

- **D1 (PA1) → opción A.** El permiso `whatsapp` se concede a los roles listados en
  `whatsapp.handoff.advisor_roles`. Ata el permiso a la misma config que decide las
  derivaciones, así que la incoherencia "te derivo pero te doy 403" no puede reaparecer.
  Un rol nuevo no entra hasta que se lo sume a `advisor_roles`, que es la propiedad
  deseada: el permiso da acceso a leer toda conversación de cualquier cliente.

  ```php
  protected function atiendeWhatsapp(Role $role): bool
  {
      return in_array($role->name, config('whatsapp.handoff.advisor_roles', []), true);
  }
  ```

- **D2 (PA2) → mapa explícito + pool.** Las 3 ciudades apuntan al único `Encargado`:

  ```php
  'city_agents' => [1 => 11, 3 => 11, 4 => 11],  // Palpalá · Barrio Alto Comedero · San Salvador de Jujuy → Gustavo Ortiz
  'pool_email'  => 'gortiz@sensia.com.ar',
  ```

  El argumento que descartó el mapa en kohlberg (un mapa a mano se desactualiza) no
  aplica con 3 ciudades y 1 asesor. El `pool_email` queda de red de seguridad: sin él,
  una ciudad que no resuelva no notifica a nadie.

  **Deuda asumida:** cuando entren más Encargados hay que repartir el mapa. Queda
  anotado acá porque es exactamente el olvido que la config original quería evitar.

- **D3 (PA4).** El +591 75526696 es correcto pese a que las ciudades son de Jujuy y la
  moneda ARS. No hay ajuste: `Core\Services\PhoneNumber` es agnóstico de país
  (matching por sufijo), verificado sobre el código.

## Preguntas abiertas

- **PA3 (bloquea los criterios 5 a 8)** — ¿Cuándo hay `WHATSAPP_TOKEN`, `WHATSAPP_APP_SECRET`
  y `WHATSAPP_VERIFY_TOKEN` de Meta, y la URL/bearer del n8n de Sensia? Los criterios 1 a 4
  se pueden cumplir y verificar sin esto.
- **PA5** — `QUEUE_CONNECTION=sync`: los jobs corren dentro del request del webhook. ¿Se deja
  así para arrancar (kohlberg está igual) o se pasa a Redis + worker antes de producción?
