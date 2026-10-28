# Actualización para el equipo del Agente IA — CRM Tessa listo

> Resumen de lo que el CRM (`08.tessa`) ya dejó listo y **validado en producción**
> (`tessa.sofopolis.com`), qué debe **ocupar** el agente y **para qué**.
> Detalle completo: `docs/handoff-agente-ia-tessa.md`.

---

## 1. Qué se hizo en el CRM (todo validado en prod)

- **Agenda única**: un solo recurso agendable ("Agenda Tessa", `doctor_id = 1`).
  Disponibilidad **por duración real** del servicio y **citas seguidas** (sin solape),
  horarios L–V 8–19 / Sáb 8:30–12:30 / **domingo cerrado**.
- **Catálogo reestructurado** en 3 niveles: **especialidad (línea) → servicio → zona**.
  La **duración y el precio viven en la ZONA**. Cada servicio trae además
  `description`, `frequency_text`, `min_interval_days` y `categories[]` (Facial/Corporal/Depilación).
- **Promociones con vigencia**: el precio vigente (`current_price`) ya viene con la
  promo aplicada (ej.: Axilas base Bs 70 → vigente Bs 50 en octubre).
- **Estados de cita** (scheduled/confirmed/completed/no_show/cancelled/rescheduled).
- **No-show a +2 h** y **recordatorio 1 día antes**: los manda el **CRM** (no el agente).
- **Todo por texto**: los WhatsApp Flows están **apagados** (el CRM los rechaza).
- **UI de administración**: Tessa ya puede cargar/editar servicios, zonas y promos desde el panel.

---

## 2. Qué debe ocupar el agente (endpoints concretos)

Base: `https://tessa.sofopolis.com`

| Para | Endpoint | Auth |
|---|---|---|
| **Catálogo** (servicios + zonas + precio vigente) | `GET /api/public/products` | sin auth |
| **Disponibilidad** | `GET /api/doctors/1/available-slots?date=YYYY-MM-DD&days=7&duration_minutes=<de la zona>` | Bearer Sanctum |
| **Crear cita** | `POST /api/v1/activities` | Bearer Sanctum |
| **Paciente** | `POST /api/v1/persons` · `GET /api/v1/leads/search` | Bearer Sanctum |
| **WhatsApp** (enviar texto / derivar a humano) | `POST /api/v1/whatsapp/conversations/{id}/messages` · `.../handoff` | Bearer Sanctum |

**Catálogo** `GET /api/public/products` → por servicio:
```
{ id, sku, name, description, specialty, categories[], frequency_text,
  min_interval_days,
  zones: [ { id, name, duration_minutes, price, current_price } ] }
```
Params: `?q=` (buscar), `?branch_id=` (promo por sucursal). `current_price` ya trae la promo.

**Crear cita** `POST /api/v1/activities`:
```
{ "type":"meeting", "title":"Depilación láser — Axilas",
  "schedule_from":"2026-10-29 09:00:00", "schedule_to":"2026-10-29 09:30:00",
  "product_id": <servicio>, "participants": { "doctors":[1], "persons":[<id>] } }
```
`schedule_to` = `schedule_from` + la **duración de la zona** elegida.

---

## 3. Para qué / cómo se usa (el flujo)

1. **Informar**: servicio → `description` (de qué trata) + `frequency_text` (cada cuánto).
2. **Elegir zona**: la paciente elige una zona → de ahí salen `duration_minutes` y `current_price`.
3. **Precio**: informar `current_price` (ya con promo). **No calcular descuentos** en el agente.
4. **Disponibilidad**: `available-slots` con `duration_minutes` = el de la zona → ofrecer horarios.
5. **Agendar**: crear la paciente si no existe → `POST /api/v1/activities` (doctor 1, product_id, from/to).
6. **Confirmar**: detalle + recordar **rasurada** (depilación) y **parqueo exclusivo**.
7. **Reprogramar**: respetar `min_interval_days` (intervalo mínimo entre sesiones).

---

## 4. Qué el agente NO hace (lo maneja el CRM o está apagado)

- **Flows / formularios** → apagados, todo por texto.
- **Seguros, SMD/ShareMeData, cálculo de horarios** → no aplican.
- **No-show (+2 h) y recordatorios** → los manda el CRM, no el agente.

---

## 5. Todavía NO existe (que el agente no lo asuma)

Bloqueado esperando definiciones/entregables de Tessa:
- **Precio congelado por paciente** y **paquetes** (criolipólisis) — no implementados aún.
- **Lista real de precios/promos** (hoy mock) y **duración real de depilación por zona**.
- **Cancelar/reprogramar por texto**: la maquinaria de estados está, pero el **disparador**
  desde el agente se conecta cuando el agente lo llame (coordinar endpoint).

---

## 6. Referencia

- Handoff completo (quitar/cambiar/agregar vs `01.odontoking`, API, flujo, env, checklist):
  `docs/handoff-agente-ia-tessa.md`.
- Brief y guion de conversación: `brief/`.
