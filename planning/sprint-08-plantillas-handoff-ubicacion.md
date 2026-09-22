# Sprint 08 — Plantillas, Handoff, Ubicación y consolidación del módulo WhatsApp

> Continuación de [`sprint-07-submodulos.md`](./sprint-07-submodulos.md), que dejó **Plantillas
> explícitamente fuera** por su dependencia del gateway y de la aprobación de Meta. Este sprint
> la retoma y le suma las cuatro piezas que faltan para poner el módulo de kohlberg a la altura
> de los otros tres tenants.
>
> **Rama:** `kohlberg-wa-v2`, partiendo de `kohlberg-whatsapp`. Nada de esto se mergea a
> `kohlberg` hasta que el bloque completo esté probado.

## De dónde sale cada pieza

Tres tenants hermanos ya resolvieron partes de esto. Se porta, no se reinventa:

| Pieza | Origen | Estado en kohlberg |
|---|---|---|
| `sendTemplate` + `TemplateCatalog` + `whatsapp:templates` | `odontoking-crm` | ❌ no existe |
| `sendMedia` + `MediaController` (mime correcto al salir) | `odontoking-crm` | ❌ no existe |
| `sendLocation` + DTO `OutboundLocation` + **picker de mapa (Leaflet)** | `imprimir_laravel` | ❌ no existe |
| Handoff por columnas + `Services\Handoff` + pool/claim | `imprimir_laravel` | ❌ no existe |
| `whatsapp:reactivate-agent` + `ai_disabled_at` | `odontoking-crm` | ❌ no existe |
| Handoff con tabla propia + idempotencia por índice único | `c21_laravel` | descartado (ver §B) |
| Ubicación gateada por handoff sobre un `codigo` | `c21_laravel` | descartado (ver §C) |

## El cuello de botella: el contrato del gateway

Todo lo demás cuelga de acá. Hoy `Gateways\Contracts\Gateway` de kohlberg expone **un solo
método de salida**:

```php
public function sendText(Conversation $conversation, string $body, ?string $replyToProviderId = null): SendResult;
```

Y `Jobs\SendMessage` llama a `sendText()` a secas, sin mirar `$message->type`. Plantillas,
media y ubicación **no tienen por dónde salir**. Por eso el Bloque 0 va primero y bloquea a
A, C y F.

---

# Bloque 0 · Contrato de salida multi-tipo `[ALTO]` — 2d

### HU-0.1 · Ampliar el contrato `Gateway` — 0,5d `[BACK]`
Agregar al interface, siguiendo la firma que ya usan odontoking y c21:

```php
public function sendTemplate(Conversation $c, string $templateKey, array $variables = [], ?string $language = null): SendResult;
public function sendMedia(Conversation $c, OutboundMedia $media): SendResult;
public function sendLocation(Conversation $c, OutboundLocation $location, ?string $replyToProviderId = null): SendResult;
```

- DTOs nuevos: `Gateways\Dto\OutboundMedia`, `Gateways\Dto\OutboundLocation` (portar de imprimir).
- `Capabilities::$send` de `CloudApiGateway` pasa de `['text', ...]` a incluir
  `template`, `image`, `document`, `video`, `location`.
- **`KommoGateway` debe implementarlos también**, aunque sea lanzando la excepción de
  capacidad no soportada. Un interface ampliado sin tocar Kommo rompe el arranque del
  contenedor con un fatal de PHP, no con un 501 amable.

### HU-0.2 · `SendMessage` despacha por tipo — 0,5d `[BACK]`
Reemplazar la llamada fija a `sendText()` por un `match ($message->type)` como el de
`c21_laravel/.../Jobs/SendMessage.php:65`. El `catch (Throwable)` que ya guarda
`status='failed'` + `error` se mantiene tal cual.

### HU-0.3 · Guardarraíl de capacidades — 0,5d `[BACK]`
Antes de encolar, si `capabilities()->canSend($type)` es falso → **501**, nunca un 200
mentiroso que deja el mensaje en `queued` para siempre. Es la regla que c21 documentó en su
§4 de códigos de respuesta y conviene respetarla acá también.

### HU-0.4 · Tests del contrato — 0,5d `[QA]`
Un test por tipo con el gateway fake: se encola, se despacha al método correcto, y un tipo no
soportado devuelve 501 sin crear el mensaje.

> ⚠️ La suite corre contra la DB de producción y ya arrastra 15 fallos previos. Los tests
> nuevos no deben usar `RefreshDatabase`.

---

# Bloque A · Plantillas: envío + reporte en el tablero `[ALTO]` — 5d

Decisión confirmada: **primero el envío, después el reporte.** Un reporte sobre envíos que
hoy no existen no tendría qué contar.

## A.1 — Envío (port de odontoking)

### HU-A1 · `TemplateCatalog` — 1d `[BACK]`
Servicio que lee las plantillas aprobadas desde la Graph API de Meta y las cachea
(`whatsapp.templates.cache_minutes`, default 30). Comando `php artisan whatsapp:templates --fresh`
para refrescar a mano. Cada entrada expone `name`, `language`, `body`, `variables` (cantidad).

### HU-A2 · `CloudApiGateway::sendTemplate()` — 0,5d `[BACK]`
Port casi literal de `odontoking-crm/.../CloudApiGateway.php:174-210`. `$templateKey` resuelve
contra el catálogo; el `language` explícito gana sobre el de la plantilla; fallback `'es'`.

### HU-A3 · Endpoints `templates` / `send-template` — 1d `[BACK]`
```
GET  admin/whatsapp/templates        → catálogo, o {templates: [], reason} si el canal no soporta
POST admin/whatsapp/send-template    → {conversation_id|phone, template, language, variables[]}
```
Validaciones que ya probó odontoking y hay que conservar:
- Meta rellena los placeholders **por posición** y rechaza el mensaje entero si la cantidad no
  coincide → validar `count($variables) === $template['variables']` y devolver 422 con el motivo.
- Ninguna variable vacía (422).
- Si el agente IA está activo en la conversación → 422, igual que `send()`.

El mensaje se guarda con `type='template'`, `sender='human'`, `body` = preview renderizado y
`payload = {template, language, variables}`. **Ese `payload` es la única fuente del reporte**,
así que se guarda siempre, incluso cuando el envío después falla.

### HU-A4 · UI del composer — 1d `[FRONT]`
Hoy `inbox-component.blade.php:254` ya muestra el cartel *"Ventana de 24h cerrada… (o usar una
plantilla aprobada)"* — pero el botón no existe. Agregar el selector: lista de plantillas,
inputs por variable, preview con las variables sustituidas antes de mandar.

**Mockup a confirmar antes de tocar el blade** (regla del proyecto).

## A.2 — Reporte, como gráfico en el tablero solo para administradores

### HU-A5 · Estadística en `Helpers\Dashboard` — 1d `[BACK]`
Método nuevo `getTemplateSendStats()`, en línea con los ~30 que ya viven ahí. Agrupa
`whatsapp_messages` con `type='template'` por `payload->>'$.template'` y por `status`
(`queued|sent|delivered|read|failed`), dentro del rango de fechas del tablero.

**El filtro es `type='template'` + `sender='human'`.** Confirmado: el agente IA no manda
plantillas en kohlberg, así que el reporte cuenta únicamente lo que sale del CRM. Filtrar solo
por `type` funcionaría hoy y empezaría a mentir el día que el agente aprenda a mandarlas.

> **Ojo con el eje temporal:** el resto del tablero cuenta por `created_at`, no por fecha de
> cierre — decisión ya tomada para este CRM. El gráfico de plantillas se alinea a eso.

### HU-A6 · Card del tablero, admin-only — 0,5d `[FRONT]`
`dashboard/index/plantillas-enviadas.blade.php`, incluido desde `dashboard/index.blade.php`
dentro del `@if ($isAdminRole)` que **ya existe en la línea 55** de esa vista. No hace falta
inventar un permiso nuevo: el gate del tablero ya está puesto.

Barras apiladas por estado, una serie por plantilla. Debajo, la tasa de entrega
(`delivered+read / enviadas`) — que es el número por el que se pregunta un reporte de
plantillas.

---

# Bloque B · Handoff — derivación a un asesor `[ALTO]` — 4d

**Se porta el de `imprimir_laravel`, no el de c21.** Los dos funcionan, pero:

| | imprimir | c21 |
|---|---|---|
| Esquema | 6 columnas en `whatsapp_conversations` | tabla `whatsapp_handoffs` aparte |
| Idempotencia | `handoffOpen()` + `lockForUpdate()` | índice único parcial (`abierto_uid`) |
| Ruteo | dueño del lead → dueño del contacto → **pool** | titular del `codigo` del inmueble |
| Acoplamiento | ninguno | asume un catálogo con titular por ítem |

kohlberg vende productos, no inmuebles con un titular por ficha: **el modelo de pool de
imprimir es el que corresponde**. La idempotencia por índice único de c21 es más sólida, pero
exige la tabla aparte; el `lockForUpdate()` de imprimir alcanza para el volumen de este tenant.

### HU-B1 · Migración — 0,5d `[BACK]`
Port de `2026_07_29_000008_add_handoff_to_whatsapp_conversations.php`: `handoff_state`,
`handoff_reason` (500), `handoff_source`, `handoff_requested_at`, `assigned_user_id`,
`assigned_at`, + índice `wa_conv_handoff_idx`.

> ⚠️ `assigned_user_id` debe ser `unsignedInteger`, **no** `bigInteger`: `users.id` es
> `increments()` y el mismatch falla con un errno 150 críptico. La migración de imprimir ya
> trae ese comentario, conservarlo.

### HU-B2 · `Services\Handoff` — 1,5d `[BACK]`
Port de `imprimir_laravel/.../Services/Handoff.php` con sus cuatro operaciones:
`request()` (idempotente), `claim()` (UPDATE condicional, no read-then-write — dos asesores
clickeando a la vez y exactamente uno gana), `reassign()`, `resolve()`.

Dos detalles que hay que **adaptar a kohlberg**, no copiar:
- `ownerFor()` excluye roles de gestión vía `whatsapp.handoff.exclude_roles`. **Confirmado:
  Encargado es el único rol que recibe derivaciones**; todo lo demás — incluida la cuenta
  **Sistema**, que es el bot — va a la lista de excluidos. Concretamente:
  `'exclude_roles' => ['Administrador', 'Sistema', ...]`, es decir, todos menos `Encargado`.
  Si esto queda mal, cada derivación cae en alguien que no abre WhatsApp.
- El `audit()` deja una actividad `type='note'` en el timeline del lead y del contacto. Ya
  existe `InboxController::storeNote()` haciendo exactamente eso — reusar ese camino.

### HU-B3 · Endpoint del agente — 0,5d `[BACK]`
`POST /api/v1/whatsapp/conversations/{id}/handoff  {reason}` bajo `auth:sanctum` +
`throttle:agent-api`. Devuelve el bloque `handoff` con **`assigned_user` como entero**, no como
objeto: usar `toAgentArray()`, no `toArray()`.

> 🔴 Esto ya rompió en imprimir. El CRM devolvía un objeto donde el agente tipaba un `int`; el
> agente reventaba con un `ValidationError` de pydantic **y aun así respondía 200**, así que
> dejó de contestar sin que nada fallara visiblemente. Está documentado en
> `imprimir_laravel/code/planning/contrato-handoff-agente.md`. No repetirlo.

Y desde el `200`: `POST …/messages` del agente sobre esa conversación responde **409**, no 200.

### HU-B4 · UI del inbox — 1d `[FRONT]`
Chip *"Sin asesor · 3m"*, banner con el `reason`, botón **Tomar conversación**, y filtros
*derivadas / sin asesor / míos* (el índice de HU-B1 los sirve). Mockup antes de implementar.

### HU-B5 · Aviso + tests — 0,5d `[BACK/QA]`
Job `NotifyHandoff` → mail al asesor asignado, o a la lista del equipo si quedó en el pool.
Tests: idempotencia, carrera de dos `claim()` simultáneos, y que el agente reciba 409 después
de derivar.

---

# Bloque C · Envío de ubicación con selector en el mapa `[MEDIO]` — 2,5d

**Corrección respecto de la primera versión de este plan: el origen es `imprimir_laravel`, no c21.**
Son dos features distintas con el mismo nombre:

| | c21_laravel | imprimir_laravel ✓ |
|---|---|---|
| Quién manda | el agente IA, por API | **el asesor, a mano desde el chat** |
| De dónde sale el punto | ficha del inmueble (lat/lng guardados) | **pin arrastrable sobre un mapa Leaflet** |
| Precondición | 409 si no hay handoff abierto sobre ese `codigo` | ninguna |
| Acoplamiento | catálogo de inmuebles | ninguno |

Lo que se pidió — *"seleccionar un punto en el mapa para que se envíe en el chat como mapa"* — es
literalmente el modal de imprimir. c21 nunca abre un mapa: lee coordenadas que ya estaban cargadas.
Por eso este bloque sube de 1,5d a **2,5d**: la mayor parte es frontend.

### HU-C1 · Gateway + DTO — 0,5d `[BACK]`
Port de `sendLocation()` en ambos gateways y del DTO `Gateways\Dto\OutboundLocation`.

Ojo con la firma: imprimir lleva un tercer parámetro que c21 no tiene, y es el que corresponde
porque el resto del módulo ya soporta responder citando un mensaje.

```php
public function sendLocation(Conversation $c, OutboundLocation $location, ?string $replyToProviderId = null): SendResult;
```

El DTO trae un detalle que vale conservar: `name` y `address` son opcionales, **pero mandarlos
importa** — sin ellos WhatsApp dibuja coordenadas peladas, que nadie puede accionar. Y su
método `label()` es lo que termina en la columna `body`, así que el preview de la lista de
conversaciones y cualquier export en texto siguen diciendo algo útil.

### HU-C2 · Endpoint de envío — 0,5d `[BACK]`
`POST admin/whatsapp/send-location` con las validaciones de imprimir, que ya están bien puestas:

```php
'latitude'  => 'required|numeric|between:-90,90',
'longitude' => 'required|numeric|between:-180,180',
```

Si el canal activo no puede mandar ubicaciones → 422 con motivo legible, antes de encolar nada.
El mensaje se guarda con `type='location'` y `payload = ['location' => $location->toArray()]`.

### HU-C3 · El picker en el composer — 1d `[FRONT]`
Modal con las cuatro formas de fijar el punto que ya resolvió imprimir:

1. **"Usar mi ubicación"** — GPS del navegador, mostrando la precisión aproximada en metros.
2. **Mapa Leaflet** con pin arrastrable; un tap en el mapa también lo mueve.
3. **Lat/lng a mano**, para pegar coordenadas de otro lado.
4. **Ubicaciones guardadas** (`config('whatsapp.locations')`): sucursal, depósito, punto de
   entrega. Botones de un click, y el asesor igual puede ajustar el pin después.

`name` y `address` quedan como campos opcionales encima del botón de enviar.

Tres detalles de implementación que imprimir ya pagó y no conviene redescubrir:

- **Leaflet se carga del CDN la primera vez que se abre el picker**, no en el bundle. Son ~150 KB
  que la mayoría de las sesiones nunca usa.
- **El objeto del mapa NO va en `data()`.** Envuelto en la reactividad de Vue, Leaflet se rompe;
  va como propiedad común de la instancia.
- **Leaflet mide el contenedor al crearse**, y dentro de un modal que recién se abre ese
  contenedor todavía mide cero: hay que invalidar el tamaño después de montar. El código de
  imprimir además detecta el caso "contenedor sin alto" y avisa en vez de mostrar un mapa gris.

> **Mockup antes de tocar el blade.** Tercera UI de este sprint (con HU-A4 y HU-B4).

### HU-C4 · Render de la burbuja — 0,5d `[FRONT/BACK]`
La burbuja de ubicación es un enlace a `https://www.google.com/maps?q=lat,lng`, con nombre,
dirección y coordenadas a 5 decimales.

El helper `locationOf()` de imprimir lee **las dos formas del `payload`**: `['location' => …]`
para lo que mandamos nosotros, y el nodo crudo del proveedor para lo que llega entrante. Sin eso,
las ubicaciones que manda un cliente se ven vacías — y hoy kohlberg ya recibe ubicaciones, solo
que no las sabe mostrar.

---


# Bloque D · Moneda en Bs `[BAJO]` — 0,5d

El símbolo `$` está **hardcodeado en el blade**, no viene del backend:
`inbox-component.blade.php` líneas **445** (valor del lead activo), **481** (total del cliente),
**565** y **569** (monto y precio unitario de cada producto), **617** (valor de cada pedido).

Dos caminos:

| | Qué implica |
|---|---|
| `APP_CURRENCY=BOB` | Una línea de `.env`. `core()->formatBasePrice()` ya formatea con Intl → "Bs". **Pero cambia la moneda de todo el CRM**: cotizaciones, leads, exportaciones. |
| Formateo en el módulo | `config('whatsapp.currency', 'BOB')`, formateo **server-side** en `ChatController::person()` (que hoy manda `number_format()` pelado), y el blade deja de escribir el símbolo. Cambio acotado al inbox. |

**Recomendado: el segundo.** El pedido fue "en el detalle de productos del módulo de WhatsApp";
mover la moneda base del CRM entero es un efecto colateral que nadie pidió.

Aunque el pedido nombra solo los productos, las cinco líneas se corrigen juntas: un panel donde
el producto dice "Bs 239" y el pedido que lo contiene dice "$ 239" es peor que no haber tocado
nada.

---

# Bloque E · Reactivación automática del agente `[MEDIO]` — 1d

**Decisión confirmada: 30 minutos**, no 8 horas. Cuando un asesor apaga la IA para tomar el
mando, la IA vuelve sola si la conversación queda 30 min en silencio.

### HU-E1 · Columna `ai_disabled_at` — 0,25d `[BACK]`
Port de la migración de odontoking. `ai_enabled` sola dice que el agente está apagado pero no
**desde cuándo**, así que nada puede decidir que ya esperó bastante.

### HU-E2 · Estampar en `toggleAgent()` — 0,25d `[BACK]`
`InboxController::toggleAgent()` (línea 97) hoy hace un `update(['ai_enabled' => ...])` pelado.
Agregar el `ai_disabled_at = now()` al apagar y `null` al encender.

### HU-E3 · Comando + cron — 0,5d `[BACK]`
Port de `whatsapp:reactivate-agent` con su `--dry-run`. Config:

```php
'reactivate' => [
    'enabled'       => env('WHATSAPP_AI_REACTIVATE', true),
    'after_minutes' => env('WHATSAPP_AI_REACTIVATE_AFTER_MINUTES', 30),
    'after_hours'   => env('WHATSAPP_AI_REACTIVATE_AFTER_HOURS', 1), // respaldo
],
```

Los 30 min quedan en `.env`, así que si mañana se quiere 8h es una variable, no un deploy.

Tres reglas del original que **no** hay que perder al portar:
1. Solo `ai_enabled = 0` explícito. Un `NULL` significa "heredá el default global" — esa
   conversación nunca fue tomada por nadie y no hay nada que devolver.
2. `COALESCE(ai_disabled_at, updated_at)`: las conversaciones apagadas antes de que la columna
   existiera tienen `ai_disabled_at` NULL y si no se contemplan **el comando no reactiva nada**.
3. Cualquier mensaje posterior al corte **reinicia la espera**. Es lo que evita que el bot se
   meta en medio de una conversación viva.

### HU-E4 · Registrar en el scheduler — 0,25d `[DEVOPS]`
`app/Console/Kernel.php` hoy tiene **una sola** tarea (`inbound-emails:process`). Agregar:

```php
$schedule->command('whatsapp:reactivate-agent')->everyFiveMinutes()->withoutOverlapping();
```

Cada 5 min, no cada minuto: con una espera de 30 min, la granularidad fina no compra nada y sí
suma carga.

> **Interacción con el Bloque B:** una derivación pedida explícitamente por el cliente no
> debería volver al bot en 30 min — devolverle el bot a quien pidió una persona contradice el
> pedido. odontoking resolvió esto con un plazo aparte (`handoff_after_minutes`, 180). Si el
> Bloque B entra, este plazo largo entra con él.

---

# Bloque F · Consolidación: lo mejor de los cuatro tenants `[MEDIO]` — 3d

Piezas ya probadas en los proyectos hermanos que kohlberg no tiene y que valen el port. Se
priorizan **después** de A–E, y cada una es independiente.

| Pieza | Origen | Por qué |
|---|---|---|
| `MediaController` con mime propio | odontoking | Servido por el web server, un audio grabado como `audio/mp4` sale como `video/mp4` y WhatsApp lo rechaza. El controller fija el mime desde lo guardado en el mensaje. |
| Media saliente (imagen/doc/audio) | odontoking | El composer de kohlberg solo manda texto. |
| `ai.test_mode` + `test_numbers` | odontoking | Correr el agente contra 2 clientes reales en vez de contra todos. Imprescindible para estrenar cualquiera de los bloques de arriba. |
| `ai.blocked_numbers` | odontoking | Números que el agente nunca debe contestar, pasen lo que pasen los switches. |
| `phone_prompt_attempts` / `phone_rechazado` | c21 | Los contadores del pedido de teléfono los lleva el CRM, no el agente: sobreviven a un reinicio del agente y evitan que pida el número 20 veces. |
| `whatsapp:webhook-info` ampliado | los tres | Diagnóstico de un solo comando. Kohlberg ya lo tiene, falta que reporte plantillas y capacidades. |

---

## Orden de ejecución y dependencias

```
Bloque 0 (contrato del gateway)  ─┬─► Bloque A (plantillas + reporte)
                                  ├─► Bloque C (ubicación)
                                  └─► Bloque F (media saliente)

Bloque B (handoff)   ── independiente, pero define el plazo largo de ──► Bloque E
Bloque D (moneda Bs) ── independiente, se puede hacer cualquier día
Bloque E (30 min)    ── independiente
```

**Sugerido:** D + E primero (1,5d, cero dependencias, valor inmediato) → Bloque 0 → A → B → C → F.

| Bloque | Estimado |
|---|---|
| 0 · Contrato del gateway | 2d |
| A · Plantillas + reporte | 5d |
| B · Handoff | 4d |
| C · Ubicación (con picker de mapa) | 2,5d |
| D · Moneda Bs | 0,5d |
| E · Reactivación 30 min | 1d |
| F · Consolidación | 3d |
| **Total** | **18d** |

---

## Decisiones confirmadas

Las tres preguntas que bloqueaban el arranque están cerradas:

| # | Pregunta | Respuesta | Afecta |
|---|---|---|---|
| 1 | ¿Qué ubicación manda kohlberg? | **Ninguna fija.** El asesor elige el punto en un mapa, conversación por conversación. `config('whatsapp.locations')` queda solo como atajos guardados (sucursal, depósito), opcional | HU-C2, HU-C3 |
| 2 | ¿Qué roles reciben derivaciones? | **Encargado, y nadie más.** Todo lo demás va a `exclude_roles`, incluida la cuenta Sistema (el bot) | HU-B2 |
| 3 | ¿Qué cuenta el reporte de plantillas? | **Solo lo enviado desde el CRM.** El agente no manda plantillas → filtro `type='template'` + `sender='human'` | HU-A5 |

**No queda nada bloqueando.** El sprint puede arrancar por D + E.
