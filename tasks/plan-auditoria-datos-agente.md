# Plan: Auditoría de datos de pedidos de WhatsApp

Spec: `tasks/spec-auditoria-datos-agente.md`. Solo auditoría de datos: lectura contra producción, sin cambios de código ni de datos.

## Hallazgos previos que cambian el diseño

- **No todos los pedidos los crea el agente.** Con `auto_create_lead` el CRM abre un pedido
  apenas escribe un contacto nuevo (`LeadOpener`). Después el agente o un asesor lo
  completa. Por eso el universo se define por la conversación, no por el creador.
- Datos de referencia: hay 842 conversaciones abiertas desde el 15/09, 834 de ellas con
  lead. Solo 154 leads del período tienen como primer actor al usuario 13 (Agente). La
  fase 1 tiene que explicar esa diferencia: ¿leads viejos reutilizados? ¿creados por otro
  usuario?
- `leads.user_id` es el asesor asignado, no el creador.

## Fases

### Fase 1 — Universo y atribución
1. Script `universo.php`: leads del período con su conversación, persona y teléfono.
   Clasificar cada uno por **origen** (CRM auto / agente API / asesor) usando el primer
   actor del activity log, y anotar si la conversación apunta a un lead de antes del
   período.
2. Script `autoria.php`: para cada campo del contrato, quién escribió el valor actual
   (agente, CRM o asesor) y si fue corregido después.

**Checkpoint:** tamaño del universo por origen. Te lo muestro antes de seguir, porque
define cuánto lleva la fase 3.

### Fase 2 — Chequeos automáticos (C1–C4, C6)
3. `c1_completitud.php`: % de nulos/vacíos por campo del contrato.
4. `c2_formato.php`: edad no numérica o fuera de 15–100, valor ≤ 0, pipeline "Sin
   ciudad", título que es un teléfono o un placeholder.
5. `c3_consistencia.php`: pipeline vs attr `Ciudad` vs `cliente_ciudad`; asesor vs
   encargado de la ciudad; conversación/lead/persona con teléfonos distintos; personas
   duplicadas por teléfono.
6. `c4_productos.php`: pedidos sin productos, cantidad ≤ 0, precio ≠ catálogo (o ≠
   `precio_promocion`), Σ productos ≠ `lead_value`.
7. `c6_correcciones.php`: campos que editó un asesor después de la carga y cuánto tiempo
   después.

**Checkpoint:** tabla de resultados de C1–C4 y C6.

### Fase 3 — Fidelidad vs conversación (C5)
8. `transcripts.php`: genera un archivo por pedido con la conversación del cliente
   (inbound/outbound, en orden) y, al lado, los campos cargados y sus productos.
9. Revisión pedido por pedido, en lotes de ~25. Por cada campo se marca
   `ok | distinto | faltó cargar | cliente no lo dijo`, con el valor esperado según la
   conversación. Resultado en `c5_fidelidad.json`.
   - Si el cliente no dijo un dato (por ejemplo la edad), el campo vacío **no** es error
     del agente. Pero se registra, porque el agente no lo preguntó.

**Checkpoint:** los primeros 25 revisados. Te los muestro para calibrar el criterio antes
de seguir con el resto.

### Fase 4 — Informe
10. `docs/agent/auditoria-datos-2026-10.md`:
    - Resumen ejecutivo (5 líneas, con números).
    - Tabla por campo: completo / válido / fiel.
    - Productos y valor del pedido.
    - Asignación de asesor por ciudad.
    - **Para el equipo del agente:** problemas priorizados, regla esperada y ejemplos
      (lead, teléfono, extracto).
    - **Para el CRM:** errores de datos que vienen del CRM (recomendaciones, sin implementar).
    - Anexo: lista completa de pedidos con error.

## Riesgos

| Riesgo | Mitigación |
|---|---|
| Universo grande (hasta ~800 pedidos) hace lenta la fase 3 | El checkpoint de la fase 1 decide si se revisan todos o si se prioriza por origen |
| Conversaciones con audio o imágenes sin texto | Se marcan "no verificable", no como error |
| Precios de catálogo cambiaron durante el período | Comparar contra `price` y `precio_promocion`; si no coincide ninguno, marcar "a revisar" |
| Pedidos movidos de ciudad por la sincronización (`CitySyncService`) | Usar el activity log para ver la ciudad que puso el agente, no solo la actual |
