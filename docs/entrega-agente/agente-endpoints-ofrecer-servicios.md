# Agente IA · Endpoints para ofrecer servicios (CRM Tessa)

> **Para:** equipo del Agente IA.
> **Qué es:** todo lo que el agente necesita para **ofrecer servicios, precios y
> promociones**, **consultar disponibilidad** y **agendar** según el catálogo
> nuevo de Tessa.
> **Estado:** validado en **producción** el 2026-11-04. Todos los ejemplos de este
> documento son respuestas **reales** del servidor.
> Colección Postman: [`tessa-agente.postman_collection.json`](tessa-agente.postman_collection.json)
> · entorno: [`tessa.postman_environment.json`](tessa.postman_environment.json).

---

## 0. Qué cambió (lo nuevo desarrollado)

- **Catálogo en 3 niveles**: **línea (especialidad) → servicio → zona**. El
  **precio y la duración viven en la ZONA**, no en el servicio.
- **HIFU** con precios reales (5 zonas faciales) + nuevo servicio **HIFU Corporal**
  (4 zonas, paquete de 3 sesiones a Bs 1.090).
- **Promociones con vigencia por sucursal**: el campo `current_price` ya trae la
  promo aplicada. **Las promos de noviembre están acotadas a Santa Cruz** → hay
  que pedir el catálogo con `?branch_id=1` (ver §3).
- Descripciones y frecuencias actualizadas (HIFU 25D PRO MAX, ALMA Soprano, Dermapen).

> ⚠️ Documentos previos (`handoff-agente-ia-tessa.md`, `agente-tessa-update.md`)
> muestran el catálogo viejo (zonas mock "Rostro 900 / Rostro y cuello 1300").
> **Este documento reemplaza esos ejemplos de catálogo.**

---

## 1. Base URL y autenticación

| | Valor |
|---|---|
| **Base URL** | `https://tessa.sofopolis.com` (alias de `https://tessa-crm-production.up.railway.app`) |
| **Auth** | **Bearer Sanctum** — `Authorization: Bearer <token>` |
| **Token** | de un usuario dedicado del CRM, se obtiene una vez con `POST /api/v1/login` |

- Endpoints **públicos** (sin token): catálogo, especialidades, doctores,
  `GET /api/doctors/{id}/slots` y `GET /api/doctors/{id}/availability/{y}/{m}`.
- Endpoints **con token** (Bearer): `available-slots`, pacientes, citas y WhatsApp.

---

## 2. Resumen de endpoints

| # | Para | Método y ruta | Auth |
|---|---|---|---|
| 1 | **Catálogo** (servicios + zonas + precio vigente) | `GET /api/public/products?branch_id=1` | pública |
| 2 | **Disponibilidad** | `GET /api/doctors/1/available-slots?date=&days=&duration_minutes=` | Bearer |
| 2b | Disponibilidad (prueba, sin token) | `GET /api/doctors/1/slots?date=&days=&duration_minutes=` | pública |
| 3 | **Buscar paciente** | `GET /api/v1/leads/search?query=` · `GET /api/v1/contacts/persons/search?query=` | Bearer |
| 4 | **Crear paciente** | `POST /api/v1/contacts/persons` | Bearer |
| 5 | **Crear cita** | `POST /api/v1/activities` | Bearer |
| 6 | **WhatsApp: historial** | `GET /api/v1/whatsapp/conversations/{id}/messages` | Bearer |
| 7 | **WhatsApp: responder** | `POST /api/v1/whatsapp/conversations/{id}/messages` | Bearer |
| 8 | **WhatsApp: derivar a humano** | `POST /api/v1/whatsapp/conversations/{id}/handoff` | Bearer |

> La agenda de Tessa es **única**: siempre `doctor_id = 1` ("Agenda Tessa").
> El agente no elige doctor ni especialidad-doctor.

---

## 3. Catálogo — `GET /api/public/products`

Devuelve los servicios con sus zonas y el **precio vigente** (`current_price`).

**Query params (todos opcionales):**

| Param | Ejemplo | Para qué |
|---|---|---|
| `branch_id` | `1` | **Aplica las promos de esa sucursal.** Tessa SC = `1`. **Usalo siempre** para cotizar el precio correcto. |
| `q` | `HIFU` | Filtra por nombre o SKU del servicio. |

**Ejemplo real** (`GET /api/public/products?branch_id=1&q=HIFU`):

```json
{
  "data": [
    {
      "id": 508,
      "sku": "HIFU",
      "name": "HIFU",
      "specialty": "Faciales de rejuvenecimiento",
      "categories": ["Facial"],
      "description": "Tecnología HIFU 25D PRO MAX 360°: combina ultrasonido focalizado, radiofrecuencia y estimulación muscular...",
      "frequency_text": "Según la edad, la calidad de la piel y el grado de flacidez: puede realizarse cada 3, 4, 6 meses o una vez al año...",
      "min_interval_days": 90,
      "recommended_sessions_min": null,
      "recommended_sessions_max": null,
      "price": "120,00 BOB",
      "duration_minutes": null,
      "zones": [
        { "id": 20, "name": "Rostro completo",                   "duration_minutes": 60, "price": "1500.0000", "current_price": 1500 },
        { "id": 21, "name": "Rostro completo + papada",          "duration_minutes": 75, "price": "1800.0000", "current_price": 1800 },
        { "id": 22, "name": "Rostro completo + papada + cuello", "duration_minutes": 90, "price": "2190.0000", "current_price": 2190 },
        { "id": 19, "name": "Óvalo facial + mandíbula + papada", "duration_minutes": 60, "price": "900.0000",  "current_price": 900 },
        { "id": 23, "name": "Papada + cuello",                   "duration_minutes": 45, "price": "800.0000",  "current_price": 800 }
      ]
    },
    {
      "id": 515,
      "sku": "HIFU-CORPORAL",
      "name": "HIFU Corporal",
      "specialty": "Reducción de grasa localizada y modelado",
      "categories": ["Corporal"],
      "frequency_text": "Protocolo de 3 sesiones, cada una con un intervalo de 30 a 40 días.",
      "zones": [
        { "id": 24, "name": "Papada y región submentoniana",     "duration_minutes": 60, "current_price": 1090 },
        { "id": 25, "name": "Posterior de brazos + zona subaxilar","duration_minutes": 60, "current_price": 1090 },
        { "id": 26, "name": "Abdomen bajo",                        "duration_minutes": 60, "current_price": 1090 },
        { "id": 27, "name": "Rollitos de la cintura",             "duration_minutes": 60, "current_price": 1090 }
      ]
    }
  ]
}
```

### Reglas al cotizar (importante)

1. **El precio a decir al paciente es `zones[].current_price`** (ya trae la promo).
   **Ignorá** el `price` del servicio (nivel producto): es un valor heredado, no el real.
2. **Pasá `?branch_id=1`** o cotizarás el precio base sin promo. Ejemplo real:
   Depilación · *Axilas + Bikini* → sin branch `current_price` = **170**; con
   `branch_id=1` = **150** (promo de noviembre).
3. **La duración de la cita es `zones[].duration_minutes`** de la zona elegida; ese
   mismo valor va a disponibilidad (`duration_minutes`) y a `schedule_to`.
4. **HIFU Corporal**: el `current_price` (Bs 1.090) es el **paquete de 3 sesiones**,
   no el precio por sesión (ver `frequency_text`).
5. Campos útiles para hablar del servicio: `description`, `frequency_text`,
   `min_interval_days`, `recommended_sessions_min/max`, `specialty`, `categories`.

---

## 4. Disponibilidad — `GET /api/doctors/1/available-slots`

**Query params:**

| Param | Req | Ejemplo | Notas |
|---|---|---|---|
| `date` | sí | `2026-11-05` | `YYYY-MM-DD`, desde hoy hasta +6 meses |
| `days` | no | `7` | 1–30 (default 7) |
| `duration_minutes` | no | `60` | **La duración de la zona elegida** (15–480). Corta los bloques en slots de esa medida. |

**Ejemplo real** (`GET /api/doctors/1/slots?date=2026-11-05&days=2&duration_minutes=60`):

```json
{
  "doctor_id": 1,
  "from": "2026-11-05",
  "days": 2,
  "duration_minutes": 60,
  "schedule": [
    {
      "date": "2026-11-05",
      "slots": [
        { "start_time": "08:00", "end_time": "09:00", "status": "available" },
        { "start_time": "09:00", "end_time": "10:00", "status": "available" }
      ]
    }
  ]
}
```

- Ofrecé al paciente solo los slots con `status: "available"`.
- Si `slots: []` ese día, ofrecé otra fecha.
- Horario: L–V 8:00–19:00, Sáb 8:30–12:30, **domingo cerrado**.
- `GET /api/doctors/1/slots` es el **mismo motor sin token** (útil para pruebas).

---

## 5. Resolver paciente (Bearer)

- **Buscar**: `GET /api/v1/contacts/persons/search?query=<nombre o teléfono>`
  (o `GET /api/v1/leads/search?query=`).
- **Crear si no existe**: `POST /api/v1/contacts/persons`

```json
{ "name": "Juan Pérez", "emails": [], "contact_numbers": [{ "value": "+591700000000", "label": "work" }] }
```

Guardá el `id` del paciente (person) devuelto para la cita.

---

## 6. Crear cita — `POST /api/v1/activities` (Bearer)

```json
{
  "type": "meeting",
  "schedule_from": "2026-11-05 09:00:00",
  "schedule_to":   "2026-11-05 10:00:00",
  "title": "HIFU - Rostro completo - Juan Pérez",
  "comment": "Primera sesión",
  "product_id": 508,
  "participants": { "doctors": [1], "persons": [3456] }
}
```

| Campo | Req | Notas |
|---|---|---|
| `type` | sí | Siempre `"meeting"`. |
| `schedule_from` / `schedule_to` | sí | `Y-m-d H:i:s` hora local. **`schedule_to` = `schedule_from` + duración de la zona.** Deben caer en un slot `available`. |
| `participants.doctors[0]` | sí | Siempre `1` (Agenda Tessa). |
| `participants.persons[0]` | sí | `id` del paciente. |
| `product_id` | reco. | El **servicio** elegido (`data[].id` del catálogo). |
| `title` / `comment` | no | Texto libre. |

- `200` → cita creada.
- `422` → no se pudo agendar (slot tomado / fuera de jornada). El POST **revalida**:
  si el horario se ocupó entre la consulta y el agendamiento, volvé a pedir
  disponibilidad y ofrecé otro slot.

---

## 7. WhatsApp — responder y derivar (Bearer)

- **Historial / contexto**: `GET /api/v1/whatsapp/conversations/{id}/messages`
- **Responder** (texto): `POST /api/v1/whatsapp/conversations/{id}/messages`
  ```json
  { "text": "¡Hola! El HIFU facial (rostro completo) está Bs 1.500 este mes. ¿Querés que te agende?" }
  ```
  `422` = fuera de la ventana de 24 h de WhatsApp → derivar a recepción.
- **Derivar a humano**: `POST /api/v1/whatsapp/conversations/{id}/handoff`
  ```json
  { "motivo": "El paciente pide hablar con recepción", "fuera_de_horario": false }
  ```

> **Flows (formularios de WhatsApp) están APAGADOS en Tessa.** Todo se hace **por
> texto**: ofrecer servicios/precios, proponer horarios y confirmar la cita por chat.
> `POST .../flows` responde `422`.

---

## 8. Flujo completo para ofrecer y agendar

1. El paciente pregunta por un tratamiento → **`GET /api/public/products?branch_id=1`**
   (filtrá con `q=` si hace falta).
2. Presentá servicio + **zona** + **`current_price`** + frecuencia.
3. El paciente elige una **zona** → tomá su `duration_minutes` y `current_price`.
4. **Disponibilidad** → `GET /api/doctors/1/available-slots?date=...&duration_minutes=<zona>`;
   ofrecé horarios `available`.
5. **Paciente** → `GET /api/v1/contacts/persons/search` y, si no existe,
   `POST /api/v1/contacts/persons`.
6. **Agendá** → `POST /api/v1/activities` con `doctors:[1]`, `persons:[id]`,
   `product_id`, `schedule_from/to` (= duración de la zona).
7. Confirmá por chat con `POST .../messages`.

---

## 9. Reglas de negocio clave (resumen)

- Precio y duración **viven en la zona**; cotizá `current_price`, no el `price` del servicio.
- **Siempre `branch_id=1`** para precios con promo (Santa Cruz).
- Agenda **única**: `doctor_id = 1`.
- **Todo por texto** (Flows apagados).
- Pagos por sesión (QR/efectivo). **Tessa no maneja seguros.**
- No-show (+2 h) y recordatorio (1 día antes) los manda **el CRM**, no el agente.

---

## 10. Referencias

- Colección Postman lista para importar: `tessa-agente.postman_collection.json`
  (+ entorno `tessa.postman_environment.json`) — en esta misma carpeta.
- Detalle de disponibilidad/agenda: `../disponibilidad-doctores.md`.
- Handoff original (su ejemplo de catálogo ya quedó apuntando aquí): `../handoff-agente-ia-tessa.md`.
