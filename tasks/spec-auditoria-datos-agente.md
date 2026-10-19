# Spec: Auditoría de datos de los pedidos cargados desde WhatsApp (agente IA)

Estado: **aprobado con ajustes** (15/10/2026)

## Objective

Verificar, **pedido por pedido**, que lo registrado en el CRM coincide con lo que el cliente
dijo en su conversación de WhatsApp, para:

1. Avisar al **equipo del agente IA** qué está cargando mal, con ejemplos concretos.
2. Señalar qué errores de datos vienen del CRM y no del agente, como recomendación.

**Alcance: solo auditoría de datos.** No se cambia código ni datos y no se diseñan
soluciones. El resultado son hallazgos con números y ejemplos.

Entregable: un `.md` en `docs/agent/auditoria-datos-2026-10.md`. Puede incluir nombres de
asesores, números de lead y teléfonos de clientes.

### Universo

- **Período:** leads con `created_at` desde el 2026-10-01 hasta el 2026-10-15 (ajustado por el usuario el 15/10).
- **Pedidos auditados:** todos los leads del período vinculados a una conversación de
  WhatsApp (`whatsapp_conversations.lead_id`). Se auditan **todos**, no una muestra.
- **Quién escribió cada campo:** el lead puede nacer de dos formas: lo abre el CRM solo
  (`LeadOpener`, `auto_create_lead`) o lo crea/actualiza el agente por API, y después lo
  edita un asesor. Con el historial de actividad se registra quién puso cada valor. Así
  un error se atribuye al agente, al CRM o al asesor.

### Contrato: campos que el agente debe llenar

| Campo | Dónde vive | Regla de "correcto" |
|---|---|---|
| Canal | `leads.lead_source_id` | Presente y = WhatsApp (o el canal real de la conversación) |
| Valor del pedido | `leads.lead_value` | > 0 y = Σ (cantidad × precio) de los productos del pedido |
| Asesor asignado | `leads.user_id` | = Encargado de la ciudad del pedido (tabla abajo) |
| Edad del cliente | attr `edad_lead` | Numérica, rango plausible, = la edad que dijo el cliente |
| Ciudad | `lead_pipeline_id` + attr `Ciudad` | Ambos iguales y = ciudad que dijo el cliente; no "Sin ciudad" si el cliente la dijo |
| Productos | `lead_products` | Mismos productos y cantidades que pidió el cliente; precio = catálogo |
| Descripción | attr `description` | Presente y resume el pedido (dirección, horario y notas que dio el cliente) |

Encargado por ciudad (fuente: `docs/agent/derivacion-a-asesor.md`): Tarija → Daniel
Escalante, Santa Cruz → Liliana Alarcon, La Paz → Stefani Mendieta, Cochabamba → Gabriel
Muñoz, Sucre → Silvia Taboada, Potosí → María Rene Solano Ruiz, Oruro → Nancy Delgado.

### Chequeos

| # | Chequeo | Cómo |
|---|---|---|
| C1 | **Completitud** de los 7 campos del contrato | SQL sobre todo el universo |
| C2 | **Formato válido** (edad numérica, valor > 0, ciudad ≠ "Sin ciudad", etc.) | SQL |
| C3 | **Consistencia interna**: pipeline = attr `Ciudad` = `cliente_ciudad` de la persona; asesor = encargado de la ciudad; conversación ↔ lead ↔ persona apuntan al mismo cliente | SQL |
| C4 | **Productos del pedido**: `lead_products` existe; cantidades > 0; precio unitario = catálogo; Σ = `lead_value` | SQL |
| C5 | **Fiel a la conversación**: leer cada conversación y comparar lo que dijo el cliente con ciudad, edad, productos, cantidades y descripción cargados | Lectura de mensajes, pedido por pedido |
| C6 | **Correcciones posteriores**: qué campos tuvo que editar un asesor después de la carga | Activity log |

## Commands

```bash
C=$(docker ps --format '{{.Names}}' | grep heaven_kolberg_laravel)
docker cp <scratchpad>/auditoria/<check>.php $C:/tmp/<check>.php
docker exec -w /code $C php artisan tinker /tmp/<check>.php
```

## Project Structure

```
tasks/spec-auditoria-datos-agente.md    → este spec
tasks/plan-auditoria-datos-agente.md    → plan
<scratchpad>/auditoria/*.php            → scripts de consulta (no se commitean)
<scratchpad>/auditoria/out/*.json       → resultados crudos por chequeo
docs/agent/auditoria-datos-2026-10.md   → informe final
```

## Verificación de los números

Cada cifra sale de un script que se puede volver a correr. Cada hallazgo trae ≥ 3 leads
de ejemplo revisados a mano. La suma de las categorías da el universo.

## Boundaries

- **Always:** solo `SELECT`; atribuir cada error al responsable (agente / CRM / asesor).
- **Never:** `INSERT`/`UPDATE`/`DELETE` en producción; correr Pint sobre directorios.

## Success Criteria

- [ ] Tabla por campo del contrato: % completo, % válido y % fiel a la conversación.
- [ ] Productos: % de pedidos con productos, cantidades y valor correctos.
- [ ] Asesor: % de pedidos asignados al encargado de su ciudad.
- [ ] Lista de pedidos con error: lead, teléfono, campo, valor cargado vs valor esperado,
      responsable.
- [ ] Top de problemas para el equipo del agente, cada uno con la regla esperada y ejemplos.
- [ ] Lista de errores atribuibles al CRM, como recomendación (sin implementar).
