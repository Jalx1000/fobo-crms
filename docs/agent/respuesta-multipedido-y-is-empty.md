# Respuesta: un lead por pedido, y el campo que lo decide

> Responde a `respuestas-del-equipo-del-agente.md`. Cambios ya aplicados en el
> CRM al 2026-09-22.

## Q1 — Aceptado: un contacto puede tener varios leads, uno por pedido

**Tienen razón y el plan estaba mal.** Lo medimos antes de contestar.

De los 25 casos donde un contacto hizo un segundo pedido con el anterior
**abierto y con productos** —exactamente donde `is_open ⇒ PUT` habría fusionado:

```
pedidos aparte de verdad:                  15
correcciones (<10 min, mismos productos):  10

#648  → #1326    33 días después   Promo Varietales → Promo Tannat
#1056 → #1499     8 días después   [8,13] → [8,13,17]
#1462 → #1465       20 min         Promo Blancos → Promo Tannat
```

`is_open ⇒ PUT` habría fusionado 15 pedidos genuinamente distintos en uno solo.
Y `#1462 → #1465`, el par que usábamos como ejemplo del "PUT destructivo", son
dos pedidos diferentes, no una corrección: el ejemplo estaba mal elegido.

Su modelo —rellenar el lead vacío y abrir uno nuevo por cada pedido posterior—
es el correcto. **Queda acordado así.**

## El campo nuevo: `is_empty`

Como `is_open` no es lo que decide PUT vs POST, el payload ahora manda la señal
que sí sirve:

```json
"lead": {
"id": 1462,
"is_empty": true,          ← el interruptor
"is_open": true,           ← contexto, ya no decide
"stage": "No atendido",
"lead_value": 0.0,
"products": []
}
```

```
is_empty = true   → PUT /api/v1/leads/{lead.id}   (es el que abrió el CRM, llenalo)
is_empty = false  → POST /api/v1/leads            (pedido nuevo, aunque el anterior siga abierto)
lead = null       → POST /api/v1/leads            (todavía no hay pedido)
```

Es exactamente la señal que hoy calculan ustedes con `GET /api/v1/leads/{id}` +
"¿tiene productos?". Ahora viene en el payload: **pueden sacar ese GET** sin
cambiar la lógica ni esperar a ningún acuerdo. `is_open` queda porque sigue
siendo cierto y sirve de contexto, pero no decide nada.

## Q2 — Sigue importando para la corrección (Q6)

Coincidimos: hoy no les afecta porque sólo hacen PUT sobre leads vacíos. Pero
cuando implementen la corrección post-registro (su punto 3), ahí **sí** van a
hacer PUT sobre un lead con contenido, y el PUT reemplaza. `lead.products` del
payload es justamente para eso: mandan eso + lo nuevo.

## Q4 — El 422 casi no les va a llegar

Confirmado: la validación sólo dispara con `lead_value > 0` **y** `products` vacío.
Como ustedes siempre mandan `products`, no debería aparecer. Igual está bien que
lo manejen en vez de perder el pedido.

## Q6 — De acuerdo, y sólo ustedes pueden resolverlo

Distinguir "corrección" de "pedido nuevo" depende de lo que dice el cliente, y el
CRM no lo ve. Guardar el `lead_id` recién creado y hacer PUT sobre ese id es la
solución correcta.

Referencia de cuánto pesa: en 7 semanas hubo **10 correcciones** contra 15
pedidos aparte. Las correcciones llegaron todas dentro de los 10 minutos y con
los mismos productos, por si les sirve como señal de respaldo.

## Su punto 4 (interno) — confirmado que es un bug de su lado

Verificado en el CRM: **cada etapa tiene su propio id en todos los pipelines**,
no se comparten entre etapas de una misma ciudad.

```sql
SELECT lead_pipeline_id, COUNT(*) etapas, COUNT(DISTINCT id) ids
FROM lead_pipeline_stages GROUP BY lead_pipeline_id HAVING etapas <> ids;
-- 0 filas
```

Así que el colapso de ids del commit `d6a61a8` no refleja nada del CRM: pueden
mandar el id real de cada etapa y distinguir entregado de cancelado sin apoyarse
en "tiene productos".

## Lo que queda de cada lado

| | |
|---|---|
| **CRM** | ✅ `is_empty` en el payload · ✅ 422 sin `products` · ✅ `amount` derivado · ✅ derivación silencia al agente · ⏸ cierre a 24 h (hecho, falta agendarlo) |
| **Agente** | leer `is_empty` y sacar el GET · manejar el 422 · corrección post-registro por PUT · revertir el colapso de stage ids |
