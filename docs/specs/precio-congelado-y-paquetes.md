# Spec: Precio congelado por paciente + Paquetes (Tessa)

> Estado: **BORRADOR — pendiente de aprobación.** Varias reglas están BLOQUEADAS
> esperando definición de Tessa/Sofopolis (ver Open Questions).

## Objective

Registrar el **plan de tratamiento** de una paciente para que:
- **Precio congelado** (preguntas 14 y 16): cuando toma una promo, se le respeta ese
  precio durante todo su tratamiento, aunque la promo del mes cambie.
- **Paquete** (preguntas 14-16): criolipólisis "15 zonas por Bs 900, pago completo al
  contratar"; se consume a lo largo de varias sesiones.

Usuario: staff del CRM (lo contrata/registra en clínica). Éxito: cada cita puede
cobrar el precio correcto (congelado) y, en paquetes, saber cuántas sesiones quedan.

## Tech Stack / Commands / Estructura

Igual que el resto: Krayin/Laravel, MySQL `od_`, paquete `Webkul/Product`
(es un concepto de catálogo/venta). Migraciones idempotentes; sin PHP local →
esquema aplicado al dev de Railway vía node.

## Data Model (propuesta Fase 1 — storage)

Tabla única **`patient_treatment_plans`** (cubre ambos casos):

| col | tipo | nota |
|---|---|---|
| id | bigint PK | |
| person_id | FK persons (cascade) | paciente |
| product_id | FK products (set null) | servicio |
| service_zone_id | FK service_zones (set null, nullable) | zona, si aplica |
| promotion_id | FK promotions (set null, nullable) | promo que tomó (trazabilidad) |
| type | enum: `per_session` \| `package` | |
| locked_price | decimal(12,4) | precio congelado (por sesión, o total del paquete) |
| total_sessions | int nullable | paquete: sesiones/zonas incluidas (ej. 15) |
| used_sessions | int default 0 | consumidas |
| paid_upfront | bool default false | paquete criolipólisis = true |
| amount_paid | decimal nullable | lo pagado al contratar |
| valid_from | date nullable | |
| valid_until | date nullable | el "plazo" (definición Tessa) |
| status | enum: `active` \| `completed` \| `expired` | |
| timestamps | | |

Relaciones: `Person::treatmentPlans()`; plan → belongsTo person/product/zone/promotion.
(Opcional Fase 2: `activities.treatment_plan_id` para ligar cada cita al plan y
descontar sesiones al completarla.)

## Code Style / Testing

Patrón Concord (contract+proxy+modelo+registro), igual que `Promotion`/`ServiceZone`.
Pest en Fase 2 (sin PHP local ahora); se valida esquema en la BD.

## Boundaries
- **Always:** snapshot del precio al contratar (no recalcular); FKs nullable; idempotente.
- **Ask first:** regla de vigencia/plazo; si el consumo se descuenta al agendar o al
  completar; si una paciente puede tener varios planes activos del mismo servicio.
- **Never:** exponer esto por la API del agente; recalcular el precio congelado.

## Success Criteria (Fase 1)
1. `migrate` crea `patient_treatment_plans` (idempotente).
2. Modelo registrado + `Person::treatmentPlans()`.
3. Se puede crear un plan `per_session` (locked_price) y uno `package`
   (total_sessions/used_sessions/paid_upfront) vía Eloquent.
4. No se expone por la API del agente.

## Open Questions (⛔ varias BLOQUEADAS por Tessa — el brief las marca pendientes)
1. **Plazo del precio congelado / vigencia del paquete** → brief: *"pendiente"*.
2. Paquete criolipólisis: ¿cuenta **zonas** o **sesiones**? ("15 zonas").
3. ¿El consumo (`used_sessions`) se descuenta al **agendar** o al **completar** la cita?
4. ¿Una paciente puede tener **varios planes activos** del mismo servicio?
5. ¿Se registran **pagos** por sesión (QR/efectivo) o solo el precio? (hoy no hay pagos).
