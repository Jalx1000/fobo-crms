# Plan técnico — Mensajes interactivos por marcador `1.-)`

Spec: [`tasks/spec-mensajes-interactivos.md`](./spec-mensajes-interactivos.md) · **aprobado 2026-09-02**
Tareas: [`tasks/todo-mensajes-interactivos.md`](./todo-mensajes-interactivos.md)
Rama: `kohlberg-wa-v2`
Estimado: **4 días**

> **Nota de nombres:** `tasks/plan.md` y `tasks/todo.md` son del Sprint 08, que sigue abierto
> (17 tareas pendientes de 48). Por eso este plan usa sufijo en vez de la ruta por defecto.

---

## Grafo de dependencias

```
        ┌──────────────────────────────────────────────┐
        │ F1 · DTO OutboundInteractive + excepción     │  (sin dependencias)
        └───────────────┬──────────────────────────────┘
                        │
        ┌───────────────▼──────────────┐   ┌──────────────────────────────┐
        │ F2 · InteractiveParser       │   │ F3 · Contrato + BaseGateway  │
        │      (el corazón)            │   │      + CloudApiGateway       │
        └───────────────┬──────────────┘   └───────────────┬──────────────┘
                        │                                  │
                        └───────────────┬──────────────────┘
                                        │
                        ┌───────────────▼──────────────┐
                        │ F4 · SendMessage + Agent-    │
                        │      Controller (el enganche)│
                        └───────────────┬──────────────┘
                                        │
              ┌─────────────────────────┼─────────────────────────┐
              │                         │                         │
    ┌─────────▼─────────┐   ┌───────────▼──────────┐   ┌──────────▼─────────┐
    │ F5 · Viaje de     │   │ F6 · Burbuja en el   │   │ F7 · El prompt     │
    │      vuelta       │   │      inbox           │   │      para el agente│
    └───────────────────┘   └──────────────────────┘   └────────────────────┘
```

**Paralelizable:** F2 y F3 no se tocan (uno parsea texto, el otro habla con Meta) — sólo
comparten el DTO de F1. F5, F6 y F7 son independientes entre sí.

**Camino crítico:** F1 → F3 → F4. Es lo mínimo para que un menú salga por WhatsApp.

---

## Orden de implementación, y por qué ese

### F1 · El DTO y su excepción — *primero, y solo*

`OutboundInteractive` + `InteractiveMessageException`. Sin red, sin base de datos: puro
objeto de valor con las constantes de Meta y la validación en los constructores nombrados
(`buttons()`, `list()`).

Va primero porque **es el contrato entre F2 y F3**. Si el parser y el gateway se escriben
contra formas distintas del mismo dato, se descubre al integrar, que es el peor momento.

### F2 · `InteractiveParser` — *el corazón del sprint*

Detecta `^\s*(\d{1,2})\.-\)\s*(\S.*?)\s*$`, valida consecutividad desde 1, mide los ítems y
decide botones / lista / degradar.

**Es la pieza con más casos borde y cero dependencias externas** — se puede probar entera sin
tocar Meta ni la base. Por eso va temprano: es donde más barato sale equivocarse.

Los siete casos que definen su comportamiento están en el criterio de éxito 1-7 de la spec.
El más importante es el negativo: `1.` `2.` a secas **no se convierte**. Ese es el que protege
contra el 50 % de falsos positivos medido sobre mensajes reales.

### F3 · El contrato del gateway — *en paralelo con F2*

Tres archivos, en este orden estricto:

1. `BaseGateway::sendInteractive()` con el `throw` **primero**.
2. Recién después, el método en `Contracts\Gateway`.
3. `CloudApiGateway::sendInteractive()` + `buttons`/`list` en `capabilities()`.

El orden importa: al revés, entre el commit 2 y el 3 cualquier driver que no implemente el
método hace **fatal al resolver el container**. Ya definimos este patrón en el sprint pasado
para media y ubicación; se repite igual.

### F4 · El enganche — *el punto donde esto empieza a existir*

`SendMessage`: un brazo más en el `match ($message->type)`, leyendo `payload['interactive']`.
`AgentController::send()`: pasa el texto por el parser antes de persistir, y guarda el tipo y
el payload que corresponda.

**Sólo en `AgentController`.** `InboxController::send()` no se toca — es lo que garantiza el
criterio 15 (un asesor que escriba `1.-)` manda texto plano).

Verificación al cerrar F4: un menú real sale al +59176616013. Es el primer punto del sprint
donde hay algo que mirar en un teléfono.

### F5 · El viaje de vuelta — *lo que hace que el sprint sirva*

`AgentPayload::message()` reporta el tap como `type: "text"` en vez de `"interactive"`,
manteniendo el bloque `interactive` al lado.

Va después de F4 y no antes porque **necesita un mensaje interactivo real para probarse de
punta a punta**. Antes de F4 sólo se puede testear con payloads inventados.

Es un cambio de tres líneas con el impacto más alto del sprint: sin él, el cliente toca una
opción y el agente no contesta.

### F6 · La burbuja del inbox

Hoy el `v-else` del blade muestra literalmente **"Tipo no soportado"**. Una rama más en el
`v-if`, mostrando body + opciones, con el payload que F4 ya dejó guardado.

Mockup en texto **antes** de tocar el blade, y verificación en un browser real: este archivo
ya dejó el inbox en blanco tres veces (Vue 3 `ref` en `v-for`, colisión Blade/Vue con `{{`,
y `msg.media.url` sin guardar).

### F7 · El prompt para el equipo del agente

`docs/agent/mensajes-interactivos.md`. **El sprint no cierra sin esto entregado.**

Va último a propósito: se escribe contra el parser ya implementado, no contra el borrador de
la spec. Un prompt que describe límites que el código no tiene es peor que no tenerlo.

---

## Riesgos

| # | Riesgo | Probabilidad | Mitigación |
|---|---|---|---|
| R1 | Ampliar `Contracts\Gateway` rompe el container | media | El `throw` en `BaseGateway` va en el commit **anterior** al del contrato. Ya validado en el sprint pasado. |
| R2 | Meta rechaza el payload por un detalle no documentado | media | Un envío real al +59176616013 al cerrar F4, botones y lista. Es la única prueba que vale. |
| R3 | El blade vuelve a dejar el inbox en blanco | **alta** — pasó 3 veces | Mockup antes de codear; verificación en browser real con consola abierta, no sólo curl. |
| R4 | El agente empieza a escribir `1.-)` para enumerar información | baja | La regla 2 del prompt lo dice explícito. Si igual pasa, el límite de 24 chars lo degrada solo. |
| R5 | El equipo del agente cambia el marcador por su cuenta | baja | El marcador queda en `config('whatsapp.interactive.marker')`, no hardcodeado en el regex. |
| R6 | Los tests tocan datos de producción | media | Guarda ya activa en `TestCase::protegerDatosReales()`. Teardown por id. Cero `truncate()`. |
| R7 | `1.-)` colisiona con texto real | **descartado** | Medido: 0 apariciones en 457 mensajes de todo el historial. |

---

## Decisiones técnicas

1. **El marcador vive en config, no en el regex.** `whatsapp.interactive.marker`. Cambiarlo
   después de que el agente lo tenga en el prompt requiere coordinación (está en *Ask first*),
   pero no un deploy de código.

2. **Un solo `type = 'interactive'` en la base.** Botones vs lista se distingue dentro del
   payload. Evita migrar la columna y evita que el `match` del job crezca dos brazos donde
   alcanza uno.

3. **Ids generados por el CRM (`opt:1`, `opt:2`…).** El agente nunca escribe un id, así que no
   puede alucinar uno — que es exactamente lo que hizo en producción con `ciudad_id: 12`, un
   pipeline que no existe.

4. **Degradar a texto, no 422.** Contradice a propósito la regla del contrato JSON. El agente
   cree que manda texto normal; un error lo dejaría mudo. Queda en
   `payload.interactive_skipped` con el motivo.

5. **La detección sólo en `AgentController`.** El composer del asesor manda lo que el asesor
   escribió, sin reinterpretar.

6. **El parser es un servicio, no un método del controlador.** Se prueba solo, sin HTTP, que
   es donde están los casos borde.

---

## Checkpoints de verificación

| Después de | Verificación | Cómo |
|---|---|---|
| F1 | Cada límite de Meta tira la excepción con el campo y el número | `php artisan test --filter=Dto` |
| F2 | Los 7 casos de la regla, incluido `1.` a secas → sin tocar | `php artisan test --filter=Parser` |
| F3 | El payload posteado a Graph tiene la forma exacta, ambos tipos | `Http::fake()` + assert del body |
| **F4** | **Un menú real llega al +59176616013**, botones y lista | teléfono en la mano |
| F5 | Round-trip: tap → `type: "text"` con la etiqueta | test de integración |
| F6 | El inbox muestra las opciones, **sin errores en consola** | browser real |
| F7 | El equipo del agente confirma que puede aplicarlo | entrega del archivo |
| cierre | La suite no suma fallos sobre la línea base (10 preexistentes) | `php artisan test` en el contenedor |
