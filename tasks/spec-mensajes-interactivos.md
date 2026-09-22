# Spec: Mensajes interactivos salientes — botones y listas por marcador `1.-)`

Estado: **APROBADO** — 2026-09-02 (v2, reemplaza la v1 de contrato JSON)
Plan: [`tasks/plan-mensajes-interactivos.md`](./plan-mensajes-interactivos.md) · Tareas: [`tasks/todo-mensajes-interactivos.md`](./todo-mensajes-interactivos.md)
Rama: `kohlberg-wa-v2`
Consumidor: el agente IA. Doc hermano: [`docs/agent/derivacion-a-asesor.md`](../docs/agent/derivacion-a-asesor.md)

---

## Objetivo

El agente sólo manda texto plano. Cuando necesita que el cliente elija algo escribe las
opciones en prosa y espera que el cliente tipee. En producción eso falla de forma medible: el
agente preguntó la ciudad tres veces en la misma conversación, y cuando el cliente contestó
"Scz" resolvió `ciudad_id: 1`, que en los pipelines del CRM es **Tarija**, no Santa Cruz.

WhatsApp resuelve esto con mensajes interactivos: el cliente **toca** una opción en vez de
escribirla. La mitad de recibirlos ya está construida y testeada (`interactiveSelection()`,
6 tests). Falta enviarlos.

**La decisión que define este sprint:** el agente no aprende una API nueva. Numera las
opciones con el marcador `1.-)` y **el CRM las convierte** en botones o lista según cuántas
sean. El cambio del lado del agente es una frase en el prompt, no una tool.

**Usuarios:**

| Quién | Qué gana |
|---|---|
| El cliente final | toca en vez de tipear; no puede escribir una ciudad que no existe |
| El agente IA | opciones cerradas sin implementar nada nuevo |
| Los 8 asesores | menos conversaciones derivadas por un loop de desambiguación |

**Éxito, en una frase:** el agente escribe `1.-) Santa Cruz` … `7.-) Otra`, al cliente le
llega una lista tocable de WhatsApp, y cuando toca una el agente contesta como si la hubiera
escrito.

---

## La decisión de diseño, y por qué

Se evaluaron tres caminos. **Medidos sobre 183 mensajes salientes reales del agente:**

| Camino | Trabajo del agente | Falsos positivos |
|---|---|---|
| Contrato JSON (`interactive: {...}` en el POST) | tool nueva + manejo de 422 | 0 |
| Detectar `1.` `2.` en el texto | ninguno | **50 %** |
| **Marcador `1.-)`** ← elegido | una frase en el prompt | **0** |

El camino del medio se descartó con datos, no con opinión. De los 4 mensajes con lista
numerada que hay en el historial, **2 son menús y 2 son enumeraciones informativas**:

```
Alejandro, tienes 4 pedidos en estado "Cliente confirmado" en Santa Cruz:
1. Pedido de 2 Promo Varietales: 2 Promo Varietales, total Bs 478.00.
...
```
Eso no es un menú. Convertirlo le pediría al cliente que elija un pedido que nadie le ofreció,
y cada ítem tiene 65 caracteres contra un límite de 24 por fila.

El marcador `1.-)` se verificó contra **457 mensajes** (todo el historial, entrante y saliente):
**cero apariciones**. No colisiona con nada que el agente, los asesores o los clientes escriban
hoy.

---

## Alcance

### Dentro

1. `Services\InteractiveParser` — detecta el marcador y arma el DTO. Es el corazón del sprint.
2. DTO `Gateways\Dto\OutboundInteractive` con los límites de Meta validados al construirse.
3. `sendInteractive()` en el contrato del gateway; default en `BaseGateway` que tira `CapabilityNotSupportedException`.
4. Implementación en `CloudApiGateway` para **reply buttons** y **list message**.
5. Brazo `'interactive'` en `Jobs\SendMessage`.
6. Enganche en `AgentController::send()` — **sólo ahí**.
7. Reportar la respuesta del cliente al agente como `type: "text"` (ver "El viaje de vuelta").
8. Burbuja en el inbox que muestre las opciones ofrecidas.
9. `docs/agent/mensajes-interactivos.md` con el prompt exacto para el equipo del agente.

### Fuera, explícitamente

- **El composer del asesor.** La detección vive en `AgentController::send()`, no en
  `InboxController::send()`. Un asesor que escriba `1.-)` a mano manda texto plano. Decidido:
  sólo el agente. Además evita que un asesor dispare esto sin querer.
- **Contrato JSON estructurado.** Se puede agregar después sin romper esto; hoy no hace falta.
- **Descripciones de fila** (el subtítulo de 72 chars que permite Meta). Nadie lo pidió y
  obligaría a inventar un separador dentro del marcador.
- **Headers de imagen/video/documento**, CTA URL buttons, flows, product lists.
- **Botones dentro de plantillas.** Se configuran en Meta al aprobar la plantilla; no es esto.
- **Arreglar el agente.** El CRM entrega; el prompt lo cambia el otro equipo.

---

## La regla de conversión

```
Detectar en el texto saliente:   ^\s*(\d{1,2})\.-\)\s*(\S.*?)\s*$   (multilínea)

Requisitos para convertir:
  · 2 o más ítems
  · numeración consecutiva empezando en 1
  · TODOS los ítems entran en el límite de Meta

Elección del formato:
  2-3 ítems, todos ≤ 20 chars ──→ BOTONES  (reply buttons)
  2-10 ítems, todos ≤ 24 chars ─→ LISTA    (list message)
  cualquier otro caso ──────────→ TEXTO PLANO (degradación, ver abajo)

Armado:
  body        = el texto ANTES del primer ítem   (≤ 1024, requerido)
  footer      = el texto DESPUÉS del último ítem (≤ 60; si es más largo, se anexa al body)
  título fila = el ítem SIN el "N.-) "           (el cliente ve "Santa Cruz")
  id fila     = "opt:<n>"                        (el CRM lo genera; el agente no escribe ids)
  botón lista = config('whatsapp.interactive.list_button', 'Ver opciones')  (≤ 20)
```

### Degradación, y por qué no es un 422

Si el agente marca 12 opciones, o una de 40 caracteres, **no se puede** mandar como
interactivo: es un límite de Meta, no una preferencia. Ahí el CRM manda **texto plano**,
reescribiendo `1.-)` como `1.` para que al cliente le llegue un mensaje legible.

Esto contradice a propósito la decisión de "422 con detalle" que rige para un contrato JSON.
El motivo: acá el agente **no sabe que existe un 422**. Está haciendo un envío de texto normal.
Devolverle un error lo dejaría mudo frente al cliente, que es exactamente el fallo que este
sprint viene a arreglar. La degradación queda registrada en `messages.payload.interactive_skipped`
con el motivo, para que sea diagnosticable sin adivinar.

---

## El viaje de vuelta

Hoy, cuando el cliente toca una opción, `AgentPayload::message()` le reporta al agente
`type: "interactive"` — y el agente lo descarta:

```json
{"event":"crm_unsupported_message","msg_type":"interactive"}
```

El `body` **ya contiene la etiqueta** ("Cochabamba"): lo resuelve `extractBody()` desde el
sprint pasado. Entonces el CRM reporta ese turno como `type: "text"`, y el agente lo ve como
si el cliente lo hubiera escrito. El bloque `interactive: {kind, id, title}` sigue viajando al
lado para cuando lo quieran usar.

**Con esto el círculo cierra sin que el equipo del agente toque una línea de código.** Es la
pieza que hace que el sprint sirva el día que se mergea, y no el día que ellos se acuerden.

---

## Entregable obligatorio: el prompt para el equipo del agente

**El sprint no está terminado hasta que este prompt esté escrito y entregado.** No es
documentación de apoyo: es la única pieza que hace que el agente empiece a usar los menús.
Todo lo demás del sprint queda inerte sin ella.

Va en `docs/agent/mensajes-interactivos.md`, siguiendo el formato que ya usa
[`derivacion-a-asesor.md`](../docs/agent/derivacion-a-asesor.md): explicación, límites en
tabla, el bloque para pegar, y cómo probarlo con curl.

Borrador del bloque a pegar en el system prompt (se afina al implementar, contra el parser real):

```
## Opciones para que el cliente elija

Cuando le ofrezcas al cliente un conjunto cerrado de opciones —ciudades,
sucursales, categorías, sí/no— numeralas con el marcador `N.-)`, una por
línea:

    ¿Desde qué ciudad nos escribís?

    1.-) Santa Cruz
    2.-) Cochabamba
    3.-) La Paz

El CRM las convierte automáticamente en botones o en una lista tocable de
WhatsApp. El cliente toca en vez de escribir, y vos recibís la etiqueta
exacta que tocó ("Santa Cruz") como si la hubiera tipeado. No tenés que
llamar ninguna herramienta ni cambiar cómo respondés.

Reglas:

1. Máximo 10 opciones. Cada una, 24 caracteres o menos.
   Si no entran, el CRM manda el mensaje como texto normal y el cliente
   tiene que escribir la respuesta. Preferí opciones cortas: "Santa Cruz",
   no "Santa Cruz de la Sierra (zona norte)".
2. Usá `N.-)` SOLO para opciones que el cliente debe elegir.
   Para enumerar información —los pedidos que tiene, los productos de una
   promo— usá `1.` `2.` normales. Esas NO se convierten, y está bien así:
   no le pidas al cliente que elija algo que sólo le estás contando.
3. El texto antes de la lista es la pregunta; el de después, una nota al
   pie corta. Poné la pregunta ANTES de las opciones, siempre.
4. No numeres dentro de la opción ni repitas el número en el texto
   ("Escribí 1 para Santa Cruz"). El cliente toca, no escribe.
5. Fuera de la ventana de 24 h de WhatsApp esto no funciona, igual que
   cualquier mensaje libre. Ahí hace falta una plantilla aprobada.
```

Criterio de entrega: el equipo del agente recibe el archivo y confirma que lo puede aplicar.
No hace falta que lo hayan aplicado para cerrar el sprint — sí que esté entregado.

---

## Tech Stack

Sin dependencias nuevas. PHP 8.1 / Laravel 10, módulo `packages/Webkul/Whatsapp`, WhatsApp
Cloud API `v21.0`, Pest. `QUEUE_CONNECTION=sync` — `SendMessage` corre inline, no hay worker.

---

## Commands

```bash
# Tests — SIEMPRE dentro del contenedor: heaven_kohlberg no resuelve desde el host
docker exec heaven_kolberg_laravel.1.ww3k6gzeul1hp7t9329x8x441 \
    sh -lc 'cd /code && php artisan test tests/Feature/WhatsappInteractiveSendTest.php'

docker exec heaven_kolberg_laravel.1.ww3k6gzeul1hp7t9329x8x441 \
    sh -lc 'cd /code && php artisan test'

# Estilo — archivo por archivo, nunca sobre un directorio
./vendor/bin/pint packages/Webkul/Whatsapp/src/Services/InteractiveParser.php

# Recompilar vistas tras tocar un blade — como www-data, nunca root
docker exec heaven_kolberg_laravel.1.ww3k6gzeul1hp7t9329x8x441 \
    sh -lc 'cd /code && su -s /bin/sh www-data -c "php artisan view:clear"'
```

---

## Project Structure

```
packages/Webkul/Whatsapp/src/
├── Services/InteractiveParser.php           → NUEVO: el marcador → DTO
├── Gateways/
│   ├── Dto/OutboundInteractive.php          → NUEVO: DTO + límites de Meta
│   ├── Contracts/Gateway.php                → + sendInteractive()
│   ├── BaseGateway.php                      → default que tira la excepción
│   └── CloudApi/CloudApiGateway.php         → implementación + capabilities
├── Jobs/SendMessage.php                     → + brazo 'interactive'
├── Http/Controllers/Api/AgentController.php → engancha el parser en send()
├── Services/AgentPayload.php                → reporta el tap como type "text"
├── Config/whatsapp.php                      → + interactive.list_button
└── Resources/views/inbox-component.blade.php → burbuja de opciones

tests/Feature/WhatsappInteractiveSendTest.php → NUEVO
docs/agent/mensajes-interactivos.md           → NUEVO
```

---

## Code Style

Comentarios en español explicando **por qué**, no qué. DTOs `final`, promoción de constructor.

```php
final class OutboundInteractive
{
    public const MAX_BUTTONS = 3;
    public const MAX_ROWS = 10;
    public const MAX_BUTTON_TITLE = 20;
    public const MAX_ROW_TITLE = 24;

    /**
     * Se valida acá y no al postear a Meta porque Graph API contesta
     * "invalid parameter" y nada más: sin esto, un título de 25 caracteres
     * es media hora de bisección contra un endpoint de producción.
     */
    public static function list(string $body, array $rows, ?string $footer = null): self
    {
        if (count($rows) > self::MAX_ROWS) {
            throw InteractiveMessageException::tooMany('filas', count($rows), self::MAX_ROWS);
        }
        // ...
    }
}
```

---

## Testing Strategy

Pest, en `tests/Feature/WhatsappInteractiveSendTest.php`. `Http::fake()` para Meta —
**ningún test manda un WhatsApp real**.

| Nivel | Qué cubre |
|---|---|
| Parser | 3 ítems cortos → botones; 7 → lista; 12 → texto; un ítem de 40 chars → texto; numeración salteada (1,2,4) → texto; un solo ítem → texto; `1.` a secas → texto **(el caso que protege contra el 50 % de falsos positivos)** |
| Parser (armado) | body = lo de antes, footer = lo de después, títulos sin el `N.-) ` |
| DTO | cada límite de Meta por separado |
| Gateway | forma exacta del payload posteado a Graph, botones y lista |
| Job | `type = 'interactive'` va a `sendInteractive()` y no cae en `default => sendText()` |
| API | 409 con handoff abierto; 422 fuera de la ventana de 24 h; degradación deja `interactive_skipped` |
| Round-trip | mandar la lista, ingerir el `list_reply`, y que `AgentPayload` lo reporte como `type: "text"` con el label |

El round-trip es el que importa: es el contrato completo, no las dos mitades sueltas.

**Restricción heredada, no negociable:** la suite corre contra la base de **producción**. No
hay base de test y el usuario declinó crearla.

- Ningún test nuevo hace `truncate()`. La guarda de `TestCase::protegerDatosReales()` lo
  bloquea igual, pero no se escribe código que dependa de que la guarda lo atrape.
- Lo que se crea (conversación, mensajes) se borra por id en el teardown.
- Sin umbral de coverage: el proyecto no tiene medición configurada y un porcentaje inventado
  no lo verifica nadie.

---

## Boundaries

### Always

- Validar contra los límites de Meta **antes** de postear.
- Guardar el payload interactivo completo en `messages.payload`, como ya se hace con `location` y `media`.
- Degradar a texto plano reescribiendo `1.-)` → `1.`, y registrar el motivo en `interactive_skipped`.
- Correr los tests dentro del contenedor.
- Pint sobre los archivos tocados, uno por uno.

### Ask first

- Aplicar la detección también al composer del asesor.
- Tocar la migración de `whatsapp_messages` (hoy no hace falta: `type` es `string`, `payload` es json).
- Cambiar el marcador `1.-)` una vez que el equipo del agente lo tenga en el prompt.
- Cualquier cosa que mande un mensaje real al +59176616013.

### Never

- Convertir listas marcadas con `1.` a secas. Es el 50 % de falsos positivos medido.
- Devolver 200 cuando Meta rechazó el envío.
- Devolver un error al agente por una lista que no entra: se degrada a texto (ver arriba).
- Commitear el token de Meta o el `app_secret`.
- Correr artisan como root (rompe permisos de `storage/framework/views`; ya pasó dos veces).
- Borrar tests que fallan para que la suite pase.

---

## Success Criteria

1. Un POST del agente con `"1.-) Santa Cruz\n2.-) Cochabamba\n3.-) La Paz"` y texto previo
   crea un mensaje `type = 'interactive'` y postea a Graph un `interactive.type = "button"`
   con 3 `action.buttons[].reply.{id,title}`, títulos **sin** el `1.-) `.
2. Con 7 ítems → `interactive.type = "list"`, una sección, 7 `rows[]`.
3. Con 12 ítems → mensaje `type = 'text'`, el cuerpo con `1.` en vez de `1.-)`, y
   `payload.interactive_skipped` explicando el motivo.
4. Con un ítem de 40 caracteres → igual que 3.
5. Con `1.` `2.` `3.` a secas → texto plano intacto, **sin tocar el cuerpo**.
6. Numeración salteada (1, 2, 4) → texto plano intacto.
7. El body es el texto anterior al primer ítem; el footer, el posterior al último.
8. Fuera de la ventana de 24 h → 422 con el mensaje de plantilla que ya existe.
9. Con handoff abierto → 409, igual que un envío de texto.
10. `Jobs\SendMessage` con `type = 'interactive'` llama a `sendInteractive()`. Verificado con
    un gateway falso, no leyendo el `match`.
11. `KommoGateway::sendInteractive()` tira `CapabilityNotSupportedException` (501), no fatal
    por método faltante.
12. `capabilities()->send` de Cloud API incluye `buttons` y `list`.
13. Round-trip: entra el webhook de `list_reply` con la opción tocada y `AgentPayload::event()`
    lo reporta como `type: "text"`, `text: "Cochabamba"`, más `interactive: {kind, id, title}`.
14. En el inbox, un interactivo enviado muestra el body **y las opciones ofrecidas** — hoy el
    `v-else` del blade mostraría literalmente "Tipo no soportado". Sin errores en la consola
    de un browser real.
15. Un mensaje del asesor con `1.-)` sale como texto plano (la detección no se aplica ahí).
16. La suite completa no suma fallos nuevos sobre la línea base (10 fallos preexistentes).
17. `docs/agent/mensajes-interactivos.md` existe, con el marcador, los límites en tabla, la
    frase lista para pegar en el prompt, y la advertencia de la ventana de 24 h.

---

## Open Questions

Ninguna bloqueante. Resueltas en esta versión:

- ~~¿Contrato JSON o detección?~~ → marcador `1.-)`, verificado con 0 colisiones en 457 mensajes.
- ~~¿El agente descarta los interactivos entrantes?~~ → se resuelve del lado del CRM
  reportando el tap como `type: "text"`. Sin dependencia del otro equipo.
- ~~¿`ciudad:3` o slug?~~ → desaparece. El CRM genera `opt:<n>` y el agente recibe la
  etiqueta como texto. Nunca escribe un id.
- ~~¿La burbuja del inbox?~~ → va. Sin ella el asesor ve "Tipo no soportado" al leer el
  historial de una conversación derivada.

Para después, sin bloquear:

1. El marcador **no arregla** que el agente resuelva mal la ciudad (`"Scz"` → Tarija). La
   ataca por otro lado: el cliente ya no escribe la ciudad, la toca. Si igual se quiere el id
   exacto, el bloque `interactive.id` está disponible y se puede mapear después.
2. Descripciones de fila (72 chars) si aparece el caso de uso.
