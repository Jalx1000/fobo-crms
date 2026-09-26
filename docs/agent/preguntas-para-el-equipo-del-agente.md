# Preguntas para el equipo del agente

Acompaña a `plan-pedido-unico-y-derivacion.md`. El lado del CRM ya está
implementado; esto es lo que necesitamos saber de ustedes para activarlo sin
romper nada.

**Las que bloquean son la 1 y la 2.** El resto es para saber qué se rompe.

---

## 1. ¿Leen `lead.is_open` del payload?

Desde ahora el evento `message.received` incluye un bloque nuevo:

```json
"lead": {
  "id": 1462,
  "is_open": true,
  "stage": "No atendido",
  "lead_value": 270.0,
  "products": [
    { "product_id": 64, "name": "Promo Vinos Blancos", "quantity": 2, "price": 135.0 }
  ]
}
```

Es el interruptor entre PUT y POST:

```
is_open = true   → PUT /api/v1/leads/{lead.id}     (llenar el pedido que ya existe)
is_open = false  → POST /api/v1/leads              (pedido nuevo)
lead = null      → POST /api/v1/leads              (todavía no hay pedido)
```

**Si no lo leen, el duplicado sigue.** Cuando alguien escribe por primera vez, el
CRM ya le abre el person y el lead; si ustedes hacen POST igual, quedan dos
pedidos para la misma venta. Pasó 81 veces.

`is_open` es false en **todas** las etapas de cierre, no sólo "Pedidos
entregados": también Cancelados, Cliente sin interés, Otros servicios y
Descartado. El pedido #1577 estaba en "Cliente sin interés" y revivirlo habría
sido igual de malo que duplicarlo.

## 2. 🔴 ¿El PUT manda la lista COMPLETA de productos?

**Esta es la que más riesgo tiene.** El PUT **reemplaza** la lista de productos,
no la suma: en `LeadRepository::update` todo producto que no venga en el payload
se borra.

```
#1462  Bs 270  Promo Vinos Blancos   ← lo que ya estaba
#1465  Bs 225  Promo Tannat          ← lo nuevo

PUT correcto   → products: [blancos, tannat]   lead_value: 495
PUT incorrecto → products: [tannat]            lead_value: 225   ← perdió Bs 270
```

Medido sobre septiembre: de 14 pares que harían PUT con la regla nueva, **10 ya
tenían contenido**. Si implementan el PUT mandando sólo lo nuevo, destruyen
pedidos cargados.

Por eso el payload les manda `lead.products` completo: no tienen que consultar
nada aparte, es `lead.products` + lo nuevo.

## 3. ¿Ya resuelven el `product_id` contra el catálogo?

El endpoint está disponible:

```
GET /api/public/products?q=tannat&disponible=1
→ { "data": [ { "id": 65, "name": "Promo Tannat", "price": 225, ... } ] }
```

Queremos saber si hoy mandan un `product_id` real o sólo texto y monto. El
precio debería salir del catálogo, no de un cálculo del agente.

Si la búsqueda devuelve varios resultados, preguntarle al cliente cuál (lista
interactiva); si no devuelve ninguno, **no** registrar un monto pelado.

## 4. ¿Qué hacen con un 422?

`POST|PUT /api/v1/leads` ahora responde **422** si viene `lead_value > 0` sin
`products[]`. El cuerpo trae el motivo en `errors.lead_value`.

¿Reintentan, o el pedido se pierde? Sobre siete semanas de tráfico real esto
habría disparado 5 veces, así que no esperamos que sea frecuente — pero
necesitamos saber qué pasa cuando ocurre.

## 5. ¿Mandan `lead_pipeline_stage_id` siempre?

Si falta la clave, el endpoint revienta con `Undefined array key` **antes** de
validar nada. Lo encontramos escribiendo los tests. Es un bug del paquete
rest-api, no lo tocamos, pero conviene que siempre la manden.

## 6. ¿Cómo corrigen un pedido hoy?

Si el cliente cambia la cantidad y ustedes hacen un POST nuevo, ahí está el
duplicado:

```
#1772  10:35:33  "Pedido de 2 Promo Primavera"  Bs 150  producto 65
#1773  10:36:04  "Pedido de 2 Promo Primavera"  Bs 225  producto 65   ← 31 s después
```

Mismo título y mismo producto, distinto monto: el cliente corrigió y se registró
de nuevo en vez de corregir.

**Corregir un pedido es un PUT sobre el mismo id, nunca un POST.** El CRM no
puede resolverlo solo: no sabe si el cliente corrigió o pidió más.

---

## Un cambio que ya está activo y les afecta

**Una conversación derivada a un asesor ya no les llega.** `aiEffective()` ahora
mira si hay una derivación abierta, sin importar el flag `ai_enabled`. La
conversación #323 llevaba 12 días asignada a un asesor con el flag en true, así
que el agente contestaba por encima de la persona que la atendía.

Cuando la derivación se cierra —a mano o automáticamente a las 24 h sin
respuesta del cliente— la conversación les vuelve sola.
