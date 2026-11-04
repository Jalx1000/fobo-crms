# Modelo de datos

> Estado actual: `whatsapp_conversations` y `whatsapp_messages` en producción, con datos vivos.
> La estrategia es **aditivo primero, rename después**, en commits separados.

---

## Tablas nuevas (aditivas, riesgo cero)

### `channel_tenants`

El dueño de las cuentas conectadas. Krayin no tiene concepto de tenant; se introduce el mínimo.

```php
$table->increments('id');
$table->string('name');
$table->string('slug')->unique();
$table->string('status')->default('active');   // active | suspended
$table->json('settings')->nullable();          // overrides de auto_create_lead, ai, etc.
$table->timestamps();
```

> **Por qué tabla propia y no los `groups` de Krayin:** los grupos agrupan *personas*, no expresan
> *propiedad de datos*. Colgar el aislamiento de ellos significa que mover a un usuario de grupo le
> abre el inbox de otro cliente. Si se prefiere atarlo a grupos, es cambiar la FK - pero hay que
> decidirlo **antes** de la migración.

### `channel_accounts`

Una cuenta conectada. Reemplaza a `config('whatsapp.gateway')` como fuente de verdad.

```php
$table->increments('id');
$table->unsignedInteger('tenant_id');           // FK channel_tenants
$table->string('channel');                      // whatsapp | messenger | instagram
$table->string('connection_mode');              // ver configuracion-canales.md
$table->string('name');                         // etiqueta legible: "Heaven Bolivia"

$table->string('external_id');                  // phone_number_id | page_id | ig_id
$table->string('parent_external_id')->nullable(); // waba_id | page_id (para IG vía FB)

$table->text('credentials')->nullable();        // cast encrypted:array
$table->json('subscribed_fields')->nullable();
$table->json('capabilities')->nullable();

$table->timestamp('token_expires_at')->nullable();
$table->timestamp('last_inbound_at')->nullable();
$table->json('health')->nullable();             // resultado del último diagnóstico
$table->string('status')->default('active');    // active | degraded | disconnected
$table->boolean('is_default')->default(false);

$table->timestamps();

$table->unique(['channel', 'external_id']);     // ver "colisión" en multi-tenant.md
$table->index(['tenant_id', 'channel']);
```

- `credentials` va con cast `encrypted:array` de Laravel. **Nunca** se devuelve completo por la API,
  ni siquiera al admin: siempre enmascarado (`EAAG…7f2a`).
- El **App Secret sigue en `.env`**: pertenece a la App, no a la cuenta.

### `channel_identities`

Cómo se identifica una persona en cada canal. Es la pieza que permite que IG y Messenger existan:
esos canales **no dan teléfono**.

```php
$table->increments('id');
$table->unsignedInteger('person_id');           // FK persons, onDelete cascade
$table->unsignedInteger('account_id');          // FK channel_accounts
$table->string('channel');
$table->string('external_id');                  // PSID | IGSID | teléfono E.164
$table->string('display_name')->nullable();
$table->string('profile_pic_url')->nullable();
$table->json('raw')->nullable();
$table->timestamps();

$table->unique(['channel', 'account_id', 'external_id']);
$table->index('person_id');
```

---

## Tablas existentes (rename + columnas)

### `whatsapp_conversations` → `conversations`

| Columna | Cambio |
|---|---|
| `channel` | **nueva** - `whatsapp` \| `messenger` \| `instagram` |
| `tenant_id` | **nueva** - denormalizada desde la cuenta, para filtrar sin join |
| `account_id` | **nueva** - FK `channel_accounts` |
| `external_contact_id` | **nueva** - PSID / IGSID / teléfono. Es la identidad real |
| `external_conversation_id` | **renombrada** desde `provider_conversation_id` |
| `window_expires_at` | **nueva** - vencimiento de la ventana de 24 h |
| `reopen_strategy` | **nueva** - `template` \| `human_agent_tag` \| `none` |
| `wa_phone` | **deja de ser UNIQUE**, pasa a informativo nullable |
| `gateway` | se mantiene por compatibilidad; el driver ya se resuelve por `account_id` |

Índice nuevo: `UNIQUE (channel, account_id, external_contact_id)`.

> Quitar el UNIQUE de `wa_phone` es el cambio de esquema más delicado del proyecto: es lo que hoy
> garantiza que no haya conversaciones duplicadas por teléfono. Su reemplazo es el UNIQUE compuesto,
> que debe existir **en la misma migración** - nunca dejar una ventana sin ninguno de los dos.

### `whatsapp_messages` → `messages`

| Columna | Cambio |
|---|---|
| `external_message_id` | **renombrada** desde `wa_message_id`. UNIQUE compuesto con `channel` |
| `channel` | **nueva** - denormalizada, para el UNIQUE y para filtrar |
| `reaction` | **nueva** - emoji de reacción a otro mensaje |
| `attachment_url` | **nueva** - URL original de FB/IG, solo para trazabilidad. **El archivo servido siempre sale de `media_path`** |
| `is_echo` | **nueva** - el mensaje se envió desde la Bandeja de Meta, no desde el CRM |

> `messages` es un nombre muy genérico en un Laravel. Verificar que no colisiona con nada del CRM ni
> de paquetes de terceros antes de ejecutar el rename. Alternativa: `channel_messages` /
> `channel_conversations`, coherente con el resto del prefijo.

---

## Estrategia de migración

Tres commits, en este orden, **nunca fusionados**:

**Commit 1 - aditivo.** Crea `channel_tenants`, `channel_accounts`, `channel_identities`.
Nada existente se toca. Reversible sin consecuencias.

**Commit 2 - backfill.** Un comando, no una migración, para poder correrlo y auditarlo por partes:

```
php artisan channels:backfill --dry-run
```

1. Crea el tenant `heaven` desde la configuración actual.
2. Crea la `channel_account` de WhatsApp leyendo `config('whatsapp.gateways.cloud_api')`
   (y la de Kommo si `WHATSAPP_GATEWAY=kommo`).
3. Por cada conversación: `channel='whatsapp'`, `tenant_id`, `account_id`,
   `external_contact_id = wa_phone`.
4. Por cada conversación con `person_id`: crea su `channel_identity`.
5. Verifica que el conteo antes y después coincide, y aborta si no.

**Commit 3 - rename.** Renombra las tablas y ajusta índices. Requiere:

- Copia de producción restaurada en local y migración probada ahí primero
- `down()` verificado, no solo escrito
- Ventana de despliegue con la cola pausada - un job en vuelo contra el nombre viejo revienta

---

## Decisión abierta: fusión de identidades

**El problema.** Llega un DM de Instagram de `@ana_perez`. Instagram **no da teléfono ni email**:
solo un IGSID opaco y un nombre público. En el CRM ya existe `Ana Pérez` con teléfono
`+591 7XXXXXXX` que conversa por WhatsApp. ¿Son la misma persona?

Opciones y su costo real:

| Política | Riesgo |
|---|---|
| Nunca fusionar | Dos `Person`, dos leads, historial partido, el tablero cuenta doble |
| Fusionar por nombre exacto | "Juan Pérez" hay cinco. Se filtra historial entre clientes distintos: **incidente de privacidad**, no un bug de UX |
| Nunca auto, pero sugerir | El sidebar propone "posible coincidencia" y un humano confirma. Seguro, con fricción |
| Solo con evidencia dura | Fusiona si el cliente escribe su teléfono/email en el chat, o si viene de un referral de anuncio con datos |

Hueco preparado en `Services/IdentityResolver.php`:

```php
/**
 * Decide si una identidad entrante de un canal sin teléfono (IG/FB)
 * corresponde a un Person existente.
 *
 * @param  ContactIdentity     $incoming    nombre público + external_id, sin teléfono
 * @param  Collection<Person>  $candidates  personas con nombre similar
 * @return array{person: ?Person, confidence: 'auto'|'suggest'|'none'}
 */
public function matchPerson(ContactIdentity $incoming, Collection $candidates): array
{
    // TODO: política de fusión. Ver planning/omnicanal/modelo-datos.md
}
```

Lo que se decida acá determina si los tableros cuentan contactos duplicados, o si un asesor puede
terminar viendo el historial de otro cliente.
