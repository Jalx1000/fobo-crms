# Respuesta al reporte del 14/09

**De:** el lado CRM · **Para:** equipo del agente
**Estado:** los tres bugs 🔴 resueltos y desplegados. 223 tests verdes.

---

## Resumen

| # | Reporte | Veredicto | Qué hacer |
|---|---|---|---|
| 1 | Cotizaciones sin líneas | **Diagnóstico incorrecto** — las líneas se crean bien. Lo que fallaba era la LECTURA | Usar el nuevo `GET /productos/cotizaciones/{id}`, o leer `items` del 201 |
| 2 | HTTP 500 al leer una cotización | **Confirmado**, pero es 500 por *inexistente*, no por relación nula | Mismo endpoint nuevo: devuelve 404 |
| 3 | Handoff `requested` no apaga la IA | **Confirmado — bug nuestro. Corregido** | Quitar el guard defensivo si molesta; ya no hace falta |
| 4 | Messenger cae en el id-space de WhatsApp | **Parcialmente incorrecto**: es el mismo espacio a propósito | Se rutea al agente; botones ya soportados (quick replies) |
| 5 | Confirmaciones | **Las tres respondidas** por el negocio el 14/09 | Ver abajo |

---

## 1. Las líneas SÍ se crean

Reproduje el POST con vuestro body exacto, contra producción:

```json
{"sku":"CM_00002","cantidad":20,"marca":"Magia Verde",
 "criterios":{"Tamaño":"50 L","Canal":"TRADICIONAL","Zona":"Santa Cruz"},
 "nit":"…","empresa":"…"}
```

En la base quedó: `sku=CM_00002 · tr4=020117-0035 · cantidad=20 · precio=10.5000`.
TR4 correcto para 50 L, cantidad y precio correctos.

**Lo que falla es `GET /api/v1/quotes/{id}`**: el `QuoteResource` de Krayin **no
incluye `items` en ninguna forma** — no es que devuelva un array vacío, es que la
clave no existe. Sus campos son: `id, description, expired_at, subject, person,
user, billing_address, shipping_address, sub_total, discount_amount, tax_amount,
adjustment_amount, grand_total, updated_at, created_at`.

Vuestro `jq '{subject, items}'` produce `"items": null` sobre una clave ausente.

### Lo que hicimos

**a)** El `201` de `crear_cotizacion` ahora devuelve las líneas:

```json
{ "quote_id": 803, "asunto": "Bolsas Magia Verde — 50 L · TRADICIONAL · Santa Cruz",
  "total": 210, "entrega": {...},
  "items": [{
    "sku": "CM_00002",
    "descripcion": "Bolsas Magia Verde — 50 L · TRADICIONAL · Santa Cruz",
    "variante": {"Tamaño": "50 L"},
    "codigo_tr4": "020117-0035", "codigo_barra": "7779970880037",
    "medida": "(65×80) cm", "unidad": "PZA",
    "cantidad": 20, "precio": "10.5000", "total": "210.0000"
  }] }
```

**b)** Endpoint de lectura nuevo: **`GET /api/v1/productos/cotizaciones/{id}`** —
con líneas, y **404 cuando no existe**.

> Sobre las cotizaciones 437 y 679: sus ítems tienen `created_at` **posterior** a
> la cotización (una hora y nueve minutos después) y datos de carga manual
> (437: TR4 de 35 L, cantidad 1, precio 0 — cuando el pedido era 50 L × 20).
> Alguien las abrió en el CRM y agregó la línea a mano. No puedo probar si la
> original se creó y se perdió, o nunca se creó: son de antes de que el endpoint
> devolviera las líneas. **La 680 (Hules, 12:10) se creó con su ítem en el mismo
> segundo.** De ahora en más el 201 lo deja por escrito.

---

## 2. El 500 es por cotización inexistente

```
GET /api/v1/quotes/137     → 500   (137 no existe; quedan 4 cotizaciones)
GET /api/v1/quotes/999999  → 500
GET /api/v1/quotes/437     → 200
```

`QuoteController::show()` de Krayin hace `find($id)` y le pasa el `null` al
Resource, que intenta leerle el `id`. Es un bug de `vendor/krayin/rest-api` y no
lo parcheamos ahí. **Usen `GET /api/v1/productos/cotizaciones/{id}`**, que
devuelve 404.

---

## 3. Handoff `requested` — bug nuestro, corregido

Tenían razón y era una inconsistencia nuestra:

| Dónde | Qué miraba |
|---|---|
| Ruteo (`IngestInboundPayload`, `NotifyAgent`) | `aiEffective()` — solo «¿está encendida la IA?» |
| Envío (`AgentController::send`) | `handoffOpen()` → 409 |

O sea: **el CRM invitaba al agente a responder y después rechazaba la respuesta.**

Ahora los dos usan `agenteDebeResponder()` = `aiEffective() && ! handoffOpen()`,
y cubre `requested` **y** `assigned`. Además el payload dice la verdad:

```json
{ "ai_enabled": false, "handoff": { "open": true, "state": "requested" } }
```

`ai_enabled` pasa a significar «¿contestás vos?», no «¿está encendida la IA?» —
con un handoff pedido son cosas distintas: la IA sigue encendida porque el asesor
puede devolver la conversación.

Pueden quitar el guard defensivo. Si prefieren dejarlo, no molesta.

---

## 4. Messenger: mismo id-space **a propósito**

El módulo Messenger se unificó al inbox compartido: sus conversaciones viven en
la misma tabla, con `gateway = 'messenger'`, y **el endpoint
`/api/v1/whatsapp/conversations/{id}/messages` es el correcto para ellas**.

Verificado: hay 21 conversaciones de Messenger; la 583 existe y su driver resuelve
a `MessengerGateway`.

**El 404 de la 2263 no es de ruteo: esa conversación no existe** — ni ella ni
ninguna entre la 2255 y la 2275. Las de Messenger llegan hasta la 588. Si tienen
el `message.received` completo de ese evento, mándenmelo y lo rastreo.

**Corrección a lo que les dije antes (14/09):** escribí que Messenger no
soportaba botones porque "Meta no tiene el equivalente". **Eso era falso.**
Messenger tiene *quick replies* (hasta 13) y *button templates* (hasta 3); lo que
no los tenía era nuestro `MessengerGateway`. Ya está resuelto.

**Decisión del negocio: Messenger SÍ se rutea a este agente**, con el mismo
protocolo. Desde este cambio:

```
MessengerGateway → send: … + interactive
                   receive: … + interactive
```

- `mostrar_opciones` sobre una conversación de Messenger ya **no devuelve 501**:
  encola igual que en WhatsApp y sale como *quick replies*.
- **El payload no cambia.** Mandan el mismo `format: button|list` con los mismos
  límites (3 botones / 10 filas). Del lado del CRM se aplana la lista a burbujas,
  se pegan `header` y `footer` al cuerpo y se recortan los títulos a 20
  caracteres (tope de Messenger). `list_button` se ignora: no hay nada que abrir.
- **La elección vuelve igual que en WhatsApp**: mensaje entrante con el título en
  `text` y el id en `message.selection.id`. Sigan ruteando por el id, nunca por
  el título (que en Messenger además puede llegar recortado).
- Los títulos de sección de una lista **se pierden** en Messenger. Si el
  agrupamiento importa, díganlo en el `body`.

No hace falta mirar `gateway` para decidir si usar botones: úsenlos siempre.

---

## 5. Confirmaciones

**5.1 · Canal HOGAR → TRADICIONAL: CONFIRMADO por el negocio (14/09).** Su
criterio es el correcto. Y lo pasamos a los datos para que deje de depender de
que el modelo lo deduzca: la nota de las filas HOGAR ahora dice la regla completa.

```
"cotiza": false,
"nota": "Menos del mínimo (10 packs): venta por supermercado, indicar los puntos
         de venta de la ciudad del cliente. Desde 10 packs es venta directa:
         cotizar con el precio TRADICIONAL del mismo tamaño y zona."
```

Antes la nota decía *«no se cotiza por chat»* mientras ustedes cotizaban: el dato
contradecía al comportamiento. Ahora coinciden, y la regla está en el prompt
nuevo (sección CASOS QUE NO SE COTIZAN).

**HOGAR sigue con `cotiza: false` a propósito**: no hay precio propio para ese
canal. Lo que cambia es que la nota les dice exactamente qué hacer en cada caso.

**5.2 · `marca` — ahora sí se acepta.** Hasta hoy el endpoint **la ignoraba en
silencio** (no estaba en la validación). Ya está: se manda por **nombre**,
tolerante a acentos y mayúsculas, y se guarda como opción del atributo.

```json
"marca": "Magia Verde"   // o "Imprimir"
```

Valores válidos: **`Imprimir`** y **`Magia Verde`**. Decide el logo y el color
del PDF. Sin marca sale con el azul y el logo por defecto.

**5.3 · Máximos: CONFIRMADO, no hay tope (14/09).** No existe ni en el modelo
de datos ni en el negocio. No hay que cargar nada ni validar cantidad máxima. Si
algún día se define uno, se agrega como campo del producto y aparece en
`precio_producto` igual que el mínimo — ustedes no tendrían que cambiar nada.

---

## Cómo verificar

```bash
TOKEN='...'
BASE='https://imprimir.sofopolis.com/api/v1'

# Crear: el 201 ya trae las líneas
curl -sS -X POST "$BASE/productos/conversations/<conv>/cotizacion" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"sku":"CM_00002","cantidad":20,"marca":"Magia Verde",
       "criterios":{"Tamaño":"50 L","Canal":"TRADICIONAL","Zona":"Santa Cruz"},
       "nit":"1234567019","empresa":"Prueba SRL"}' | jq '{quote_id, total, items}'

# Leer: con líneas, y 404 si no existe
curl -sS "$BASE/productos/cotizaciones/<quote_id>" -H "Authorization: Bearer $TOKEN" | jq
curl -sS -o /dev/null -w '%{http_code}\n' "$BASE/productos/cotizaciones/99999999" -H "Authorization: Bearer $TOKEN"
```

Para el handoff: derivar una conversación y mandarle otro mensaje al bot. El
`message.received` tiene que llegar con `ai_enabled: false` — o no llegar.
