# Odontoking CRM - Documentación Técnica

> Sistema de gestión para clínica dental construido sobre **Krayin CRM** (Laravel + Vue 3).  
> Extiende la arquitectura modular de Krayin con el módulo custom **Doctor** y la integración con **ShareMeData**.

---

## Tabla de contenidos

1. [Visión general](#1-visión-general)
2. [Arquitectura de módulos](#2-arquitectura-de-módulos)
3. [Flujo: Crear cita desde el Calendario](#3-flujo-crear-cita-desde-el-calendario)
4. [Flujo: Crear Lead con cita automática](#4-flujo-crear-lead-con-cita-automática)
5. [Flujo: Timeline de actividades en un Lead](#5-flujo-timeline-de-actividades-en-un-lead)
6. [Flujo: Editar una actividad](#6-flujo-editar-una-actividad)
7. [Flujo: Verificación de seguros](#7-flujo-verificación-de-seguros)
8. [Flujo: Gestión de turnos de doctores](#8-flujo-gestión-de-turnos-de-doctores)
9. [Inventario de clases y métodos](#9-inventario-de-clases-y-métodos)
10. [Rutas completas](#10-rutas-completas)
11. [Arquitectura de capas](#11-arquitectura-de-capas)
12. [Eventos del sistema](#12-eventos-del-sistema)
13. [Glosario](#13-glosario)

---

## 1. Visión general

### Equivalencias de dominio

| Entidad Krayin | Significado en Odontoking |
|---|---|
| `Lead` | Caso / Expediente del paciente |
| `Person` | Paciente |
| `Organization` | Clínica / Sucursal |
| `Activity` (`type=meeting`) | Cita médica agendada |
| `Activity` (`type=note`) | Nota clínica |
| `Activity` (`type=call`) | Llamada de seguimiento |
| `Product` | Servicio / Procedimiento dental |
| `Pipeline / Stage` | Etapas del flujo de atención |
| `Doctor` *(custom)* | Médico dentista con turnos y especialidades |

### Stages del pipeline relevantes

| ID | Nombre | Cuándo se asigna |
|---|---|---|
| 1 | Consulta | Lead creado sin cita |
| 2 | Confirmada | Lead creado con cita desde el formulario |
| 7 | Consulta Confirmada | Cita creada desde el Calendario o `AppointmentService` |

---

## 2. Arquitectura de módulos

```
packages/Webkul/
├── Activity/           Motor de actividades y citas
├── Admin/              UI principal, controladores web, servicios custom
│   └── Services/
│       ├── AppointmentService.php      ← Procesador central de citas (6 pasos)
│       ├── ShareMeDataService.php      ← Integración con agenda externa SMD
│       ├── InsuranceService.php        ← Verificación de seguros (multi-driver)
│       └── Insurance/Drivers/
│           ├── AlianzaDriver.php
│           ├── NacionalVidaDriver.php
│           └── OdontokingMembershipDriver.php
├── Contact/            Pacientes (Person) y Organizaciones
├── Doctor/             Doctores, especialidades, turnos ← MÓDULO CUSTOM
├── Lead/               Pipeline de casos / citas
├── Attribute/          Atributos personalizados por entidad
├── Automation/         Workflows automáticos y Webhooks
├── DataTransfer/       Importación masiva de datos (CSV)
├── Email/              Gestión de correos integrados
├── Marketing/          Campañas y eventos de marketing
├── Product/            Servicios dentales e inventario
├── Quote/              Presupuestos
├── Tag/                Etiquetas para leads y contactos
├── User/               Usuarios, roles, grupos
└── Warehouse/          Sucursales / inventario
```

---

## 3. Flujo: Crear cita desde el Calendario

```
Usuario abre /admin/activities (vista Calendario de Doctores)
│
├─► Vue: GET /activities/get?view_type=calendar&calendar_mode=doctor&start=...
│         │
│         └─► ActivityController::get()
│               1. Determina rango: week / month / day
│               2. Consulta activities JOIN doctor_activities JOIN doctors
│                  JOIN persons JOIN leads JOIN products
│               3. Consulta doctor_shifts (jornadas laborales)
│               4. generateSlotsFromAvailability()
│                  └─ Divide cada bloque de turno en slots de 30 min
│               5. Retorna JSON:
│                  { range, days, doctors, appointments, availability, stages }
│
├─► Vue renderiza calendario: columnas=doctores, filas=horas
│   Slots verdes = disponibles | Slots con color = citas existentes
│
├─► Usuario hace clic en un slot disponible
│
├─► Modal: Paciente + Servicio + Razón
│
└─► Vue: POST /activities/appointments/create
          │
          └─► ActivityController::storeAppointment()
                Valida: person, doctor_id, product_id,
                        date, start_time, end_time|duration_minutes
                Construye schedule_from y schedule_to
                │
                └─► processMedicalAppointment()
                      │
                      └─► AppointmentService::process(data)
```

### `AppointmentService::process()` - 6 pasos garantizados

```
PASO 1 - Validar turno / jornada laboral
──────────────────────────────────────────────────────────
SELECT * FROM doctor_shifts
WHERE doctor_id = X
  AND date = fecha_cita
  AND start_time <= hora_inicio
  AND end_time   >= hora_fin

  ✗ No existe → AppointmentException: "Fuera de jornada laboral"
  ✓ Existe    → continúa

PASO 2 - Validar conflicto de cita local
──────────────────────────────────────────────────────────
SELECT * FROM activities
JOIN doctor_activities ON activities.id = activity_id
WHERE doctor_id = X
AND (
  schedule_from >= :inicio AND schedule_from < :fin      -- caso A
  OR schedule_to > :inicio AND schedule_to <= :fin       -- caso B
  OR schedule_from <= :inicio AND schedule_to >= :fin    -- caso C (contiene)
)

  ✗ Existe → AppointmentException: "Doctor ya tiene cita en este horario"
  ✓ No     → continúa

PASO 3 - Auto-descubrir unique_id en ShareMeData (si falta)
──────────────────────────────────────────────────────────
Para cada especialidad del doctor:
  GET /api/calendar/schedule/availability?specialty=X&...
  Compara nombre del doctor con physicians en respuesta
  Si coincide → UPDATE doctors SET unique_id = _id

  ✗ No encontrado → AppointmentException: "No se pudo vincular al doctor"
  ✓ Encontrado   → continúa

PASO 4 - Verificar disponibilidad en ShareMeData
──────────────────────────────────────────────────────────
Para cada especialidad del doctor:
  GET /api/calendar/schedule/availability?
    where=subsidiary=Santa Cruz&specialty=X
    &from=2026-05-05T08:00:00-04:00
    &to=2026-05-05T09:00:00-04:00
    &groupByDay=true&timeZone=America/La_Paz

  Divide el rango en bloques de 15 min
  Verifica que TODOS los bloques estén disponibles

  ✗ No disponible → AppointmentException: "Sin disponibilidad en ShareMeData"
  ✓ Disponible   → continúa

PASO 5 - Crear evento en ShareMeData (PRIMERO que los registros locales)
──────────────────────────────────────────────────────────
POST /api/calendar/schedule/createEvent
{
  "summary":   "CONSULTA: Juan Pérez - Limpieza",
  "physician": { "_id": "unique_id_smd", "email": "dr@clinica.com" },
  "patient":   { "name": "Juan", "lastName": "Pérez", "phone": "0",
                 "personID": "", "birthday": "" },
  "slot":      { "start": "2026-05-05T08:00:00-04:00",
                 "end":   "2026-05-05T09:00:00-04:00" }
}

  ✗ Falla → AppointmentException: "Error al registrar la cita en ShareMeData"
  ✓ OK    → continúa

PASO 6 - Crear registros locales en DB::transaction()
──────────────────────────────────────────────────────────
  ¿Paciente tiene lead abierto?
    Sí → reutiliza ese lead
    No → leadRepository.create({ title, description, person, doctor_id })

  activityRepository.create({
    type:          'meeting',
    title:         eventTitle,
    comment:       razón,
    schedule_from, schedule_to,
    user_id:       auth().id,
    participants:  { doctors: [doctor_id], persons: [person_id] }
  })

  activity.leads().sync([lead.id])
  UPDATE leads SET lead_pipeline_stage_id = 7   -- "Consulta Confirmada"
  activity.products().sync([product_id])

  Retorna: { lead_id, activity_id, product_id, message }
```

**Respuesta al frontend:**
```json
{
  "data":    { /* ActivityResource */ },
  "message": "Cita creada y sincronizada correctamente."
}
```
Vue emite el evento `on-activity-added` y el calendario se actualiza.

---

## 4. Flujo: Crear Lead con cita automática

```
Usuario: GET /admin/leads/create
│
│  Formulario: título, paciente, doctor, stage, productos,
│              appointment_start (opcional), appointment_end (opcional)
│
└─► POST /admin/leads/create → LeadController::store()
      │
      ├─► ¿Tiene appointment_start y appointment_end?
      │     No → crea Lead con stage 1 ("Consulta") y termina
      │
      ├─► ¿Hay doctor_id?
      │     No → Error 422: "Debe seleccionar un doctor"
      │
      ├─► Validación 1: Conflicto local (igual al Paso 2 de AppointmentService)
      │     Existe → Error: "Doctor ya tiene cita en este horario"
      │
      ├─► Validación 2: Jornada laboral (igual al Paso 1 de AppointmentService)
      │     Fuera  → Error: "Fuera de jornada laboral"
      │
      ├─► Validación 3: Disponibilidad en ShareMeData (igual al Paso 4)
      │     No disponible → Error: "Sin disponibilidad en ShareMeData"
      │
      └─► DB::transaction()
            │
            ├─► leadRepository.create(data)
            │   stage = 2 ("Confirmada")
            │
            ├─► createAutomaticAppointment(lead, data)
            │   activityRepository.create({
            │     type:          'meeting',
            │     title:         'Cita Confirmada: ' + lead.title,
            │     schedule_from: appointment_start,
            │     schedule_to:   appointment_end,
            │     is_done:       0,
            │     participants:  { persons:[person_id], doctors:[doctor_id] }
            │   })
            │   activity.leads().attach(lead.id)
            │   activity.products().attach([product_ids])
            │
            ├─► shareMeDataService.createEvent({ summary, physician, patient, slot })
            │
            └─► Event::dispatch('lead.create.after', lead)
                Redirige al kanban → Lead visible en columna "Confirmada"
```

---

## 5. Flujo: Timeline de actividades en un Lead

```
Usuario: GET /admin/leads/view/{id}
│
│  LeadController::view($id)
│  Valida autorización: bouncer().getAuthorizedUserIds()
│
└─► [Blade: admin::leads.view]
      Pestaña "Activities" → Vue: GET /leads/{id}/activities
      │
      └─► Lead\ActivityController::index($id)
            │
            ├─► Query actividades del lead:
            │   activityRepository
            │     .leftJoin('lead_activities',   'activities.id', 'activity_id')
            │     .leftJoin('doctor_activities', 'activities.id', 'activity_id')
            │     .leftJoin('doctors',           'doctor_activities.doctor_id', 'doctors.id')
            │     .where('lead_activities.lead_id', id)
            │     .select('activities.*',
            │             'doctors.name as doctor_name',
            │             'doctor_activities.doctor_id')
            │
            ├─► concatEmailAsActivities($leadId, $activities)
            │   │
            │   ├─► Query emails del lead (directos + cadenas de respuesta):
            │   │   SELECT child.* FROM emails child
            │   │   JOIN emails parent ON child.parent_id = parent.id
            │   │   WHERE parent.lead_id = X
            │   │   UNION
            │   │   SELECT * FROM emails WHERE lead_id = X
            │   │
            │   └─► Cada email se normaliza como objeto:
            │       { type: 'email', title: subject, comment: reply,
            │         additional: { folders, from, to, cc, bcc },
            │         files: [attachments] }
            │
            ├─► Mezcla actividades + emails
            ├─► Ordena por created_at DESC, id DESC
            └─► Retorna ActivityResource::collection(...)

Timeline resultante (orden cronológico inverso):
  ● Citas       (type=meeting)  → doctor asignado, hora, servicio
  ● Notas       (type=note)     → texto libre, notas de seguro
  ● Llamadas    (type=call)     → hora programada
  ● Emails      (type=email)    → enviados/recibidos
  ● Archivos    (type=file)     → documentos adjuntos
```

### Crear actividad desde el Lead

```
Usuario: Modal "Agregar Actividad" → POST /admin/activities/create
│
└─► Activity\ActivityController::store()
      │
      Valida según tipo:
        type='note'  → comment requerido
        type='file'  → file requerido
        otros        → schedule_from y schedule_to requeridos
      │
      ├─► ¿type == 'meeting'?
      │     Sí → extrae doctor_id y person_id de participants{}
      │          → processMedicalAppointment() → AppointmentService (6 pasos)
      │
      └─► Cualquier otro tipo:
            activityRepository.create({
              ...request.all(),
              is_done: type == 'note' ? 1 : 0,
              user_id: auth().guard('user').id()
            })
            Event::dispatch('activity.create.after', activity)
```

---

## 6. Flujo: Editar una actividad

```
Usuario: GET /admin/activities/edit/{id}
│
└─► ActivityController::edit($id)
      activity = activityRepository.with(['participants','doctors']).findOrFail($id)
      leadId   = activity.leads().first()?.id
      lookUpEntityData = attributeRepository.getLookUpEntity('leads', leadId)
      → Vista: admin::activities.edit  (formulario pre-llenado)

Usuario modifica y guarda: PUT /admin/activities/edit/{id}
│
└─► ActivityController::update($id)
      │
      Event::dispatch('activity.update.before', $id)
      │
      activity = activityRepository.update(data, $id)
      │
      ├─► ¿Viene 'lead_id'?
      │     lead existe  → activity.leads().sync([lead_id])
      │                    ¿Viene lead_pipeline_stage_id?
      │                      → lead.update({ lead_pipeline_stage_id })
      │     lead no existe → activity.leads().sync([])  ← desasocia
      │
      ├─► ¿Viene 'product_id'?
      │     → activity.products().sync([product_id])   o sync([])
      │
      ├─► ¿Viene 'doctor_id'?
      │     → activity.doctors().sync([doctor_id])     o sync([])
      │
      Event::dispatch('activity.update.after', activity)
      │
      ¿AJAX? → JSON { data: ActivityResource, message }  HTTP 200
      No     → redirect /admin/activities
```

---

## 7. Flujo: Verificación de seguros

```
Desde perfil del paciente: botón "Verificar Seguro"
│
└─► GET /contacts/persons/{id}/insurance/verify
      │
      └─► InsuranceController::verify($id)
            Lee: ci_paciente, seguro_paciente (atributos custom del paciente)
            │
            ├─► ¿Faltan CI o seguro?
            │     Sí → HTTP 422 + opciones de seguro (attribute_options)
            │          → UI abre modal para completar datos
            │
            └─► insuranceService.verify($id)
                  │
                  InsuranceService::verify($personId)
                  │
                  ├─► seguro == 'No tiene' o vacío → status: SIN_SEGURO
                  │
                  ├─► resolveDriver(seguroName):
                  │     'alianza'    → AlianzaDriver
                  │     'nacional'   → NacionalVidaDriver
                  │     'membresía'  → OdontokingMembershipDriver
                  │     'odontoking' → OdontokingMembershipDriver
                  │     otro         → null → INDETERMINADO
                  │
                  ├─► ¿CI vacío? → INDETERMINADO: "Completa el CI"
                  │
                  └─► driver.verify(person, ci)
                        → Llama API externa de la aseguradora
                        → Retorna { status, message, badge, success, data{} }

Posibles status:
  VIGENTE       → Seguro activo y al día         🟢
  EN_MORA       → Seguro activo con mora          🟡
  VENCIDO       → Seguro expirado                 🔴
  INDETERMINADO → No se pudo verificar            ⚪
  SIN_SEGURO    → Sin seguro registrado           🔶

¿status == VIGENTE o EN_MORA?
  Sí → insuranceService.createNoteActivity($personId, $result)
       │
       Crea Activity type='note':
         title:   "Verificación de Seguro: Alianza - VIGENTE"
         comment: "Seguro: Alianza\n| Estado: VIGENTE\n|
                   Paciente: Juan Pérez\n| Detalle: ACTIVO\n|
                   Vigencia hasta: 31/12/2026\n| Coaseguro: 20%\n| ..."
         │
         activity.persons().attach($personId)
           → Visible en perfil del paciente (person_activities)
         activity.leads().attach([lead_ids del paciente])
           → Visible en cada cita relacionada (lead_activities)
```

### Flujo alternativo: usuario llena CI/seguro en modal

```
POST /contacts/persons/{id}/insurance/update-verify
│
└─► InsuranceController::updateAndVerify($id)
      Valida: ci_paciente (required), seguro_paciente (required)
      personRepository.update({ entity_type:'persons',
                                 ci_paciente, seguro_paciente }, $id)
      Event::dispatch('contacts.person.update.after', person)
      Cache::forget("insurance_verify_{$id}_...")
      → llama verify($id)  (flujo completo de arriba)
```

---

## 8. Flujo: Gestión de turnos de doctores

```
Administrador: GET /admin/schedules
│
└─► ScheduleController::index() → vista gestión de turnos
      Vue: GET /admin/schedules/week?start=2026-05-05
      │
      └─► ScheduleController::week()
            start = Carbon::parse(query.start) → inicio de semana
            end   = start.endOfWeek()
            doctors = doctorRepository.all()
            shifts  = shiftRepository.findWhere([date >= start, date <= end])
            → JSON { range, days:[{date,label}], doctors, shifts }

Vue muestra grilla semanal: filas=doctores, columnas=días
```

### Crear turno individual

```
POST /admin/schedules/create  (sin campo 'recurrence')
│
└─► ScheduleController::store()
      Valida: doctor_id, date, start_time, end_time
      │
      ¿Conflicto?
      SELECT FROM doctor_shifts
      WHERE doctor_id = X AND date = Y
        AND start_time < end_time_nuevo
        AND end_time   > start_time_nuevo
      │
      ✗ Conflicto → Error 422: "Conflicto de horario"
      ✓ Libre     → shiftRepository.create({ doctor_id, date,
                                              start_time, end_time, notes })
                    → JSON 201 con el shift creado
```

### Crear turno con recurrencia semanal

```
POST /admin/schedules/create  (con campo 'recurrence' + array 'shifts')
│
└─► ScheduleController::store()
      Para cada objeto en shifts[]:
        { doctor_id, start_date, end_date,
          days: [1,3,5],   ← Lun=1, Mié=3, Vie=5
          start_time, end_time }

        cursor = start_date
        MIENTRAS cursor <= end_date:
          ¿cursor.dayOfWeek en days[]?
            Sí → Verifica conflicto en esa fecha
                 Si sin conflicto:
                   shiftRepository.create({ doctor_id, date: cursor,
                                             start_time, end_time })
                   createdCount++
          cursor.addDay()

      → JSON 201: "X turnos creados correctamente"
```

---

## 9. Inventario de clases y métodos

### `Activity\ActivityController`
`packages/Webkul/Admin/src/Http/Controllers/Activity/ActivityController.php`

| Método | Visibilidad | Descripción |
|---|---|---|
| `index(): View` | public | Vista `/activities` con DataGrid y Calendario |
| `get(): JsonResponse` | public | JSON para DataGrid o Calendario de Doctores. Parámetros: `view_type`, `calendar_mode`, `calendar_view`, `start`, `activity_types[]` |
| `store()` | public | Crea actividad. Si `type=meeting` → delega en `processMedicalAppointment()` |
| `storeAppointment(): JsonResponse` | public | Endpoint dedicado para citas desde el calendario. Acepta `person`, `doctor_id`, `product_id`, `date`, `start_time`, `end_time\|duration_minutes` |
| `edit($id): View` | public | Formulario edición con participants y doctors pre-cargados |
| `update($id)` | public | Actualiza actividad + sincroniza lead/product/doctor vía `sync()` |
| `massUpdate(): JsonResponse` | public | Marca múltiples actividades como `is_done=1` |
| `download($id): StreamedResponse` | public | Descarga archivo adjunto de la actividad |
| `destroy($id): JsonResponse` | public | Elimina actividad (dispara eventos `before/after`) |
| `massDestroy(): JsonResponse` | public | Elimina múltiples actividades |
| `processMedicalAppointment()` | private | Orquesta llamada a `AppointmentService`, captura `AppointmentException` |
| `generateSlotsFromAvailability($availability, $durationMinutes=30)` | private | Convierte bloques de turno en slots de tiempo fijo para el calendario |

---

### `Lead\ActivityController`
`packages/Webkul/Admin/src/Http/Controllers/Lead/ActivityController.php`

| Método | Descripción |
|---|---|
| `index($id)` | Lista actividades del lead + emails concatenados, ordenados por `created_at DESC` |
| `concatEmailAsActivities($leadId, $activities)` | Query UNION de emails directos + cadenas de respuesta del lead. Los normaliza con `type='email'` |

---

### `Lead\LeadController`
`packages/Webkul/Admin/src/Http/Controllers/Lead/LeadController.php`

| Método | Descripción |
|---|---|
| `index()` | Vista kanban (Blade) o DataGrid (AJAX) |
| `get(): JsonResponse` | Leads agrupados por stage con paginación de 10, incluye `tags, type, source, user, doctor, person, pipeline, stage, attribute_values` |
| `create(): View` | Formulario con atributos `quick_add=1` + doctores disponibles |
| `store(LeadForm)` | Crea lead. Con cita: valida 3 reglas → transacción DB con cita automática + SMD |
| `edit($id): View` | Vista edición |
| `view($id)` | Vista detalle con control de autorización vía `bouncer()` |
| `update(LeadForm, $id)` | Actualiza lead y stage del pipeline |
| `updateAttributes($id)` | Actualiza solo atributos personalizados del lead |
| `updateStage($id)` | Cambia stage. Si es `won/lost` → setzt `closed_at = now()` |
| `search()` | Búsqueda por nombre para lookups/autocompletado |
| `addProduct($leadId): JsonResponse` | Asocia producto/servicio con precio y cantidad |
| `removeProduct($id): JsonResponse` | Desasocia producto del lead |
| `massUpdate(): JsonResponse` | Cambio masivo de stage |
| `massDestroy(): JsonResponse` | Eliminación masiva |
| `kanbanLookup()` | Búsqueda dinámica de filtros en vista kanban |
| `createByAI()` | Crea leads desde archivos PDF usando IA (Claude) |
| `createAutomaticAppointment($lead, $data)` | *(protected)* Crea `Activity` tipo `meeting` y la vincula al lead |
| `getKanbanColumns(): array` | *(private)* Define las 7 columnas filtrables del kanban |

---

### `Doctor\DoctorController`
`packages/Webkul/Doctor/src/Http/Controllers/DoctorController.php`

| Método | Descripción |
|---|---|
| `index()` | DataGrid de doctores (AJAX) o vista listado |
| `create()` | Formulario con todas las especialidades disponibles |
| `store()` | Crea doctor + guarda atributos custom (`entity_type='doctors'`) |
| `edit($id)` | Formulario edición pre-llenado |
| `update($id)` | Actualiza doctor + atributos custom. Valida nombre único excluyendo self |
| `destroy($id)` | Elimina doctor → JSON |
| `search()` | Búsqueda por nombre (query param `query`) → `{ data: [{id, name}] }` |

---

### `Doctor\ScheduleController`
`packages/Webkul/Doctor/src/Http/Controllers/ScheduleController.php`

| Método | Descripción |
|---|---|
| `index(): View` | Vista gestión de turnos |
| `week(Request): JsonResponse` | JSON con `range + days + doctors + shifts` de la semana solicitada |
| `create(): View` | Formulario creación de turno |
| `store(Request)` | Crea turno individual **o** con recurrencia semanal por días de semana en un rango de fechas. Valida conflictos en ambos casos |
| `edit($id): View` | Formulario edición del turno |
| `update(Request, $id): JsonResponse` | Actualiza turno con validación de conflicto (excluye el turno actual) |
| `destroy($id): JsonResponse` | Elimina turno |

---

### `Doctor\Api\AvailabilityController`
`packages/Webkul/Doctor/src/Http/Controllers/Api/AvailabilityController.php`

| Método | Descripción |
|---|---|
| `getForMonth(Request, $doctorId, $year, $month): JsonResponse` | Calcula slots de 60 min disponibles para todo el mes. Cruza `doctor_shifts` con `activities` ya agendadas. Query param `includeBooked=true` para incluir slots ocupados. Retorna `[{date, slots:[{startTime, endTime, isBooked}]}]` |

---

### `Persons\InsuranceController`
`packages/Webkul/Admin/src/Http/Controllers/Contact/Persons/InsuranceController.php`

| Método | Descripción |
|---|---|
| `verify($id): JsonResponse` | Lee atributos del paciente y delega en `InsuranceService`. Si faltan datos → devuelve opciones de seguro para modal |
| `updateAndVerify($id): JsonResponse` | Actualiza `ci_paciente` + `seguro_paciente` y luego verifica |
| `clearCache($id)` | Limpia caché `insurance_verify_{id}_*` del paciente |

---

### `AppointmentService`
`packages/Webkul/Admin/src/Services/AppointmentService.php`

| Método | Descripción |
|---|---|
| `process(array $data): array` | **Método principal.** 6 pasos ordenados. Recibe `{ doctor_id, person{id}, schedule_from, schedule_to, title?, reason?, product_id?, lead_id? }`. Retorna `{ lead_id, activity_id, product_id, message }`. Lanza `AppointmentException` en cualquier fallo |

---

### `ShareMeDataService`
`packages/Webkul/Admin/src/Services/ShareMeDataService.php`  
Base URL: `https://gamma.sharemedata.com/api/calendar`

| Método | Endpoint | Descripción |
|---|---|---|
| `getSpecialties()` | `GET /specialties` | Lista nombres de especialidades del sistema externo |
| `syncSpecialties()` | - | Sincroniza especialidades SMD con la BD local |
| `checkAvailability($extId, $specialty, $subsidiary, $from, $to)` | `GET /schedule/availability` | Retorna slots del doctor o `[]` si no hay disponibilidad |
| `isRangeAvailable(...)` | - | Verifica que **todo** el rango esté cubierto en bloques de 15 min |
| `createEvent($data): array` | `POST /schedule/createEvent` | Retorna `{ success, data, message }` |
| `getLastResponse(): array\|null` | - | Retorna última respuesta HTTP cruda (útil para debugging) |

> **Nota:** Todos los timestamps se envían en zona horaria UTC-4 (Bolivia).  
> Formato: `Y-m-d\TH:i:s-04:00`

---

### `InsuranceService`
`packages/Webkul/Admin/src/Services/InsuranceService.php`

| Método | Descripción |
|---|---|
| `verify(int $personId): array` | Lee atributos del perfil del paciente y delega al driver correcto |
| `verifyDirect(int $personId, string $ci, string $seguroName): array` | Verificación con parámetros explícitos (para API REST). Si VIGENTE/EN_MORA → crea nota automáticamente |
| `forceVerify(int $personId): array` | Borra caché y re-verifica |
| `resolveDriver(string $seguroName): ?InsuranceDriverInterface` | *(protected)* Matching case-insensitive por contenido parcial del nombre |
| `createNoteActivity(int $personId, array $result): void` | Crea nota `Activity` vinculada al paciente y a todos sus leads |
| `indeterminate(string $message): array` | *(protected)* Helper: retorna respuesta de error estándar |

**Drivers disponibles:**

| Driver | Seguro |
|---|---|
| `AlianzaDriver` | Alianza |
| `NacionalVidaDriver` | Nacional Vida |
| `OdontokingMembershipDriver` | Membresía / Odontoking |

---

### Modelo `Activity`
`packages/Webkul/Activity/src/Models/Activity.php`

| Relación | Tipo | Tabla pivot |
|---|---|---|
| `user()` | `belongsTo` | `users` |
| `participants()` | `hasMany` | `activity_participants` |
| `files()` | `hasMany` | `activity_files` |
| `leads()` | `belongsToMany` | `lead_activities` |
| `persons()` | `belongsToMany` | `person_activities` |
| `products()` | `belongsToMany` | `product_activities` |
| `warehouses()` | `belongsToMany` | `warehouse_activities` |
| `doctors()` | `belongsToMany` | `doctor_activities` |

**Fillable:** `title`, `type`, `location`, `comment`, `additional`, `schedule_from`, `schedule_to`, `is_done`, `user_id`

---

### Modelo `Doctor`
`packages/Webkul/Doctor/src/Models/Doctor.php`

| Campo | Tipo | Descripción |
|---|---|---|
| `number` | string | Número de colegiatura |
| `name` | string (unique) | Nombre completo |
| `email` | string | Email (usado en payload SMD) |
| `title` | string | Título / especialidad para display |
| `unique_id` | string | ID del doctor en ShareMeData |
| `is_active` | boolean | Estado activo/inactivo |

**Relaciones:** `specialties()` (via `doctor_specialty`), `activities()` (via `doctor_activities`)  
**Traits:** `LogsActivity`, `CustomAttribute`, `HasFactory`

---

## 10. Rutas completas

### Actividades

| Método | URI | Nombre de ruta | Acción |
|---|---|---|---|
| GET | `/admin/activities` | `admin.activities.index` | `ActivityController::index` |
| GET | `/admin/activities/get` | `admin.activities.get` | `ActivityController::get` |
| POST | `/admin/activities/create` | `admin.activities.store` | `ActivityController::store` |
| POST | `/admin/activities/appointments/create` | `admin.activities.appointments.store` | `ActivityController::storeAppointment` |
| GET | `/admin/activities/edit/{id}` | `admin.activities.edit` | `ActivityController::edit` |
| PUT | `/admin/activities/edit/{id}` | `admin.activities.update` | `ActivityController::update` |
| GET | `/admin/activities/download/{id}` | `admin.activities.file_download` | `ActivityController::download` |
| DELETE | `/admin/activities/{id}` | `admin.activities.delete` | `ActivityController::destroy` |
| POST | `/admin/activities/mass-update` | `admin.activities.mass_update` | `ActivityController::massUpdate` |
| POST | `/admin/activities/mass-destroy` | `admin.activities.mass_delete` | `ActivityController::massDestroy` |

### Leads

| Método | URI | Nombre de ruta | Acción |
|---|---|---|---|
| GET | `/admin/leads` | `admin.leads.index` | `LeadController::index` |
| GET | `/admin/leads/get` | `admin.leads.get` | `LeadController::get` |
| GET | `/admin/leads/create` | `admin.leads.create` | `LeadController::create` |
| POST | `/admin/leads/create` | `admin.leads.store` | `LeadController::store` |
| POST | `/admin/leads/create-by-ai` | `admin.leads.create_by_ai` | `LeadController::createByAI` |
| GET | `/admin/leads/view/{id}` | `admin.leads.view` | `LeadController::view` |
| GET | `/admin/leads/edit/{id}` | `admin.leads.edit` | `LeadController::edit` |
| PUT | `/admin/leads/edit/{id}` | `admin.leads.update` | `LeadController::update` |
| PUT | `/admin/leads/attributes/edit/{id}` | `admin.leads.attributes.update` | `LeadController::updateAttributes` |
| PUT | `/admin/leads/stage/edit/{id}` | `admin.leads.stage.update` | `LeadController::updateStage` |
| GET | `/admin/leads/search` | `admin.leads.search` | `LeadController::search` |
| DELETE | `/admin/leads/{id}` | `admin.leads.delete` | `LeadController::destroy` |
| GET | `/admin/leads/{id}/activities` | `admin.leads.activities.index` | `Lead\ActivityController::index` |
| PUT | `/admin/leads/product/{lead_id}` | `admin.leads.product.add` | `LeadController::addProduct` |
| DELETE | `/admin/leads/product/{lead_id}` | `admin.leads.product.remove` | `LeadController::removeProduct` |
| GET | `/admin/leads/kanban/lookup` | `admin.leads.kanban.lookup` | `LeadController::kanbanLookup` |

### Módulo Doctor

| Método | URI | Nombre de ruta | Acción |
|---|---|---|---|
| GET | `/admin/doctor` | `admin.doctor.index` | `DoctorController::index` |
| GET | `/admin/doctor/create` | `admin.doctor.create` | `DoctorController::create` |
| POST | `/admin/doctor` | `admin.doctor.store` | `DoctorController::store` |
| GET | `/admin/doctor/edit/{id}` | `admin.doctor.edit` | `DoctorController::edit` |
| PUT | `/admin/doctor/edit/{id}` | `admin.doctor.update` | `DoctorController::update` |
| DELETE | `/admin/doctor/{id}` | `admin.doctor.delete` | `DoctorController::destroy` |
| GET | `/admin/doctor/search` | `admin.doctor.search` | `DoctorController::search` |
| GET | `/admin/specialties` | `admin.specialties.index` | `SpecialtyController::index` |
| GET | `/admin/specialties/sync` | `admin.specialties.sync` | `SpecialtyController::sync` |
| GET | `/admin/schedules` | `admin.schedules.index` | `ScheduleController::index` |
| GET | `/admin/schedules/week` | `admin.schedules.week` | `ScheduleController::week` |
| POST | `/admin/schedules/create` | `admin.schedules.store` | `ScheduleController::store` |
| PUT | `/admin/schedules/edit/{id}` | `admin.schedules.update` | `ScheduleController::update` |
| DELETE | `/admin/schedules/{id}` | `admin.schedules.delete` | `ScheduleController::destroy` |

### API pública Doctor

| Método | URI | Nombre de ruta | Acción |
|---|---|---|---|
| GET | `/api/specialties` | `api.specialties.index` | `Api\SpecialtyController::index` |
| GET | `/api/doctors` | `api.doctors.index` | `Api\DoctorController::index` |
| GET | `/api/doctors/{id}` | `api.doctors.show` | `Api\DoctorController::show` |
| GET | `/api/doctors/{id}/availability/{year}/{month}` | `api.doctors.availability` | `AvailabilityController::getForMonth` |

---

## 11. Arquitectura de capas

```
┌─────────────────────────────────────────────────────────────────┐
│  FRONTEND                                                       │
│  Blade Templates + Vue 3 (componentes inline) + Tailwind CSS    │
│  Librerías: vue-cal, flatpickr, vee-validate, vuedraggable      │
│  Plugins globales: $admin, $axios, $emitter (mitt)              │
│  Build: Vite - un vite.config.js por paquete                    │
├─────────────────────────────────────────────────────────────────┤
│  CONTROLADORES HTTP                                             │
│  Activity\ActivityController   Calendario + CRUD actividades    │
│  Lead\LeadController           Pipeline kanban + CRUD leads     │
│  Lead\ActivityController       Timeline de actividades          │
│  Persons\InsuranceController   Verificación de seguros          │
│  DoctorController              CRUD médicos                     │
│  ScheduleController            Gestión de turnos                │
│  Api\AvailabilityController    Disponibilidad mensual (API)     │
├─────────────────────────────────────────────────────────────────┤
│  SERVICIOS DE NEGOCIO                                           │
│  AppointmentService   Procesador central de citas (6 pasos)     │
│  ShareMeDataService   Integración con agenda externa SMD        │
│  InsuranceService     Verificación de seguros (multi-driver)    │
├─────────────────────────────────────────────────────────────────┤
│  REPOSITORIOS  (patrón l5-repository)                           │
│  ActivityRepository  LeadRepository   PersonRepository          │
│  DoctorRepository    ShiftRepository  SpecialtyRepository       │
│  AttributeRepository AttributeValueRepository                   │
│  PipelineRepository  StageRepository  ProductRepository         │
├─────────────────────────────────────────────────────────────────┤
│  MODELOS ELOQUENT  (con Proxy pattern de Concord)               │
│  Activity  Lead  Person  Doctor  Shift  Specialty               │
│  Pipeline  Stage  Product  Attribute  User  Tag  Email          │
├─────────────────────────────────────────────────────────────────┤
│  BASE DE DATOS  (MySQL)                                         │
│  Tablas pivot clave:                                            │
│  doctor_activities   activity ↔ doctor                          │
│  lead_activities     activity ↔ lead                            │
│  person_activities   activity ↔ person (notas de seguro, etc.)  │
│  product_activities  activity ↔ product                         │
│  doctor_specialty    doctor ↔ specialty                         │
│  doctor_shifts       jornadas laborales de cada doctor          │
├─────────────────────────────────────────────────────────────────┤
│  SISTEMAS EXTERNOS                                              │
│  ShareMeData API    gamma.sharemedata.com/api/calendar          │
│  Alianza API        Verificación seguro Alianza                 │
│  Nacional Vida API  Verificación seguro Nacional Vida           │
└─────────────────────────────────────────────────────────────────┘
```

---

## 12. Eventos del sistema

| Evento | Cuándo se dispara |
|---|---|
| `activity.create.before / after` | Antes/después de crear actividad |
| `activity.update.before / after` | Antes/después de actualizar actividad |
| `activity.delete.before / after` | Antes/después de eliminar actividad |
| `lead.create.before / after` | Antes/después de crear lead |
| `lead.update.before / after` | Antes/después de actualizar lead |
| `lead.delete.before / after` | Antes/después de eliminar lead |
| `lead.product.delete.before / after` | Al desasociar producto de lead |
| `contacts.person.update.after` | Después de actualizar paciente |

---

## 13. Glosario

| Término | Definición |
|---|---|
| **SMD / ShareMeData** | Sistema externo de agenda médica con el que se sincronizan citas |
| **unique_id** | ID del doctor en el sistema ShareMeData (`doctors.unique_id`) |
| **doctor_shifts** | Tabla de jornadas laborales de los doctores (turnos diarios) |
| **doctor_activities** | Tabla pivot que asocia doctores con actividades/citas |
| **Pipeline Stage** | Etapa del proceso de atención: Consulta → Confirmada → Consulta Confirmada |
| **Proxy pattern** | `DoctorProxy::modelClass()` resuelve la clase concreta en runtime (Concord) |
| **Bouncer** | Librería de control de acceso (roles y permisos) |
| **l5-repository** | Patrón Repository usado en todos los módulos de Krayin |
| **CI** | Cédula de Identidad del paciente (atributo custom `ci_paciente`) |
| **UTC-4** | Zona horaria Bolivia (`America/La_Paz`) para timestamps enviados a SMD |
| **AppointmentException** | Excepción custom lanzada cuando falla algún paso del proceso de cita |
| **DataGrid** | Componente de tabla con filtros, ordenamiento y paginación |
| **Quick Add** | Atributos marcados `quick_add=1` para mostrarse en formulario rápido |
| **Concord** | Framework de extensibilidad modular base de Krayin |

---

*Documentación generada: 5 de mayo de 2026 - rama `main`*
