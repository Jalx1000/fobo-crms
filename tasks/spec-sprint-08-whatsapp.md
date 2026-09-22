# Spec: Sprint 08 — Plantillas, Handoff, Ubicación y consolidación del módulo WhatsApp

Estado: **aprobado** — 2026-09-01. Planificado en [`tasks/plan.md`](./plan.md), tareas en [`tasks/todo.md`](./todo.md)
Rama: `kohlberg-wa-v2` (parte de `kohlberg-whatsapp`)
Fecha: 2026-09-01
Plan narrativo: [`planning/sprint-08-plantillas-handoff-ubicacion.md`](../planning/sprint-08-plantillas-handoff-ubicacion.md)

---

## Objetivo

El módulo de WhatsApp de kohlberg tiene inbox, gateway Cloud API, respuestas rápidas y notas
internas. Le faltan cinco capacidades que los tres tenants hermanos ya usan en producción, y
todas menos una están bloqueadas por el mismo detalle: **el contrato de salida del gateway
expone un único método, `sendText()`**, y `Jobs\SendMessage` lo llama sin mirar el tipo del
mensaje. Plantillas, media y ubicación no tienen por dónde salir.

Este sprint amplía ese contrato y construye encima las seis cosas pedidas.

**Usuarios:**

| Quién | Qué gana |
|---|---|
| Los 8 asesores (rol `Encargado`) | mandar plantillas fuera de la ventana de 24 h, mandar una ubicación desde un mapa, recibir conversaciones derivadas |
| El administrador | un gráfico de envío de plantillas con su tasa de entrega |
| El agente IA (cuenta `Sistema`) | poder soltar una conversación a un humano, y recuperarla sola a los 30 min |
| El cliente final | recibe la ubicación como mapa nativo de WhatsApp, no como un link |

**Éxito, en una frase:** un asesor puede mandar una plantilla y una ubicación desde el inbox,
el agente puede derivar a un humano y recuperar la conversación solo, y el administrador ve
cuántas plantillas se mandaron y cuántas llegaron.

---

## Alcance

### Dentro

| # | Pedido original | Bloque |
|---|---|---|
| 1 | Reporte de envío de plantillas | A |
| 2 | Módulo de WhatsApp — "lo mejor de todos los proyectos" | 0 + F |
| 3 | Handoff / derivación a un asesor | B |
| 4 | Envío de ubicación | C |
| 5 | Moneda Bs en el detalle de productos | D |
| 6 | El agente se activa y desactiva solo | E |

### Fuera, explícitamente

- **Recordatorios programados** y envíos masivos de plantillas. El sprint entrega el envío
  1-a-1 desde el inbox; disparar plantillas por lote es otro problema (segmentación, opt-out,
  rate limits de Meta).
- **Multi-tenant / omnicanal** (Messenger en el mismo inbox). Existe en `imprimir_laravel`,
  no entra acá.
- **Migrar el gateway a Kommo.** Cloud API sigue siendo el activo.
- **Avisarle al cliente que fue derivado.** El CRM no le manda nada: el agente ya se lo dijo
  en lenguaje natural antes de llamar a la tool. Copiar el criterio de imprimir.

---

## Tech Stack

Sin dependencias nuevas de Composer ni de npm.

- Laravel 10 / Krayin CRM (monorepo Webkul/Concord)
- PHP 8.x, MySQL 9
- Blade + Vue 3 (el inbox ya es un componente Vue con `x-template`)
- **Leaflet 1.9.4 desde CDN**, cargado en runtime la primera vez que se abre el picker de
  ubicación. No entra al bundle de Vite ni a `package.json`.
- Pest PHP para tests
- Cola Redis, queue `whatsapp`

---

## Commands

```bash
# Dependencias
composer install
npm install

# Build del front (el inbox es Blade+Vue, no hay HMR para el componente)
npm run build
npm run dev                      # Vite en desarrollo

# Tests
php artisan test
php artisan test tests/Feature/WhatsappHandoffTest.php
./vendor/bin/pest --filter="deriva la conversacion"

# Estilo — SOLO sobre archivos tocados, nunca sobre un directorio
./vendor/bin/pint packages/Webkul/Whatsapp/src/Services/Handoff.php

# Migraciones
php artisan migrate

# Comandos del módulo (los dos primeros ya existen)
php artisan whatsapp:webhook-info
php artisan whatsapp:repair-gateway
php artisan whatsapp:templates --fresh          # nuevo, bloque A
php artisan whatsapp:reactivate-agent --dry-run # nuevo, bloque E
```

---

## Project Structure

Todo vive en el módulo, salvo dos archivos del Admin y uno del scheduler.

```
packages/Webkul/Whatsapp/src/
├── Gateways/
│   ├── Contracts/Gateway.php          ← se amplía: +sendTemplate, +sendMedia, +sendLocation
│   ├── CloudApi/CloudApiGateway.php   ← implementa los tres
│   ├── Kommo/KommoGateway.php         ← implementa los tres lanzando "no soportado"
│   └── Dto/
│       ├── OutboundLocation.php       ← nuevo
│       └── OutboundMedia.php          ← nuevo
├── Services/
│   ├── Handoff.php                    ← nuevo, port de imprimir
│   └── TemplateCatalog.php            ← nuevo, port de odontoking
├── Console/Commands/
│   ├── ListTemplates.php              ← nuevo
│   └── ReactivateAgent.php            ← nuevo
├── Jobs/
│   ├── SendMessage.php                ← despacha por tipo
│   └── NotifyHandoff.php              ← nuevo
├── Http/Controllers/
│   ├── InboxController.php            ← +templates, +sendTemplate, +sendLocation, +handoff
│   └── Api/AgentController.php        ← +handoff
├── Database/Migrations/               ← 3 nuevas
├── Exceptions/HandoffConflictException.php  ← nuevo
├── Mail/HandoffRequested.php          ← nuevo
├── Config/whatsapp.php                ← +templates, +locations, +handoff, +ai.reactivate
└── Resources/views/
    ├── inbox-component.blade.php      ← 3 modales nuevos + moneda
    └── emails/handoff/requested.blade.php   ← nuevo

packages/Webkul/Admin/src/
├── Helpers/Dashboard.php              ← +getTemplateSendStats()
└── Resources/views/dashboard/index/plantillas-enviadas.blade.php   ← nuevo

app/Console/Kernel.php                 ← +schedule del comando de reactivación
tests/Feature/                         ← Whatsapp{Template,Handoff,Location,Reactivate}Test.php
```

---

## Code Style

Pint (preset Laravel). Dos convenciones del módulo que importan más que el formateo:

**1. Los comentarios explican *por qué*, no *qué*.** El módulo está escrito así en los cuatro
tenants y es lo que hizo portables estas piezas. Ejemplo real de lo que se va a portar:

```php
/**
 * Take a pooled conversation.
 *
 * A single conditional UPDATE, not read-then-write: InnoDB serialises
 * updates on the same primary key, so with two advisors clicking at once
 * exactly one gets $affected === 1 and the other gets the conflict.
 *
 * @throws HandoffConflictException
 */
public function claim(Conversation $conversation, int $userId): Conversation
{
    $affected = DB::table($conversation->getTable())
        ->where('id', $conversation->id)
        ->where('handoff_state', Conversation::HANDOFF_REQUESTED)
        ->whereNull('assigned_user_id')
        ->update([
            'handoff_state'    => Conversation::HANDOFF_ASSIGNED,
            'assigned_user_id' => $userId,
            'assigned_at'      => now(),
            'ai_enabled'       => false,
            // DB::table() bypasses Eloquent, so timestamps are ours to keep.
            'updated_at'       => now(),
        ]);

    if ($affected === 0) {
        throw HandoffConflictException::alreadyClaimed();
    }
    // ...
}
```

**2. Nada específico del proveedor cruza el contrato del gateway.** El núcleo habla solo en
DTOs canónicos. Si un `payload` de Meta aparece fuera de `Gateways/CloudApi/`, es un bug.

**3. Los comentarios nuevos en español**, como el resto del módulo en este tenant; los
portados conservan su inglés original para que el diff contra el proyecto de origen siga
siendo legible.

---

## Testing Strategy

Pest PHP, en `tests/Feature/`. Un archivo por bloque.

### Protección de la base de datos — resuelto, 2026-09-01

> 🔴 **La suite corre contra la base de producción, y había un test que la vaciaba.**

Tres hechos verificados en el repo:

1. `phpunit.xml` **no sobreescribe ninguna variable `DB_*`**. Solo cambia `APP_ENV`, cache,
   mail, queue y sesión. `php artisan test` lee `.env` y se conecta a `DB_DATABASE=kohlberg`.
2. No existe `.env.testing`.
3. `ProductosByCiudadSucursalTest` hacía, en su teardown:
   ```php
   DB::table('attribute_values')->where('entity_type', 'products')->delete();
   DB::table('attributes')->where('code', 'ciudad_producto_sucursal')->delete();
   DB::table('products')->truncate();
   ```
   Contra la base real eso son **29 productos, 319 valores de atributo y la definición del
   atributo `ciudad_producto_sucursal`** que usa el endpoint en producción.

**Decisión: no se crea una base de test.** La protección vive en el código de los tests.

#### Capa 1 — corte antes de ejecutar (`tests/TestCase.php`)

`setUp()` registra un `DB::beforeExecuting` que lanza una excepción si una sentencia intenta
vaciar o alterar una tabla de negocio: `products`, `attributes`, `attribute_values`, `persons`,
`organizations`, `leads`, `users`, `roles`.

Bloquea tres formas: `TRUNCATE`, `DELETE` sin `WHERE`, y `DROP`/`ALTER TABLE`.

Va en `setUp()` y no en `Pest.php` a nivel de archivo: ahí se ejecutaría antes de que la
aplicación arranque y el facade de `DB` todavía no tiene raíz.

> **Por qué `DatabaseTransactions` no bastaba:** `TRUNCATE` es DDL y en MySQL provoca un
> `COMMIT` implícito. La transacción del test ya se cerró sola antes de que llegue el rollback.
> El corte tiene que ser *antes* de ejecutar, no después.

#### Capa 2 — los tests que necesitan tabla vacía se saltean

`ProductosByCiudadSucursalTest` afirma totales absolutos sobre toda la tabla (`total = 2`,
`total = 5`) y el endpoint además incluye siempre los productos con valor 10. Con 29 productos
reales cargados, esos asserts no pueden cerrar — por eso la versión anterior vaciaba la tabla.

Vaciar producción para que un assert pase no es una opción. El helper
`requiereTablaDeProductosVacia()` saltea esos 6 casos mientras la suite apunte a la base real,
y los corre normalmente si algún día hay una base descartable. Los casos de auth y validación
de parámetros no tocan datos y siguen corriendo siempre.

#### Capa 3 — teardown acotado

El teardown ahora registra los ids que crea y borra exactamente eso, por `whereIn`.

#### Verificado

```
ANTES:   29 productos · 319 attribute_values · 1 atributo
corrida: 2 failed, 6 skipped, 1 passed
DESPUES: 29 productos · 319 attribute_values · 1 atributo
```

Los 2 fallos son **preexistentes y ajenos a este cambio**: `Webkul\User\Models\User::factory()`
no existe porque el modelo no usa `HasFactory`. Son parte de los 15 fallos de la línea base.

### Convenciones de los tests de este sprint

Con la base de test ya en pie, siguen valiendo:

- `DatabaseTransactions` en todos los tests nuevos, nunca `RefreshDatabase` (correría
  `migrate:fresh`).
- **`truncate()` prohibido**, sin excepción. Borrar por `where` acotado a lo que el test creó.
- Cada test crea sus propios datos; nada asume una tabla vacía; nada borra lo que no creó.
- **15 fallos preexistentes** son la línea base, no cero: un test nuevo que rompa algo tiene
  que distinguirse de ese ruido.

| Nivel | Qué cubre | Dónde |
|---|---|---|
| Feature (HTTP) | endpoints nuevos, códigos de respuesta, permisos | `tests/Feature/Whatsapp*Test.php` |
| Feature (servicio) | `Handoff::request/claim/resolve`, idempotencia, carreras | `WhatsappHandoffTest.php` |
| Feature (comando) | `whatsapp:reactivate-agent` con `--dry-run` | `WhatsappReactivateTest.php` |
| Manual | los tres modales del inbox, en navegador | checklist en el PR |

**Cobertura mínima obligatoria** (no negociable, son los caminos que ya fallaron en otros tenants):

1. Dos `claim()` simultáneos sobre la misma conversación → exactamente uno gana.
2. `request()` llamado dos veces → un solo handoff, un solo mail, `changed: false` la segunda.
3. El JSON del agente devuelve `assigned_user` como **entero**, no como objeto.
4. `POST /messages` del agente sobre una conversación derivada → **409**.
5. Plantilla con cantidad de variables distinta a la declarada → **422** antes de encolar.
6. Tipo no soportado por el gateway activo → **501**, sin crear el mensaje.
7. `whatsapp:reactivate-agent` reactiva una conversación con `ai_disabled_at` en NULL
   (las apagadas antes de que existiera la columna).
8. Un mensaje posterior al corte **impide** la reactivación.

---

## Boundaries

### Always

- Rama `kohlberg-wa-v2`. Nada se mergea a `kohlberg` sin el bloque completo probado.
- Correr `./vendor/bin/pint` **sobre los archivos tocados, uno por uno** — nunca sobre un
  directorio: reformatea archivos ajenos y ensucia el diff.
- Cada capacidad nueva del gateway se declara en `Capabilities::$send` **solo si funciona de
  verdad**. El composer se renderiza desde ahí; una capacidad listada que falla es peor que
  una ausente.
- Mockup en texto/ASCII, confirmado, **antes** de escribir cualquiera de las tres UIs nuevas.
- Los tests nuevos limpian lo que crean.

### Ask first

- Cualquier migración que toque tablas fuera de `whatsapp_*`.
- Cambiar `APP_CURRENCY` o cualquier config global del CRM.
- Agregar una dependencia a `composer.json` o `package.json`.
- Cambiar el contrato existente del agente IA (`/conversations/{id}/messages`). Del otro lado
  hay un servicio que no controlamos.
- Tocar `Bouncer` o `acl.php` para permisos nuevos.

### Never

- Commitear tokens de Meta, `WHATSAPP_TOKEN`, `KOMMO_TOKEN` o el `app_secret`.
- Devolver **200** cuando el envío no ocurrió. Un 200 mentiroso es el fallo más caro de este
  módulo: el agente cree que habló y se queda callado.
- Reasignar un handoff que ya tiene dueño a otra persona automáticamente.
- Borrar o silenciar un test que falla para que la suite pase.
- Correr `php artisan migrate:fresh` o cualquier cosa destructiva contra la DB.
- Usar `truncate()` en un test. Es DDL: hace commit implícito y ningún `DatabaseTransactions`
  lo revierte.
- Correr la suite apuntando a `DB_DATABASE=kohlberg`.

---

## Success Criteria

Condiciones concretas y verificables. El sprint está hecho cuando **las trece** se cumplen.

**Bloque 0 — contrato**
1. `Gateway` declara `sendText`, `sendTemplate`, `sendMedia` y `sendLocation`; los dos
   gateways compilan y el contenedor arranca.
2. `SendMessage` despacha por `$message->type`; un tipo no soportado responde 501 y **no**
   deja un mensaje colgado en `queued`.

**Bloque A — plantillas**
3. Un asesor manda una plantilla aprobada a una conversación con la ventana de 24 h cerrada,
   y el cliente la recibe.
4. Una plantilla con la cantidad equivocada de variables devuelve 422 con el motivo, antes de
   llamar a Meta.
5. El administrador ve en el tablero un gráfico de plantillas enviadas por estado, con la tasa
   de entrega `(delivered + read) / enviadas`.
6. Un usuario con rol `Encargado` **no** ve ese gráfico.

**Bloque B — handoff**
7. El agente llama al endpoint de handoff, la conversación queda asignada al dueño del lead
   (o al pool si no tiene dueño activo con rol `Encargado`), y ese asesor recibe un mail.
8. Desde ese momento, `POST /messages` del agente responde 409.
9. Dos asesores tocan "Tomar conversación" al mismo tiempo: uno la toma, el otro ve un
   conflicto legible, y nunca queda con dos dueños.

**Bloque C — ubicación**
10. Un asesor abre el mapa, arrastra el pin y envía; el cliente recibe una **ubicación nativa**
    de WhatsApp, no un link de texto.
11. Una ubicación **entrante** de un cliente se ve en el thread como burbuja con enlace a Maps.

**Bloque D — moneda**
12. En el panel del cliente dentro del inbox, los cinco importes (producto, precio unitario,
    lead activo, total y pedidos) muestran Bs. El resto del CRM sigue igual.

**Bloque E — reactivación**
13. Un asesor apaga el agente; 30 min sin mensajes y el agente vuelve solo. Si entra un
    mensaje a los 20 min, la espera se reinicia y el agente **no** vuelve.

---

## Open Questions

Ninguna bloquea la Fase 2, pero tres afectan el alcance y conviene cerrarlas antes de
implementar el bloque que nombran.

| # | Pregunta | Bloquea | Por qué importa |
|---|---|---|---|
| 1 | **¿Hay plantillas aprobadas por Meta en la cuenta de kohlberg, y cuáles?** | Bloque A | `TemplateCatalog` lee de la Graph API. Sin plantillas aprobadas el catálogo lista vacío y no hay nada que probar de punta a punta. Es lo primero a verificar. |
| 2 | ¿El envío de ubicación se expone también al agente IA por API, o queda solo para el asesor? | HU-C2 | imprimir lo tiene solo en el admin. Exponerlo al agente es media hora más, pero cambia el contrato que el agente tiene que conocer. |
| 3 | ¿Leaflet desde `unpkg.com` está bien, o preferimos servirlo desde el propio dominio? | HU-C3 | Si el CDN se cae, el picker no abre. Self-hostearlo son ~150 KB en `public/` y una decisión de ops, no de código. |

---

## Verificación de este spec

- [x] Cubre las seis áreas núcleo
- [x] Los criterios de éxito son específicos y verificables
- [x] Boundaries definidos en los tres niveles
- [x] Guardado en el repositorio
- [x] **Revisado y aprobado por el humano** — 2026-09-01
