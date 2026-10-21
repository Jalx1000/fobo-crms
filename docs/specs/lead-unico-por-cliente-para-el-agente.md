# Un lead por cliente — propuesta del CRM para acordar con el agente

> Para el equipo del agente.
> **Estado: PROPUESTA, pendiente de acuerdo.** El CRM tiene hoy activa una protección provisoria
> (§4); se mantiene, se ajusta o se revierte según lo que acordemos acá.

---

## 1. El problema

Un mismo cliente termina con **dos o tres leads abiertos** en el kanban, todos de tipo `Comprador` y
origen `WhatsApp orgánico`. Los asesores los borran a mano (el 14/10 se borraron los leads #70, #74 y
#77 por esto).

No hay nada en el CRM que cree leads por su cuenta: no hay automatizaciones (workflows) ni código en
la recepción de mensajes que lo haga. **Todos salen de `POST /api/v1/inmobiliaria/conversations/{id}/solicitud`.**

## 2. Evidencia (log del servidor, 14/10, hora UTC)

| Conversación | Llamadas a `solicitud` | Leads creados |
|---|---|---|
| #259 (Vivi) | 18:21:35 · 18:34:56 · 18:55:37 | 3: «Busca inmueble», «Busca departamento», «Busca departamento hasta USD 150.000» |
| #253 | 18:21:33 · 18:58:15 | 2 |
| #261 | 18:57:13 · 20:02:51 | 2 |
| #262 | 20:38:10 · 20:42:54 | 2 |
| #265 | 21:28:31 · 22:42:19 | 2 |
| #267 | 22:47:22 · 22:53:23 | 2 |

Patrón: una primera llamada con pocos datos («Busca inmueble») y otra más tarde, cuando el agente ya
sabe tipo, zona o presupuesto.

Además, a las **18:21:32–18:21:35** llegaron 11 llamadas seguidas para las conversaciones #250 a #260,
en 3 segundos. Parece un proceso masivo (¿un reprocesamiento?), no conversaciones en vivo.

## 3. Qué dice hoy el contrato

El docblock del endpoint dice: *«Lead de comprador. Se crea una sola vez, cuando el cliente
confirmó.»* El CRM confiaba en eso y creaba un lead nuevo en cada llamada. En la práctica el endpoint
se usa también para **actualizar** lo que el agente va aprendiendo.

## 4. Lo que el CRM tiene activo hoy (provisorio)

Para frenar los duplicados mientras lo acordamos:

- Si el contacto ya tiene un lead **abierto del mismo tipo** (no está en «Cierre exitoso» ni en
  «Desistimiento»), `solicitud` / `captacion` / `postventa` **actualizan ese lead** (título, detalle,
  presupuesto si es > 0, inmuebles de interés) en vez de crear otro.
- Si el cliente ya cerró o desistió y vuelve a escribir, **sí** se crea un lead nuevo.
- Llamadas simultáneas sobre la misma conversación se ejecutan una detrás de otra (bloqueo de la fila).
- La respuesta es la misma de siempre (`message`, `lead_id`, `referencia`), con un campo extra:
  `"actualizado": true|false`. `referencia` sigue siendo `Solicitud #<lead_id>` del lead existente.

**No hace falta ningún cambio del lado del agente para que esto funcione**, pero cambia lo que
significa llamar dos veces, y por eso lo traemos a acordar.

## 5. Opciones

| | Cómo | A favor | En contra |
|---|---|---|---|
| **A. Upsert en el CRM** (lo que está activo) | `solicitud` crea o actualiza según haya lead abierto | Cero cambios en el agente; robusto ante reintentos | El agente no distingue «registré» de «actualicé» salvo por `actualizado` |
| **B. Dos endpoints explícitos** | `solicitud` solo crea (409 si ya hay uno abierto, devolviendo su `lead_id`); `PATCH …/leads/{id}` actualiza | Intención clara en cada llamada; auditable | El agente tiene que guardar el `lead_id` y elegir qué llamar |
| **C. El agente llama una sola vez** | El agente espera a tener los datos y registra al final | Nada que cambiar en el CRM | Si la conversación se corta antes, no queda lead; frágil ante reintentos |

**Recomendación del CRM: A** (ya activa) y, si quieren más control, sumar el `PATCH` de B como
complemento. C sola no alcanza: un reintento por timeout vuelve a duplicar.

## 6. Preguntas para el equipo del agente

1. ¿Llamar a `solicitud` varias veces es **intencional** (para ir completando datos) o es un efecto
   no buscado del flujo?
2. ¿Qué fueron las 11 llamadas de las 18:21:32–35 UTC del 14/10? ¿Un reprocesamiento, una migración?
3. ¿Hay algún caso donde **quieran** dos leads abiertos del mismo tipo para el mismo cliente (por
   ejemplo, busca una casa para él y otra para un familiar)? Hoy el upsert los juntaría.
4. ¿Les sirve el campo `actualizado` en la respuesta, o prefieren otro formato (por ejemplo, un
   código HTTP distinto: 201 al crear, 200 al actualizar)?
5. ¿Prefieren A, B o C?

## 7. Limpieza de los duplicados existentes

Queda uno: **Mir Angela** con los leads #46 y #60. Proponemos unirlos en uno (el más reciente,
con los inmuebles de interés y las actividades de los dos) una vez acordado el criterio.
