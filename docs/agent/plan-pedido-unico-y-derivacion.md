# Plan: un solo pedido por cliente, y silencio total al derivar

Fecha: 2026-09-22 · Estado: **propuesta, nada ejecutado**
Involucra: CRM (kohlberg) + proyecto del agente
˜˜
---

## Problema 1 — se crean dos pedidos

### Qué pasa hoy

```
12:10  el cliente escribe por primera vez
       CRM → crea Person #2153
       CRM → crea Lead #1789  "WhatsApp - Danitza"  (vacío, Sin ciudad, Daniel)

12:17  el agente registra la venta
       agente → POST /api/v1/leads
       CRM → crea Lead #1792  "Pedido Promo Primavera"  (Sucre, Silvia, Bs 75)
```

Dos pedidos para la misma persona, con 7 minutos de diferencia. Medido el
2026-09-22: **81 casos**, el fantasma además en el pipeline y con el asesor
equivocados.

### Lo que ya existe y no se usa

El CRM **ya le manda el id del pedido al agente**, en cada mensaje entrante:

```json
{
  "conversation_id": 1416,
  "contact": { "phone": "+591...", "person_id": 2153, "lead_id": 1789 }
}
```

No falta infraestructura. Falta que el agente use ese `lead_id` en vez de crear
uno nuevo.

### El cambio

**CRM** — agregar al payload cuál es el pedido abierto del contacto:

```json
"lead": {
  "id": 1789,
  "stage": "No atendido",
  "is_open": true,            // se le puede agregar: NO está entregado ni cerrado
  "pipeline": "Sin ciudad",
  "products": [               // lo que YA tiene cargado
    { "product_id": 65, "quantity": 1, "price": 75 }
  ],
  "lead_value": 75
}
```

**Agente** — una regla, dos caminos:

```
si  lead.is_open  →  PUT  /api/v1/leads/{lead.id}     (agrega al pedido abierto)
si  no            →  POST /api/v1/leads               (abre uno nuevo)
```

### Qué cuenta como "abierto"

`is_open` es falso cuando la etapa del pedido es una de cierre:

```
Pedidos entregados · Pedidos cancelados · Cliente sin interés
Otros Servicios · Descartado
```

Es la lista que el CRM ya usa en `CitySyncService::CLOSED_STAGE_CODES`, así que
no hay un segundo criterio conviviendo.

Se incluyen "cancelados" y "sin interés" además de "entregados" por un caso
real: el pedido #1577 está en *Cliente sin interés*. Sin esta ampliación, un
pedido nuevo habría revivido uno que un asesor ya cerró.

### 🔴 El PUT REEMPLAZA los productos, no los suma

En `LeadRepository::update`, todo producto que no venga en el payload se borra:

```php
$previousProductIds = $lead->products()->pluck('id');
// ...
foreach ($previousProductIds as $productId) {
    $this->productRepository->delete($productId);
}
```

**Por eso el PUT tiene que mandar la lista COMPLETA**: lo que el pedido ya tenía
más lo nuevo. Si manda sólo lo nuevo, borra lo anterior.

Medido sobre septiembre: de 14 pares que harían PUT con esta regla, **10 tenían
contenido previo**. Ejemplo concreto:

```
#1462  Bs 270  Promo Vinos Blancos   ← lo que ya estaba
#1465  Bs 225  Promo Tannat          ← lo nuevo

PUT correcto   → products: [blancos, tannat]   lead_value: 495
PUT incorrecto → products: [tannat]            lead_value: 225   ← perdió Bs 270
```

Para eso el payload incluye `lead.products` y `lead.lead_value`: el agente no
tiene que consultar nada aparte, ya los recibe.

> ⚠️ **Corregido el 2026-09-22.** La regla de abajo (`is_open` ⇒ PUT) resultó
> equivocada al medirla: habría fusionado 15 pedidos genuinamente distintos. El
> interruptor real es **`is_empty`** — un lead por pedido. Ver
> `respuesta-multipedido-y-is-empty.md`, que es lo acordado con el equipo del
> agente y lo que manda.

### El flujo completo, tal como debe implementarlo el agente

```
1. Llega un mensaje. El payload trae contact.lead_id y lead.is_open.

2. El cliente confirma un pedido:

   ¿lead.is_open?
     SÍ  → PUT /api/v1/leads/{lead.id}
             products  = lead.products + los nuevos      ← lista COMPLETA
             lead_value = suma de todos
             lead_pipeline_id / user_id = la ciudad real
     NO  → POST /api/v1/leads   (pedido nuevo)

3. ¿El cliente corrige lo que acaba de pedir?
     → PUT sobre EL MISMO lead.id, con la lista corregida.
     → NUNCA un POST: eso es lo que produjo los duplicados.
```

### El duplicado es un error del flujo del agente

Tres pares creados con menos de 2 minutos de diferencia:

```
#1772  10:35:33  "Pedido de 2 Promo Primavera"  Bs 150  producto 65
#1773  10:36:04  "Pedido de 2 Promo Primavera"  Bs 225  producto 65   ← 31s después
```

Mismo título y mismo producto, distinto monto: el cliente corrigió la cantidad y
el agente registró de nuevo en vez de corregir. Con el paso 3 del flujo —PUT
sobre el mismo id— desaparece.

El CRM no puede resolverlo solo: no sabe si el cliente corrigió o pidió más.

### 🔴 Estrategias para que SIEMPRE se registren los productos

Medido sobre los 662 pedidos creados desde el 01/08/2026:

```
Pedidos CONFIRMADOS o ENTREGADOS sin una sola línea de producto:  27 de 182  (15%)
lead_value ≠ suma de los productos:                               33 leads
Filas de producto con amount = 0:                                 21 de 372
Filas con price = 0:                                               2
Filas con product_id nulo o quantity 0:                            0   ← esto ya quedó sano
```

Traducido: **1 de cada 7 ventas cerradas no dice qué se vendió.** Hay monto, hay
cliente, hay asesor — pero no hay detalle. Sobre eso no se puede sacar un
ranking de productos, ni reponer stock, ni calcular margen.

El `product_id` nulo ya no aparece (lo arreglamos en `LeadForm`), así que lo que
queda es un problema de *flujo*, no de validación de campos.

#### Estrategia 1 — el valor no existe sin el detalle

La regla más simple y la que corta el 15% de raíz:

> **El agente nunca manda `lead_value` solo.** Manda `products[]` y el
> `lead_value` sale de ahí: `lead_value = Σ (quantity × price)`.

Si el agente sabe cuánto cobrar, sabe qué está cobrando. No hay caso legítimo
donde tenga el monto pero no el ítem.

#### Estrategia 2 — resolver el producto contra el catálogo, siempre

El endpoint ya existe y el agente ya lo puede usar:

```
GET /api/public/products?q=tannat&disponible=1
→ { data: [ { id: 65, name: "Promo Tannat", price: 225, ... } ] }
```

Antes de registrar, por cada ítem que mencionó el cliente:

```
1. Buscar por nombre en /api/public/products
2. ¿1 resultado?     → usar ese id y ese price
3. ¿varios?          → preguntarle al cliente cuál (lista interactiva, 1.-) )
4. ¿ninguno?         → NO registrar un monto pelado: preguntar, o derivar
```

El precio sale del catálogo, no de lo que el agente calcule. Así `amount` nunca
queda en 0 (hoy pasa en 21 filas).

#### Estrategia 3 — confirmar con el cliente la misma lista que se va a guardar

Antes del POST/PUT, el agente repite el pedido:

```
Confirmo tu pedido:
  2 × Promo Tannat        Bs 450
  1 × Promo Vinos Blancos Bs 270
  Total: Bs 720
¿Está correcto?
```

Esa lista textual **es** el `products[]` del request. Un solo objeto en memoria
para las dos cosas: lo que el cliente ve y lo que se guarda no se pueden
desincronizar. Y de paso, el "¿está correcto?" es donde el cliente corrige —
antes de crear el pedido, no 31 segundos después con un POST duplicado.

#### Estrategia 4 — nunca cerrar un pedido vacío

Si un pedido llega a `cliente-confirmado` o `pedidos-entregados` sin productos,
se vendió algo que no quedó registrado en ningún lado. **Esto todavía no está
resuelto** — ver "Lo que quedó al descubierto", abajo.

#### Estrategia 5 — que el CRM lo haga visible

Las cuatro anteriores son instrucciones al agente, y las instrucciones fallan un
porcentaje de las veces. Por eso el CRM además:

- **valida** en `POST/PUT /api/v1/leads`: si viene `lead_value > 0` sin
  `products[]`, responde 422 con el motivo (nunca un 200 silencioso);
- **recalcula** `amount = quantity × price` del lado del servidor, así el agente
  no puede mandar un total inconsistente;
- **reporta**: un comando `leads:sin-detalle` que lista los pedidos cerrados sin
  productos, para verlo una vez por semana en vez de descubrirlo en seis meses.

#### Decidido: A — rechazar con 422

Cuando el agente manda un pedido con monto y sin productos, el CRM responde 422
con el motivo. El corte es angosto a propósito (`lead_value > 0` **y** cero
productos): sobre siete semanas de tráfico real eso son 5 requests, así que no
hay riesgo de que el agente empiece a perder pedidos en masa.

Implementado en `LeadForm::reglaValorSinDetalle()`, **sólo para `api/v1/leads`**.
El formulario del admin queda fuera: ahí un supervisor puede congelar un valor a
mano (`lead_value_is_manual`) y es un caso legítimo.

#### 🔴 Lo que quedó al descubierto al medir

El comando `leads:sin-detalle` listó los 27 casos, y **no son del agente**:

```
15 × "Consulta agendada"   Bs 0   Cliente confirmado   Jorge Bares, Gabriel Muñoz, Stefani Mendieta
 9 × "WhatsApp - <nombre>" Bs 0   Confirmado/Entregado Daniel Escalante, Gabriel Muñoz, Liliana Alarcon
 1 × "victor coa diaz"     Bs 239 Pedidos entregados   Silvia Taboada
```

26 de los 27 tienen `lead_value = 0`. O sea: **la regla A no los habría frenado
a ninguno**, porque no hay monto. El agujero real no es el agente mandando plata
sin detalle — es un pedido que se marca como ganado desde el formulario del
admin sin haberle cargado nunca los productos.

Dos cosas distintas ahí dentro, y conviene separarlas antes de poner otra regla:

Medido: de los 289 pedidos titulados "Consulta agendada", **79 terminaron con
productos**. No es otro flujo — es un título genérico del bot viejo. Así que los
27 son ventas de verdad y ninguno se excluye del reporte.

Queda pendiente la estrategia 4: **avisar, no bloquear**. Bloquear el cambio de
etapa le frena el trabajo al asesor en el peor momento —con el cliente
esperando— por un dato que puede cargar después. El reporte `leads:sin-detalle`
los deja visibles en una semana en vez de en seis meses, que era el problema.

### Los tres casos quedan cubiertos

| Caso | Hoy | Con el plan |
|---|---|---|
| **Un pedido, varios productos** (32 de 246) | array `products` | igual, sin cambios |
| **Varios pedidos del contacto** (21) | siempre POST | PUT mientras el anterior siga abierto; POST cuando se entregó |
| **Doble registro** (3 pares) | dos pedidos | PUT sobre el mismo id |

---

## Problema 2 — el agente escucha conversaciones derivadas

### Qué pasa hoy

```php
// Conversation.php
/** ¿Hay una derivación abierta? Es lo que silencia al agente... */
public function handoffOpen(): bool { ... }

public function aiEffective(): bool
{
    return $this->ai_enabled ?? (bool) config('whatsapp.ai.enabled');
}   // ← nunca consulta handoffOpen()
```

El comentario afirma algo que el código no hace. `IngestInboundPayload` decide
con `aiEffective()`, así que **el handoff no silencia nada por sí mismo**.

Funciona de casualidad: `Handoff::request()` apaga `ai_enabled`. Medido:

```
14 conversaciones derivadas · 35 mensajes del cliente después de derivar
0 respuestas de la IA
```

Pero el apagado es un flag que una persona puede volver a encender desde el
inbox. Ya pasó:

```
conv #323   assigned   ai_enabled = TRUE   derivada el 10/09   7 entrantes
```

Doce días asignada a un asesor, con la IA encendida. Hoy no contestó por suerte,
no por diseño.

### El cambio

**Una línea en el CRM**, que convierte la suerte en garantía:

```php
public function aiEffective(): bool
{
    // Derivada = la atiende una persona. El agente no escucha, sin importar
    // cómo haya quedado el interruptor.
    if ($this->handoffOpen()) {
        return false;
    }

    return $this->ai_enabled ?? (bool) config('whatsapp.ai.enabled');
}
```

Efectos, todos deseados:

- `IngestInboundPayload` deja de despachar `NotifyAgent` en conversaciones
  derivadas: **el agente no recibe el mensaje**, en vez de recibirlo y chocar
  con un 409 al responder.
- El interruptor de IA del inbox queda sin efecto mientras haya derivación. Para
  devolvérsela al bot hay que **resolver la derivación**, que es la acción que
  significa "ya lo atendí".
- El 409 en `AgentController::send()` se mantiene como segunda barrera.

### Qué tiene que hacer el agente

Nada. Deja de llegarle el mensaje. Si igual intenta responder, sigue recibiendo
409 con el bloque `handoff`.

---

## Problema 3 — derivaciones que nadie contesta

### La regla

Si pasan **24 h desde el último mensaje del cliente** sin que la derivación se
resuelva, se cierra sola y **la conversación vuelve al bot**.

El reloj corre desde el último mensaje del cliente, no desde que se derivó: si
el cliente siguió escribiendo, el caso está vivo aunque el asesor no haya
contestado, y cerrarlo sería peor que dejarlo.

### Lo que ya existe

`Handoff::resolve()` hace exactamente esto, y su default ya es devolver al bot:

```php
public function resolve(Conversation $c, ?int $actor = null, bool $reactivateAi = true)
```

Y hay scheduler corriendo (`inbound-emails:process` cada 5 min), así que no hace
falta infraestructura nueva. El trabajo es un comando y una línea en el Kernel.

### Estado actual

Medido el 2026-09-22 — **10 de 15 derivaciones abiertas ya pasaron las 24 h**:

```
conv #795   288h        conv #1223   98h
conv #833   243h        conv #1328   75h
conv #854   187h        conv #1319   28h
conv #966   145h        conv #1336   27h
conv #1196  118h        conv #1198   27h
```

Doce días la más vieja. Ninguna se cerró porque nadie lo hace a mano.

### Cuidados

- **La primera corrida cierra 10 de golpe.** Conviene `--dry-run` y avisarle al
  equipo, porque esas conversaciones vuelven al bot de un día para el otro.
- **Queda registrado**: `resolve()` escribe en la auditoría quién y cuándo. El
  cierre automático debe decir que fue por inactividad, no fingir que lo hizo
  una persona.
- **`assigned_user_id` se conserva**, así que no se pierde quién la atendía.
- El plazo va a config (`whatsapp.handoff.auto_resolve_hours`), no hardcodeado.

### Qué tiene que hacer el agente

Nada. La conversación le vuelve con `ai_enabled = true` y el handoff resuelto,
igual que si un asesor la hubiera cerrado a mano.

---

## Orden de implementación

| # | Dónde | Qué | Depende de |
|---|---|---|---|
| 1 | ✅ CRM | `aiEffective()` mira `handoffOpen()` | — |
| 2 | ✅ CRM | conv #323 — era la única así, y el paso 1 ya la silencia sin tocar el dato | 1 |
| 3 | ✅ CRM | `lead.is_empty`, `is_open`, `products` y `lead_value` en el payload | — |
| 4 | **Agente** | PUT si `is_empty`, POST si no · **lista completa de productos** | 3 |
| 5 | **Agente** | corregir un pedido = PUT sobre el mismo id, nunca POST | 3 |
| 6 | ⏸ CRM | `whatsapp:cerrar-derivaciones-inactivas` — hecho y probado; **falta agendarlo** | — |
| 7 | ✅ CRM | el listener `DiscardEmptyWhatsappLead` ya está registrado | — |
| 8 | ✅ CRM | 422 si viene `lead_value` sin `products[]` · `amount` derivado en el modelo | — |
| 9 | ✅ CRM | comando `leads:sin-detalle` (pedidos cerrados sin productos) | — |
| 10 | **Agente** | resolver cada ítem contra `/api/public/products` antes de registrar | — |
| 11 | **Agente** | confirmar la lista con el cliente y guardar **esa misma** lista | 10 |

Los pasos 1, 3, 6, 7 y 9 son del CRM y **no rompen nada del lado del agente**:
`is_open` es un campo nuevo que, si lo ignoran, deja todo como está hoy.

El paso 8 es un cambio de contrato —empieza a rechazar lo que hoy pasa en
silencio—, pero medido sobre siete semanas afecta a 5 requests, así que se pudo
adelantar sin esperar al 10.

El paso 6 está terminado pero **no agendado a propósito**: la primera corrida
cierra 10 derivaciones de golpe (la más vieja lleva 288 h) y esos clientes
vuelven al bot sin aviso. Se agenda con una línea en `app/Console/Kernel.php`:

```php
$schedule->command('whatsapp:cerrar-derivaciones-inactivas')->hourly();
```

Antes de esa línea: avisarle a los asesores. El `--dry-run` muestra la lista
exacta que se va a cerrar.

## Cómo se verifica

- Un mensaje entrante en una conversación derivada **no** dispara `NotifyAgent`.
- Resolver la derivación devuelve el agente a la conversación.
- Primer pedido por PUT: el contacto queda con **un** pedido, en su ciudad real.
- Segundo pedido por POST: el contacto queda con **dos**, ninguno descartado.
- Un contacto que escribe y nunca compra conserva su pedido vacío.
- Un PUT con la lista completa **conserva** los productos anteriores y suma el total.
- Un pedido en "Pedidos entregados" hace que el siguiente sea un POST.
- Un pedido en "Cliente sin interés" NO se reusa.
- Una derivación sin respuesta por 24 h desde el último mensaje del cliente se
  cierra y vuelve al bot, con la auditoría diciendo que fue por inactividad.
- Un POST con `lead_value` y sin `products[]` responde 422, no 200.
- `amount` guardado = `quantity × price`, aunque el agente mande otra cosa.
- Ningún pedido nuevo en "Confirmado" o "Entregado" queda sin líneas de producto.
