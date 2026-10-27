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
- `<Paciente> ya tiene una cita con <Doctor> el dd/mm a las HH:MM...` con
  `details.code = "patient_already_booked"` (y `existing_activity_id`, `existing_from`) →
  **no reintentar con otro horario**: el paciente ya tiene cita ese día con ese doctor.
  Avisarle y, si quiere otro horario, reprogramar esa cita en vez de crear otra.

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
5. Manejar `422` al agendar: re-consultar disponibilidad y reintentar, salvo
   `details.code = "patient_already_booked"` (ya tiene cita ese día con ese doctor:
   avisar, no reintentar).
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

#### Pre-llenado (`prefill`, solo `paciente_tercero`)

Lo que el paciente **ya dijo en el chat** se puede mandar para que el formulario
llegue con esos valores elegidos (el paciente puede cambiarlos):

```json
{ "flow": "paciente_tercero",
  "prefill": { "relation": "tercero", "patient_name": "María Pérez",
               "relationship": "hijo", "specialty": "Ortodoncia" } }
```

| Clave | Valores | Efecto |
|---|---|---|
| `relation` | `self` (también `para_mi`, `yo`) · `tercero` (también `other`, `otro`) | `self` → "Para mí". `tercero` → el familiar a cargo con ese nombre si ya existe; si no, "Otra persona" |
| `patient_name` | texto libre | Con `tercero`: elige al familiar a cargo con ese nombre o llena "Nombre completo" |
| `relationship` | `hijo`, `padre_madre`, `pareja`, `hermano`, `familiar`, `otro` | Parentesco del tercero nuevo |
| `specialty` | id de especialidad del CRM, su nombre (sin importar tildes ni mayúsculas) o `general` / `No sé` | Especialidad elegida al llegar a esa pantalla |
| `doctor` | **id del doctor en el CRM** (recomendado) o su nombre (`"Dr. Eloy"`) | Doctor elegido, si tiene horarios en esa especialidad. Sin `specialty`, se elige la especialidad de ese doctor. Por nombre debe coincidir con **un solo** doctor |
| `date` | `YYYY-MM-DD` | El día, si tiene horarios; si no, el siguiente día con horarios; si está más allá de la lista de días, "Otra fecha" con ese día ya puesto |
| `time` | `HH:MM` (24 h; acepta `H:MM`) | La hora, si está libre; si no, la hora libre más cercana |

**Turno sugerido (R9):** después de elegir el doctor, el formulario muestra una pantalla
"Turno sugerido" con **el turno libre más próximo** de ese doctor ya marcado (o el pedido con
`date`/`time`, si está libre: "El turno que pediste con…"). Si le sirve, **Continuar** va
directo al resumen; si no, "Quiero otra fecha y hora" sigue con la elección normal de día y
hora (sin volver a marcar la sugerencia). Pasa con o sin `prefill`. Todo es una
**sugerencia** sobre la agenda real: nunca se ofrece un horario que no esté libre.

**Formulario ya avanzado:** con paciente conocido (`self`, o un familiar a cargo por
`patient_name`) el formulario **no empieza en "¿Para quién?"**: abre en el primer paso que
falta decidir:

| Lo que se mandó | Abre en |
|---|---|
| paciente + especialidad (o doctor) + doctor + `date` + `time`, y el turno está libre | **"Revisá tu cita"** — solo falta confirmar |
| ídem, pero el turno pedido no está libre, o sin `time` | **"Turno sugerido"** (el más cercano) |
| paciente + especialidad, sin doctor | la lista de **doctores** |
| paciente, sin especialidad ni doctor | **especialidad** |
| tercero nuevo (sin ficha), o paciente sin cédula cargada | el principio (hay que pedirle los datos) |

Mandar `doctor` como **id** y `date`/`time` exactos es lo que permite abrir directo en
"Revisá tu cita".

- Un valor que falta, sobra o no coincide con una opción **se ignora** y el formulario abre
  igual. Nunca da `422`.
- La respuesta trae `prefill` con lo que se tomó, ya normalizado (`{}` si nada).
- En `cancelar_cita` y `reprogramar_cita` el `prefill` se ignora.
- Los servicios (`limpieza`, `brackets`…) no son especialidades: mapearlos a una del
  catálogo antes de mandarlos, o no mandar `specialty`.
- Mandar solo datos **ciertos** del chat, nunca inventados.

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

**Lead de la cita.** Si la cita es **para quien escribe** (`patient_status = "self"`) y tiene
un lead abierto en **Consultas** (el "Consulta - …" que crea el agente), la cita se agenda
**sobre ese lead**: no se crea otro, y el lead pasa a "Cita confirmada". Para un tercero, la
cita lleva su propio lead, a nombre del paciente, y el lead "Consulta" de quien escribe no se
toca. Un lead derivado a recepción, o uno cerrado, nunca se reutiliza.
Consecuencia para el agente: después de una cita "para mí", ese paciente **ya no tiene lead
en Consultas**. Si en una conversación posterior hace falta un lead-conversación (por
ejemplo, para derivar a recepción), hay que crear uno nuevo y no mover el lead de la cita.

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

## 9. Respuesta al recordatorio: confirma / no asiste

El día antes de la cita el CRM le manda al paciente (o a **quien agendó**) el recordatorio por
plantilla, que termina en *"Responda SÍ para confirmar o NO para cancelar su cita"*. La respuesta
llega al agente como un `message.received` común. Para saber **a qué cita** contesta, el
`patient_context` trae `recordatorios`:

```json
"recordatorios": [
  {"activity_id": 77587, "lead_id": 9120, "paciente": "…", "fecha": "29/10/2026", "hora": "09:00",
   "doctor": "CAROLINA ROJAS", "enviado_at": "2026-10-28T08:00:03-04:00", "respuesta": null}
]
```

Son los recordatorios enviados a **esta conversación** de citas que todavía no pasaron, el más
reciente primero. `respuesta` es `null` (sin contestar), `"confirma"` o `"no_asiste"`. En el
`history` el recordatorio aparece como `{"role": "assistant", "type": "template", "content": "…"}`.

### 9.1 Registrar la respuesta

```
POST /api/v1/whatsapp/conversations/{conversation_id}/appointments/respond
Authorization: Bearer <token>
{"respuesta": "confirma"}                       // o "no_asiste"
{"respuesta": "confirma", "activity_id": 77587} // cita puntual
{"respuesta": "confirma", "lead_id": 9120}      // también sirve el lead de patient_context.citas
```

- **Sin `activity_id` ni `lead_id`**: vale solo si hay **un** recordatorio sin contestar. Con varios
  (p. ej. "confirmo ambas citas") responde `422` con la lista en `recordatorios`: llamar **una vez por
  cita** con su `activity_id`.
- La cita tiene que ser futura y del contacto, de sus pacientes a cargo, o una que se le recordó a
  esta conversación. Si no, `422`.

| `respuesta` | Qué hace el CRM |
|---|---|
| `confirma`  | La cita pasa a **Cita confirmada** y se envía a SMD con estado `CONFIRMED` (lo que ve recepción). Nota en el lead de la cita. |
| `no_asiste` | **No** cancela ni mueve la cita. La anota (nota en el lead) para que recepción llame. |

Respuesta `200`:

```json
{"respuesta": "confirma",
 "cita": {"activity_id": 77587, "lead_id": 9120, "paciente": "…", "fecha": "29/10/2026", "hora": "09:00", "doctor": "CAROLINA ROJAS"},
 "smd": {"synced": true, "reason": null}}
```

`smd.synced = false` no es un error para el paciente: la confirmación quedó en el CRM.

### 9.2 Reglas

- "Sí", "Confirmo", "Ahí estaré" o 👍 después de un recordatorio sin contestar → `confirma` y
  responder en una línea con fecha, hora y doctor. **No** mandar formulario ni hacer preguntas.
- "No", "No puedo ir" → `no_asiste` y ofrecer en el mismo mensaje **cambiarla**
  (`reprogramar_cita`) o **cancelarla** (`cancelar_cita`). Si eligió una, el formulario hace el
  cambio; el `no_asiste` ya quedó anotado.
- Si `recordatorios` está vacío, un "Sí" suelto no es una confirmación: leer el `history`.
