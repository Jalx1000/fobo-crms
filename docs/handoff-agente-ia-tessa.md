# Handoff — Desarrollo del Agente IA de Tessa

> **Audiencia:** equipo que desarrolla el agente IA en `02.agentes/tessa`.
> **Base:** el brief de Tessa (`08.tessa/brief/`) + lo ya implementado en el CRM
> (`08.tessa`), partiendo del agente existente `02.agentes/01.odontoking` como molde.
> **Última validación del CRM:** deploy OK en `tessa.sofopolis.com` (disponibilidad
> por duración, citas seguidas, domingos cerrado — verificado end-to-end).

---

## 1. Qué es Tessa y cómo encajan las piezas

Tessa es un **spa estético** (depilación láser, HIFU/Dermapen, criolipólisis y
corporales) en Santa Cruz y Asunción. Quiere un **asistente de WhatsApp** que
informe tratamientos/precios y **agende citas solo**.

Dos sistemas separados:

| Sistema | Repo | Rol |
|---|---|---|
| **CRM** | `08.tessa` (Krayin/Laravel) | Guarda pacientes, citas, catálogo, agenda. Expone **API**. Ya adaptado a Tessa. |
| **Agente IA** | `02.agentes/tessa` (Python/FastAPI + LangGraph) | **A construir.** Chatea en WhatsApp y **llama a la API del CRM**. |
| *Molde* | `02.agentes/01.odontoking` | Agente dental ya hecho. Se **clona y adapta**. |

El agente **conversa** con la paciente y, cuando hay que ver horarios o agendar,
**pega a los endpoints del CRM** (sección 7).

---

## 2. Punto de partida: adaptar el agente Odontoking

Clonar `01.odontoking` y aplicar estos cambios. La infraestructura (LangGraph,
LLM service con fallback, memoria mem0, gateway WhatsApp, buffer de mensajes,
sessions, Docker, evals) **se reutiliza tal cual**.

### 🔴 QUITAR (es dental, no aplica a un spa)

| Qué | Dónde (en 01.odontoking) | Por qué |
|---|---|---|
| Verificación de **seguros** | `tools/insurance.py`, endpoint `POST /api/insurance/verify`, intake de CI/seguro | Tessa no maneja seguros. Se paga por sesión (QR/efectivo). |
| **WhatsApp Flows** (formularios) | `tools/whatsapp_flows.py` (`enviar_formulario`), `WHATSAPP_FLOWS_ENABLED` | Tessa es **todo por texto**. El CRM ya **rechaza** el envío de Flows. |
| **ShareMeData (SMD)** | `SHAREMEDATA_API_URL/KEY`, lógica de disponibilidad vía SMD | Tessa usa **disponibilidad local** del CRM (no hay SMD dental). |
| Lógica **multi-doctor / especialidad→doctor** | `get_doctors`, `get_specialty_doctors`, filtro por edad | Tessa tiene **agenda única** (un solo recurso). |
| **Prompts dentales** | `prompts/odontoking*.md`, `system.md` | Se reemplazan por el guion de Tessa. |

### 🟡 CAMBIAR / ADAPTAR

| Qué | Cambio |
|---|---|
| `tools/odontoking.py` → `tools/tessa.py` | `get_services` ahora trae **servicios con sus zonas**; la disponibilidad usa `available-slots` con la **duración de la zona**; el `doctor_id` es **fijo** (la agenda única de Tessa). |
| `tools/crm.py` | Apuntar al CRM de Tessa; al crear la cita, mandar `product_id` del servicio y `schedule_to` según la **duración de la zona**. |
| **Prompt del sistema** | Reescribir con el guion de `brief/Estructura de Conversacion IA.md` (sección 6) y el tono de Tessa (sección 5.6). |
| **Variables de entorno** | `ODONTOKING_API_*` → `TESSA_API_*`; CRM y WhatsApp apuntando a Tessa (sección 10). |

### 🟢 AGREGAR (nuevo en Tessa)

- **Selección de ZONA**: servicio → la paciente elige zona → de ahí salen
  `duration_minutes` y `price`. (Ej.: HIFU → "rostro" 60 min / "rostro y cuello" 90 min.)
- **Informar precio vigente** (`current_price`, ya con promo aplicada) + `description`
  (beneficios) + `frequency_text` (cada cuánto).
- **Recordatorios de texto** en la confirmación: llegar **rasurada** (depilación) y
  **parqueo exclusivo**.
- Respetar el **intervalo mínimo** entre sesiones (`min_interval_days`) al reprogramar.

---

## 3. Modelo de datos del catálogo (lo que el agente debe entender)

Tres niveles + dos ejes de categoría:

```
ESPECIALIDAD (línea)        → Depilación láser / Faciales / Reducción de grasa
  └─ SERVICIO (product)      → Depilación láser, HIFU, Dermapen, Criolipólisis, ...
       │  description        → "Información y beneficios" (qué trata)
       │  frequency_text     → "Sesión y frecuencia" (texto para citar)
       │  min_interval_days  → 90 (HIFU) / 30 (Dermapen,crio) / 7 (corporales) / 30 (depilación)
       │  categories[]       → eje transversal: Facial / Corporal / Depilación (puede ser varias)
       └─ ZONA (service_zone) → Axilas, Bikini, Rostro, Rostro y cuello, ...
            duration_minutes  → 30 / 60 / 90 ...   ← la agenda bloquea ESTO
            price / current_price → Bs base / Bs vigente (promo aplicada)
```

Regla de oro: **la duración y el precio viven en la ZONA**, no en el servicio. El
agente debe llevar a la paciente **servicio → zona** antes de agendar.

**Catálogo real (mock de precios hasta que Tessa pase la lista):** 8 servicios, 18 zonas.
- Depilación láser: 9 zonas (Axilas 30min/Bs70, Bikini, Piernas, …)
- HIFU: Rostro 60min, Rostro y cuello 90min · Dermapen: Facial 30min
- Criolipólisis (Por zona / Paquete 15 zonas), Ultracavitación, Vacuum RF, Maderoterapia, Masoterapia (60min)

---

## 4. Agenda única (clave)

- Tessa atiende **una paciente a la vez**: hay **un solo recurso agendable** por
  sucursal. En el CRM es un "doctor" llamado **"Agenda Tessa"** (hoy `id = 1`).
- El agente **siempre** agenda contra ese `doctor_id`. No elige doctor ni especialidad-doctor.
- Las citas van **seguidas, sin solape**, con la **duración de la zona**. El CRM ya
  devuelve los slots así; el agente **no calcula horarios**, solo consulta y reserva.

---

## 5. Reglas de negocio de Tessa

### 5.1 Horarios
L–V 08:00–19:00 · Sáb 08:30–12:30 · **Domingo cerrado**. El CRM ya los aplica (un
domingo devuelve 0 slots). El agente no hardcodea horarios.

### 5.2 Duración variable
30 / 60 / 90 / 120 min según servicio+zona. El agente pasa `duration_minutes` = la
duración de la zona elegida al consultar disponibilidad y al fijar `schedule_to`.

### 5.3 Sin Flows — todo por texto
El agente agenda/cancela/reprograma **por conversación**, llamando a los endpoints.
El CRM **rechaza** cualquier intento de enviar un Flow (422).

### 5.4 No-show y recordatorios → los maneja el CRM
- Recordatorio **1 día antes**: comando programado del CRM.
- **No-show** (faltó sin avisar): el CRM envía **una sola vez** el mensaje de
  reprogramación a **+2 h** del fin de la cita. El agente **no** implementa esto.

### 5.5 Precios y promociones
El agente informa `current_price` de la zona (ya trae la promo vigente aplicada).
Las promos tienen vigencia (mes). (El "precio congelado por paciente" y los paquetes
están **pendientes** — sección 9.)

### 5.6 Trato / tono (brief pregunta 8)
- Saludo según la hora: "buen día" / "buenas tardes" / "buenas noches".
- Trato de **tú o vos**, por el **nombre**: "Buenas noches, [nombre], un gusto".
- Si no se conoce el nombre: **"señora" + apellido**.
- En la confirmación: recordar **rasurada** (depilación) y **parqueo exclusivo**.

---

## 6. Flujo conversacional (resumen del brief)

Fuente completa: `08.tessa/brief/Estructura de Conversacion IA.md`.

1. **Bienvenida** (saludo por hora) → ¿qué tratamiento te interesa?
2. **Orientar** si no sabe → las 3 líneas.
3. **Info del servicio** → usar `description` + `frequency_text` del catálogo.
4. **Precio/promo** → `current_price` de la zona + beneficios.
5. **Invitar a reservar** → ¿qué día / mañana o tarde?
6. **Consultar agenda** → `available-slots` con la **duración de la zona**; ofrecer horarios.
7. **Registrar datos** → si el contacto no existe, crearlo (persons).
8. **Crear la cita (interno)** → re-verificar disponibilidad, crear la actividad,
   confirmar que quedó bien **antes** de confirmarle a la paciente.
9. **Confirmación** → tratamiento+zona, fecha, hora, duración, sucursal, dirección,
   rasurada (si depilación), parqueo.
10. Recordatorio (CRM) · No-show (CRM) · **Cambiar/Cancelar** · Medios de pago
    (QR/efectivo) · **Derivar a humano** (handoff) · Cierre a las 4 h sin respuesta.

---

## 7. API del CRM que consume el agente

**Base URL:** `https://tessa.sofopolis.com`
**Auth:** Bearer **Sanctum** (token de un usuario dedicado del CRM, obtenido una vez
con `POST /api/v1/login`). Enviar `Authorization: Bearer <token>` en los endpoints autenticados.

### 7.1 Disponibilidad (el endpoint del agente)
```
GET /api/doctors/{id}/available-slots?date=YYYY-MM-DD&days=7&duration_minutes=30
Auth: Bearer
```
- `id` = la agenda única ("Agenda Tessa", hoy `1`).
- `duration_minutes` = **duración de la zona elegida** (clave).
- Devuelve `schedule[].slots[]` con `{start_time, end_time, status:"available"}`,
  ya empaquetados **seguidos** y con domingos vacíos.
- Para Tessa (sin SMD) degrada a disponibilidad **local** (`source:"local"`), que es
  lo esperado. *(Existe `GET /api/doctors/{id}/slots` sin auth, mismo motor, útil para pruebas.)*

### 7.2 Catálogo
```
GET /whatsapp/products?search=&branch_id=        (auth admin — estructura RICA)
```
Devuelve por servicio: `name, specialty, categories[], description, frequency_text,
min_interval_days, zones[]{name, duration_minutes, price, current_price}`.

> ⚠️ **Pendiente CRM (sección 9):** el endpoint público `GET /public/products` hoy
> **no** expone zonas/precio/frecuencia. Hay que enriquecerlo (o darle al agente un
> token para `/whatsapp/products`). **Coordinar con el equipo del CRM.**

### 7.3 Crear la cita
```
POST /api/v1/activities
Auth: Bearer
{
  "type": "meeting",
  "title": "Depilación láser — Axilas",
  "comment": "Agendado por el agente IA",
  "schedule_from": "2026-10-29 09:00:00",
  "schedule_to":   "2026-10-29 09:30:00",     // = from + duración de la zona
  "product_id": 12,                            // el SERVICIO
  "participants": { "doctors": [1], "persons": [3456] }
}
```
El CRM crea la cita con `status="scheduled"` y la sucursal de la agenda; mueve el
lead a "Cita confirmada". Re-verifica solape antes de crear.

### 7.4 Paciente (contacto)
```
POST /api/v1/persons            (crear paciente)      Auth: Bearer
GET  /api/v1/leads/search       (buscar caso)         Auth: Bearer
```

### 7.5 WhatsApp (conversación)
```
GET  /api/v1/whatsapp/conversations/{id}/messages     Auth: Bearer   (historial)
POST /api/v1/whatsapp/conversations/{id}/messages      Auth: Bearer   (enviar texto)
POST /api/v1/whatsapp/conversations/{id}/handoff       Auth: Bearer   (derivar a humano)
POST /api/v1/whatsapp/webhook/{secret}                 (inbound entrante)
# POST .../conversations/{id}/flows  → DESHABILITADO (responde 422). No usar.
```

---

## 8. Secuencia de agendamiento (orden de llamadas)

```
1. Catálogo         → GET /whatsapp/products            (servicios + zonas + precios)
2. (paciente elige servicio → ZONA)  → de la zona: duration_minutes, current_price
3. Disponibilidad   → GET /api/doctors/1/available-slots?date=...&duration_minutes=<zona>
4. (paciente elige un slot)
5. Resolver paciente→ GET /api/v1/leads/search  /  POST /api/v1/persons (si no existe)
6. Crear cita       → POST /api/v1/activities  (doctors:[1], persons:[id], product_id, from/to)
7. Confirmar        → mensaje con detalle + rasurada/parqueo
```

---

## 9. Pendientes del lado CRM que afectan al agente

| Pendiente | Impacto en el agente | Estado |
|---|---|---|
| Enriquecer `GET /public/products` con zonas/precio/frecuencia **o** dar token para `/whatsapp/products` | El agente necesita el catálogo con zonas y `current_price` | **A definir con CRM** |
| **Precio congelado por paciente** (#4) | El agente informaría el precio ya respetado del tratamiento | Bloqueado (reglas de Tessa) |
| **Paquetes** criolipólisis (#5) | Vender/consumir paquete (15 zonas, pago completo) | Bloqueado (reglas de Tessa) |
| **Wiring de estados** confirmed/cancelled/rescheduled (#7) | Cancelar/reprogramar por texto desde el agente | Pendiente CRM |

El agente puede arrancar con **agendar + informar** (lo que ya está listo) y sumar
cancelar/reprogramar/paquetes cuando el CRM cierre #7 y Tessa defina las reglas.

### 9.1 Decisión pendiente — fuente de catálogo para el agente

El agente Odontoking consume el catálogo por `GET /public/products` (sin auth), pero
ese endpoint **todavía no expone la estructura nueva de Tessa** (zonas, `current_price`,
`frequency_text`, `categories`). Hay que resolver **una** de estas dos opciones antes de
que el agente pueda ofrecer zonas y precios:

- **Opción A — enriquecer `/public/products`**: agregarle zonas + precio vigente +
  frecuencia + categorías (misma forma que `/whatsapp/products`). El agente sigue sin auth.
- **Opción B — dar un token al agente** para que use `/whatsapp/products` (que ya trae
  todo), protegido por Sanctum.

> **Estado: a decidir con el equipo del CRM.** No implementado a propósito. Mientras
> tanto, `/whatsapp/products` es la referencia de la estructura completa (sección 7.2).

---

## 10. Variables de entorno (de Odontoking → Tessa)

```diff
# Catálogo / disponibilidad del CRM
- ODONTOKING_API_URL=        → + TESSA_API_URL=https://tessa.sofopolis.com
- ODONTOKING_API_TOKEN=      → + TESSA_API_TOKEN=<Sanctum bearer>
# CRM intermediario (persons/leads/activities)
  CRM_BASE_URL=https://tessa.sofopolis.com
  CRM_API_KEY=<Sanctum bearer>
# WhatsApp (gateway de Tessa)
  WHATSAPP_GATEWAY=...        WHATSAPP_PHONE_NUMBER_ID=...  WHATSAPP_ACCESS_TOKEN=...
  WHATSAPP_AGENT_TOKEN=<bearer que el CRM manda en cada evento; el agente lo valida>
# QUITAR
- SHAREMEDATA_API_URL / SHAREMEDATA_API_KEY     (no hay SMD)
- WHATSAPP_FLOWS_ENABLED                         (todo por texto)
- (todo lo de insurance / seguros)
# LLM
  OPENAI_API_KEY= / DEFAULT_LLM_MODEL=           (o el proveedor que usen)
```

---

## 11. Checklist de implementación (equipo del agente)

- [ ] Clonar `01.odontoking` a `02.agentes/tessa`; limpiar historial/secrets dental.
- [ ] **Quitar**: `insurance.py`, `whatsapp_flows.py`, integración SMD, prompts dentales.
- [ ] **Adaptar** `odontoking.py`→`tessa.py` y `crm.py` a los endpoints de la sección 7.
- [ ] Agregar el paso **servicio → zona** (duración + precio de la zona).
- [ ] Reescribir el **prompt** con el guion de Tessa (sección 6) + tono (5.6).
- [ ] Configurar **env** de Tessa (sección 10) + token Sanctum del CRM.
- [ ] Conectar el **gateway de WhatsApp** de Tessa.
- [ ] Coordinar con CRM el **catálogo público** (sección 9).
- [ ] Adaptar **evals** al flujo de Tessa (agendar depilación/HIFU/criolipólisis).
- [ ] Prueba end-to-end: conversación real → cita creada en el CRM.

---

*Referencias: `08.tessa/brief/` (brief + guion), `08.tessa/docs/specs/` (specs de
precio-congelado y fichas), `02.agentes/01.odontoking/` (molde).*
