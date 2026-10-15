# Guía de integración - Agente IA / Disponibilidad y agendamiento

Cómo debe consumir el agente IA el CRM Odontoking para **consultar horarios disponibles**
y **agendar citas**. La disponibilidad ya viene calculada por el CRM
(`SMD ∩ jornada local − citas`); el agente **no** debe hablar con ShareMeData directamente.

---

## 1. Configuración

| Variable | Valor |
|----------|-------|
| `ODONTOKING_API_URL`   | `https://odontoking.sofopolis.com` |
| `ODONTOKING_API_TOKEN` | token Sanctum |

Headers en **todas** las llamadas:

```http
Authorization: Bearer {ODONTOKING_API_TOKEN}
Accept: application/json
```

> ❌ No uses `/api/horarios` ni `/api/disponibilidad` (deprecados) ni
> `/api/doctors/{id}/slots` (solo datos locales, sin SMD). Para disponibilidad usá
> **únicamente** `/api/doctors/{id}/available-slots`.

---

## 2. Flujo recomendado

```
1) GET /api/doctors                      → encontrar al doctor (id) y sus especialidades
2) GET /api/doctors/{id}/available-slots → horarios reales para ofrecer al paciente
3) (resolver paciente: ya lo hace crm.py - GET/POST /api/v1/persons|contacts)
4) POST /api/v1/activities               → agendar la cita elegida
```

---

## 3. Consultar disponibilidad

```
GET /api/doctors/{id}/available-slots
```

### Parámetros (query)

| Param              | Req | Tipo    | Default | Descripción |
|--------------------|-----|---------|---------|-------------|
| `date`             | sí  | `Y-m-d` | -       | Fecha de inicio (entre hoy y hoy+6 meses) |
| `days`             | no  | int     | 7       | Días a consultar desde `date` (1–30) |
| `duration_minutes` | no  | int     | -       | **Duración real de la consulta.** Corta los bloques libres en slots de esa medida (15–480) |
| `specialty`        | no  | string  | todas   | Filtra por una especialidad puntual |
| `subsidiary`       | no  | string  | `Santa Cruz` | Sucursal |

> ⚠️ **`duration_minutes` es clave.** Pasá la duración real del servicio a agendar.
> Si la consulta es de 30 min usá `duration_minutes=30`; si es de 60, `=60`.
> Con 60 min no se ofrecen tramos donde no caben 60 min seguidos (correcto: ahí no
> se puede agendar). Sin el parámetro, devuelve los bloques contiguos completos sin cortar.

### Ejemplo

```bash
curl -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
  "$ODONTOKING_API_URL/api/doctors/12/available-slots?date=2026-06-29&days=1&duration_minutes=30"
```

### Respuesta

```json
{
  "doctor_id": 12,
  "from": "2026-06-29",
  "days": 1,
  "source": "smd",
  "degraded": false,
  "reason": null,
  "schedule": [
    {
      "date": "2026-06-29",
      "slots": [
        { "start_time": "08:00", "end_time": "08:30", "status": "available" },
        { "start_time": "14:30", "end_time": "15:00", "status": "available" }
      ]
    }
  ]
}
```

### Cómo interpretar la respuesta

| Campo | Qué significa para el agente |
|-------|------------------------------|
| `schedule[].slots` | Horarios **ofrecibles** ese día (`start_time`/`end_time` en `H:i`, hora local). |
| `slots: []` | Ese día no hay disponibilidad (o la clínica no atiende). Ofrecer otra fecha. |
| `source: "smd"` | Disponibilidad real (SMD + jornada local + citas). Confiable para agendar. |
| `source: "local"` + `degraded: true` | SMD no disponible; datos solo locales. Ver `reason`. |

**Valores de `reason` (solo cuando `degraded=true`):**

| `reason` | Significado | Acción sugerida del agente |
|----------|-------------|-----------------------------|
| `doctor_unlinked` | El doctor no está vinculado a SMD | Ofrecer con cautela; avisar a soporte |
| `smd_unavailable` | SMD caído / timeout | Reintentar más tarde; los slots son aproximados |
| `smd_disabled` | Integración SMD apagada | Operación 100% local |

> Si `degraded=true`, los horarios provienen solo de la jornada local y pueden no
> reflejar ocupación real en SMD. Igual se puede agendar (el POST revalida), pero
> conviene loguearlo.

---

## 4. Agendar la cita

```
POST /api/v1/activities
```

El doctor y el paciente van dentro de `participants`. El backend revalida jornada,
disponibilidad SMD y conflictos antes de crear.

### Body (JSON)

```json
{
  "type": "meeting",
  "schedule_from": "2026-06-29 08:00:00",
  "schedule_to":   "2026-06-29 08:30:00",
  "title": "Consulta Ortodoncia - Juan Pérez",
  "comment": "Primera consulta",
  "product_id": 15,
  "participants": {
    "doctors": [12],
    "persons": [3456]
  }
}
```

| Campo | Req | Notas |
|-------|-----|-------|
| `type` | sí | Siempre `"meeting"` para citas |
| `schedule_from` / `schedule_to` | sí | `Y-m-d H:i:s` hora local. Deben caer dentro de un slot disponible |
| `participants.doctors[0]` | sí | `id` del doctor |
| `participants.persons[0]` | sí | `id` del paciente (person) |
| `product_id` | no | Servicio/procedimiento; si se envía debe existir |
| `title` | no | Título de la cita |
| `comment` | no | Motivo/nota |
| `lead_id` | no | Reutiliza un lead existente |

### Respuestas

| HTTP | Caso | Body |
|------|------|------|
| 200 | Cita creada | `{ "data": {...}, "message": "..." }` |
| 422 | No se pudo agendar | `{ "message": "...", "details": {...} }` |

Mensajes `422` típicos (mostrar/reaccionar):
- `El doctor ya tiene una cita programada en este horario...` → ofrecer otro slot
- `El horario seleccionado está fuera de la jornada laboral...` → elegir dentro de la jornada
- `El doctor no tiene disponibilidad en SHAREMEDATA...` → refrescar disponibilidad y reintentar

> El POST **revalida** todo: aunque el slot venía de `available-slots`, si entre la
> consulta y el agendamiento alguien tomó el horario, devuelve `422`. El agente debe
> manejar ese caso volviendo a pedir disponibilidad.

---

## 5. Ejemplo en Python (`requests`)

```python
import os, requests

BASE  = os.environ["ODONTOKING_API_URL"]
TOKEN = os.environ["ODONTOKING_API_TOKEN"]
H = {"Authorization": f"Bearer {TOKEN}", "Accept": "application/json"}

def get_available_slots(doctor_id: int, date: str, *, days: int = 7,
                        duration_minutes: int | None = None, specialty: str | None = None):
    params = {"date": date, "days": days}
    if duration_minutes:
        params["duration_minutes"] = duration_minutes
    if specialty:
        params["specialty"] = specialty
    r = requests.get(f"{BASE}/api/doctors/{doctor_id}/available-slots",
                     headers=H, params=params, timeout=20)
    r.raise_for_status()
    return r.json()

def book_appointment(doctor_id: int, person_id: int, start: str, end: str,
                     *, title=None, comment=None, product_id=None):
    payload = {
        "type": "meeting",
        "schedule_from": start,            # "Y-m-d H:i:s"
        "schedule_to":   end,
        "participants":  {"doctors": [doctor_id], "persons": [person_id]},
    }
    if title:      payload["title"]      = title
    if comment:    payload["comment"]    = comment
    if product_id: payload["product_id"] = product_id

    r = requests.post(f"{BASE}/api/v1/activities", headers=H, json=payload, timeout=30)
    if r.status_code == 422:
        return {"ok": False, "error": r.json().get("message"), "details": r.json().get("details")}
    r.raise_for_status()
    return {"ok": True, "data": r.json()}

# --- Flujo típico ---
avail = get_available_slots(12, "2026-06-29", days=1, duration_minutes=30)
dia   = avail["schedule"][0]
if not dia["slots"]:
    print("Sin disponibilidad ese día")
else:
    slot = dia["slots"][0]                                   # primer horario libre
    start = f'{dia["date"]} {slot["start_time"]}:00'
    end   = f'{dia["date"]} {slot["end_time"]}:00'
    res = book_appointment(12, 3456, start, end, title="Consulta", product_id=15)
    if not res["ok"]:
        # el horario se ocupó: refrescar disponibilidad y reintentar con otro slot
        print("No se pudo agendar:", res["error"])
```

---

## 6. Reglas para el agente (resumen)

1. **Disponibilidad** → solo `GET /api/doctors/{id}/available-slots`.
2. Pasar **`duration_minutes`** = duración real del servicio.
3. Ofrecer únicamente los `slots` devueltos; `slots: []` ⇒ proponer otra fecha.
4. **Agendar** con `POST /api/v1/activities`, doctor y paciente en `participants`.
5. Manejar `422` al agendar: re-consultar disponibilidad y reintentar.
6. Loguear `degraded=true` / `reason` para visibilidad operativa.

---

## 7. Citas para otra persona (hijos, familiares): formulario de WhatsApp

Quien escribe no siempre es el paciente. Para una cita **para otra persona** el agente
**no** crea el paciente con `POST /api/v1/persons`: le envía al usuario un **formulario de
WhatsApp (Flow)** que pide nombre, **cédula (obligatoria)**, fecha de nacimiento y
relación, y el CRM identifica al paciente **por la cédula**. Así, si la mamá agenda el
lunes y el papá el jueves desde otro número, los dos llegan a la **misma** ficha.

> Solo funciona por **Cloud API** (no por Kommo) y con la ventana de 24 h abierta.

### 7.1 Antes de preguntar, mirar el contexto

En cada webhook `message.received`, `patient_context.pacientes_a_cargo` trae a quienes
este contacto ya agendó:

```json
"pacientes_a_cargo": [
  {"person_id": 4521, "nombre": "Juan Pérez López", "relacion": "hijo"}
]
```

Si la cita es para uno de ellos, agendar directo con su `person_id`. Sus citas ya vienen
en `patient_context.citas` (campo `paciente`).

### 7.2 Enviar el formulario

```
POST /api/v1/whatsapp/conversations/{conversation_id}/flows
Authorization: Bearer <token>
{"flow": "paciente_tercero", "text": "Para agendar, completá los datos del paciente 👇"}
```

`text` es opcional. Respuestas: `200` (encolado), `422` si la conversación está fuera de
la ventana de 24 h o el canal no soporta formularios.

### 7.3 Qué hace el formulario

El formulario no solo identifica al paciente: **también agenda**. Pantallas:
¿Para quién? → (datos del tercero, con cédula) → Especialidad → Doctor (recomendados,
ordenados por el horario más próximo) → Día → Hora → Confirmar.

Solo ofrece especialidades y doctores con **horarios realmente libres** (catálogo
`whatsapp:booking-catalog`, recalculado cada 10 min desde SMD). Turnos de **1 hora en punto**, hasta **fin del mes siguiente**, y la opción "No sé / Consulta
general". Al confirmar, la cita se crea en segundo plano igual que con
`POST /api/v1/activities` (SMD + CRM) y el recordatorio le llega a **quien agendó**.

### 7.4 Cuando el usuario lo completa

Llega un `message.received` con `message.type = "flow_reply"` y los datos en `message.flow`:

```json
"message": {
  "type": "flow_reply",
  "text": "📅 Pidió cita: Juan Pérez López · CAROLINA ROJAS · 2026-10-13 09:00 (confirmando)",
  "flow": {
    "flow": "paciente_tercero",
    "status": "pending",
    "patient_status": "created",
    "patient_id": 4521,
    "patient_name": "Juan Pérez López",
    "relation": "hijo",
    "specialty": "Endodoncia",
    "doctor_id": 10,
    "doctor_name": "CAROLINA ROJAS",
    "date": "2026-10-13",
    "time": "09:00"
  }
}
```

| `status` | Qué hacer |
|---|---|
| `pending` | La cita **se está creando** (segundo plano, segundos). El CRM le manda solo al usuario "✅ Cita confirmada…" o, si el horario se ocupó, que elija otro. El agente **no** agenda ni confirma por su cuenta. |
| `handoff` | No había horarios reservables por el formulario: derivar a recepción (`/handoff`). |

`patient_status` dice cómo se identificó al paciente: `created` (nuevo), `found` (ya existía)
o `self` (quien escribe). `relation` (y `relacion` en `pacientes_a_cargo`) dice **qué es el
paciente para quien escribe**: `hijo`, `padre_madre`, `pareja`, `hermano`, `familiar` u `otro`.

### 7.5 Reglas

- Nunca crear a un tercero con `POST /api/v1/persons`: siempre por el formulario.
- Si el formulario devolvió `pending`, la cita ya está en curso: no llamar a `POST /api/v1/activities`.
- No pedir la cédula por chat para un tercero: la pide y valida el formulario.
- Si el usuario no puede dar la cédula del paciente, derivar a recepción (`/handoff`).

---

## 8. Cancelar y reprogramar citas: formularios de WhatsApp

Igual que para agendar (§7), cancelar y reprogramar se hacen con **formularios de WhatsApp**,
no por chat. El agente detecta la intención y envía el formulario; el CRM muestra solo las
**próximas citas del contacto y de los pacientes que tiene a cargo**, aplica el cambio en el
CRM **y en SMD**, y le confirma al usuario por el chat.

### 8.1 Cuándo enviar cada formulario

| El usuario quiere… | `flow` |
|---|---|
| Agendar (para sí o para otra persona) | `paciente_tercero` |
| Cancelar una cita | `cancelar_cita` |
| Cambiar el día u hora de una cita | `reprogramar_cita` |

```
POST /api/v1/whatsapp/conversations/{conversation_id}/flows
Authorization: Bearer <token>
{"flow": "cancelar_cita"}            // o "reprogramar_cita"; "text" opcional
```

Mismas reglas que §7.2: solo con la ventana de 24 h abierta (`422` si no) y solo por Cloud API.

### 8.2 Qué le llega al agente

Un `message.received` con `message.type = "flow_reply"` y los datos en `message.flow`.

**Cancelar** (`flow: "cancelar_cita"`):

```json
{"flow": "cancelar_cita", "status": "pending", "activity_id": 77587, "patient_id": 6021,
 "patient_name": "Heidy Dayana Mogro Peñafiel", "doctor_name": "CAROLINA ROJAS",
 "date": "2026-10-15", "time": "10:00", "reason": "No puedo asistir"}
```

**Reprogramar** (`flow: "reprogramar_cita"`):

```json
{"flow": "reprogramar_cita", "status": "pending", "activity_id": 77587, "patient_id": 6021,
 "patient_name": "Heidy Dayana Mogro Peñafiel", "doctor_name": "CAROLINA ROJAS",
 "previous_date": "2026-10-15", "previous_time": "10:00", "date": "2026-10-16", "time": "11:00"}
```

| `status` | Qué hacer |
|---|---|
| `pending` | El cambio **se está aplicando** (segundos). El CRM le manda solo al usuario "✅ Cita cancelada" / "✅ Cita reprogramada", o el aviso de que el horario se ocupó (la cita queda como estaba). El agente **no** repite la acción. |
| `none`    | El usuario no tenía citas próximas. Ofrecer agendar (`paciente_tercero`). |
| `handoff` | (solo reprogramar) No hay horarios libres con ese doctor: derivar a recepción (`/handoff`). |

### 8.3 Reglas

- No cancelar ni reprogramar por API ni por chat: siempre con el formulario.
- Reprogramar mantiene el **mismo doctor**, con turnos de **1 hora en punto** hasta fin del
  mes siguiente. Si el usuario quiere **otro doctor**, se cancela y se agenda de nuevo.
- Las citas que se pueden tocar son las **futuras** y vigentes (no canceladas, atendidas ni
  perdidas) del contacto y de sus pacientes a cargo. La de otra persona no aparece ni se acepta.
- Si el usuario dice que no le aparece una cita que sí tiene, derivar a recepción.
