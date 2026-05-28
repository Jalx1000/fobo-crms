# Informe de Integración — ShareMeData × Odontoking CRM

**Fecha:** 2026-05-22
**Versión:** 1.0
**Preparado por:** Equipo Técnico Odontoking

***

## Índice

1. [Resumen ejecutivo](#1-resumen-ejecutivo)
2. [Inventario de integración](#2-inventario-de-integración)
3. [Diagramas de flujo](#3-diagramas-de-flujo)
4. [Diagrama de actividades — Ciclo completo](#4-diagrama-de-actividades--ciclo-completo)
5. [Configuración y entornos](#5-configuración-y-entornos)

***

## 1. Resumen ejecutivo

Odontoking CRM integra el sistema ShareMeData (SMD) en seis flujos de negocio distintos que cubren la gestión completa del ciclo de vida de una cita dental: desde la creación hasta la cancelación, pasando por la sincronización de pacientes y especialidades.

La integración opera en dos modos complementarios:

- **Saliente (CRM → SMD):** creación de citas y sincronización de pacientes.
- **Entrante (SMD → CRM):** recepción de citas y actualizaciones de estado vía webhook directo y vía Dropbox (webhook + polling de respaldo).

| # | Flujo                                  | Dirección     |
| - | -------------------------------------- | ------------- |
| A | Creación de cita                       | CRM → SMD     |
| B | Webhook directo                        | SMD → CRM     |
| C | Webhook Dropbox (tiempo real)          | Dropbox → CRM |
| D | Polling Dropbox (respaldo, cada 5 min) | Dropbox → CRM |
| E | Sincronización de paciente             | CRM ↔ SMD     |
| F | Sincronización de especialidades       | SMD → CRM     |

***

## 2. Inventario de integración

### 2.1 Configuración

| Variable de entorno           | Descripción                                                    |
| ----------------------------- | -------------------------------------------------------------- |
| `SHAREMEDATA_API_KEY`         | API Key de autenticación para calendario y pacientes           |
| `SHAREMEDATA_BASE_URL`        | URL base de la API de calendario (incluye `/api/calendar`)     |
| `SHAREMEDATA_PATIENTS_URL`    | URL base de la API de pacientes                                |
| `SHAREMEDATA_WEBHOOK_SECRET`  | Secreto HMAC para validar webhooks directos de SMD             |
| `DROPBOX_ACCESS_TOKEN`        | Token de acceso Dropbox (fallback)                             |
| `DROPBOX_REFRESH_TOKEN`       | Refresh token OAuth2 de Dropbox (credencial principal)         |
| `DROPBOX_APP_KEY`             | App key de la aplicación Dropbox                               |
| `DROPBOX_APP_SECRET`          | App secret de Dropbox (validación firma HMAC del webhook)      |
| `DROPBOX_APPOINTMENTS_FOLDER` | Ruta de la carpeta Dropbox donde SMD deposita los eventos JSON |

### 2.2 Servicios

| Clase                        | Responsabilidad                                                                                                                                                                                                               |
| ---------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `ShareMeDataService`         | Cliente HTTP hacia la API SMD. Métodos: `getSpecialties`, `syncSpecialties`, `checkAvailability`, `searchPatient`, `searchPatientByCi`, `searchPatientByEmail`, `createPatient`, `getPatient`, `updatePatient`, `createEvent` |
| `DropboxService`             | Cliente HTTP Dropbox con auto-renovación de token OAuth2. Métodos: `listFilesForDate`, `getInitialCursor`, `listChanges`, `downloadJson`                                                                                      |
| `AppointmentService`         | Orquesta la creación de citas: valida turno local, conflictos locales, disponibilidad SMD y crea el evento en SMD antes de persistir localmente                                                                               |
| `IncomingAppointmentService` | Procesa citas entrantes desde SMD (webhook directo y Dropbox). Normaliza, resuelve doctor/paciente/lead, crea actividad                                                                                                       |
| `SmdStatusMapper`            | Mapea el campo `status` de SMD (`confirmed`, `completed`, `canceled`, `no-show`) a stages del pipeline local                                                                                                                  |

### 2.3 Endpoints expuestos por Odontoking (recepción)

| Método | URI                         | Descripción                       | Seguridad                               |
| ------ | --------------------------- | --------------------------------- | --------------------------------------- |
| `POST` | `/api/webhooks/sharemedata` | Webhook directo SMD               | Secreto `X-Webhook-Secret`              |
| `GET`  | `/api/webhooks/dropbox`     | Handshake de verificación Dropbox | Ninguna (requerido por Dropbox)         |
| `POST` | `/api/webhooks/dropbox`     | Notificación de cambios Dropbox   | Firma HMAC-SHA256 `X-Dropbox-Signature` |

### 2.4 Endpoints SMD consumidos por Odontoking

| Método HTTP | Endpoint                                        | Propósito                          |
| ----------- | ----------------------------------------------- | ---------------------------------- |
| `GET`       | `{base_url}/specialties`                        | Obtener listado de especialidades  |
| `GET`       | `{base_url}/schedule/availability`              | Consultar disponibilidad de médico |
| `POST`      | `{base_url}/schedule/createEvent`               | Crear cita en SMD                  |
| `GET`       | `{patients_url}/patients?where=phone=/X/`       | Buscar paciente por teléfono       |
| `GET`       | `{patients_url}/patients?where=personID=/X/`    | Buscar paciente por CI             |
| `GET`       | `{patients_url}/patients?where=secondEmail=/X/` | Buscar paciente por email          |
| `POST`      | `{patients_url}/patients/user`                  | Crear paciente                     |
| `GET`       | `{patients_url}/patients/{id}`                  | Obtener paciente por ID            |
| `PATCH`     | `{patients_url}/patients/{id}`                  | Actualizar paciente                |

### 2.5 Persistencia

| Tabla               | Campo                                        | Propósito                                                                |
| ------------------- | -------------------------------------------- | ------------------------------------------------------------------------ |
| `persons`           | `smd_patient_id` (string, nullable)          | ID del paciente en SMD (`_id` de MongoDB)                                |
| `persons`           | `insurance_status` (string, nullable)        | Estado del seguro obtenido de SMD                                        |
| `persons`           | `insurance_checked_at` (timestamp, nullable) | Última verificación de seguro                                            |
| `smd_synced_events` | tabla completa                               | Registro de deduplicación de eventos procesados desde Dropbox            |
| `smd_synced_events` | `external_id` (unique)                       | ID del evento en SMD — garantiza idempotencia                            |
| `smd_synced_events` | `source_file`                                | Archivo Dropbox origen                                                   |
| `smd_synced_events` | `activity_id`, `lead_id`                     | Referencias locales creadas                                              |
| `smd_synced_events` | `raw_payload`, `status`                      | Payload original y estado de procesamiento                               |
| `smd_synced_events` | `archived_at`                                | Marca de cancelación                                                     |
| `doctors`           | `unique_id`                                  | `_id` del médico en SMD, usado para disponibilidad y creación de eventos |
| `settings`          | `key = 'dropbox_cursor'`                     | Cursor de posición en la API `list_folder/continue` de Dropbox           |

***

## 3. Diagramas de flujo

### Flujo A: Creación de cita saliente (CRM → SMD)

```mermaid
flowchart TD
    A([Usuario Admin\nagenda cita]) --> B[POST /api/v1/activities\ntype=meeting]
    B --> C{type == meeting?}
    C -- No --> D[Flujo Krayin estándar]
    C -- Sí --> E{doctorId y personId\npresentes?}
    E -- No --> F[422 Unprocessable\nDebes seleccionar Doctor y Paciente]
    E -- Sí --> G[AppointmentService::process]

    G --> G1{¿El doctor tiene\nturno para ese día?}
    G1 -- No --> G1E[Error: Fuera de jornada laboral]
    G1 -- Sí --> G2{¿Conflicto local\nen doctor_activities?}
    G2 -- Sí --> G2E[Error: Doctor ya tiene cita local]
    G2 -- No --> G3{¿Doctor tiene\nunique_id en BD?}
    G3 -- No --> G3A[Auto-descubrir:\nSMD GET /schedule/availability\npor cada especialidad]
    G3A --> G3B{¿Nombre del doctor\ncoinicde en SMD?}
    G3B -- No --> G3C[Error: No se pudo vincular\ndoctor con SMD]
    G3B -- Sí --> G3D[Guardar unique_id y email\ndel doctor en doctors]
    G3D --> G4
    G3 -- Sí --> G4[SMD GET /schedule/availability\npor cada especialidad del doctor]
    G4 --> G5{¿Todos los bloques\nde 15min disponibles?}
    G5 -- No --> G5E[Error: Sin disponibilidad en SMD]
    G5 -- Sí --> G6{¿Paciente tiene\nsmd_patient_id?}
    G6 -- No --> G6A[SMD GET /patients?where=phone=X]
    G6A --> G6B{¿Encontrado?}
    G6B -- No --> G6C[SMD POST /patients/user\ncrear paciente]
    G6C --> G6D{Duplicado en SMD?\nerror.duplicatePatient}
    G6D -- Sí --> G6E[SMD GET /patients?where=phone=X\nvuelve a buscar]
    G6D -- No --> G6F[Guardar _id en persons.smd_patient_id]
    G6E --> G6F
    G6B -- Sí --> G6F
    G6F --> G7[SMD POST /schedule/createEvent\npayload: physician._id, patient, slot]
    G7 --> G8{¿Respuesta exitosa?}
    G8 -- No --> G8E[Error: Error al registrar en SMD]
    G8 -- Sí --> G9[DB Transaction\nCrear/Reusar Lead + Crear Activity\nMover Lead a stage Confirmada]
    G9 --> G10{¿Error local?}
    G10 -- Sí --> G10E[Error: Registrado en SMD pero error local]
    G10 -- No --> G11[200 OK\nlead_id, activity_id, message]
```

***

### Flujo B: Webhook directo SMD → CRM

```mermaid
flowchart TD
    S([ShareMeData\nenvía webhook]) --> W[POST /api/webhooks/sharemedata]
    W --> CS{¿SHAREMEDATA_WEBHOOK_SECRET\nconfigurado?}
    CS -- Sí --> CV{header X-Webhook-Secret\n== secret?}
    CV -- No --> C401[401 Unauthorized]
    CV -- Sí --> VP
    CS -- No --> VP[Validar payload:\nphysician._id, patient.phone,\nslot.start, slot.end]
    VP --> VF{¿Falla validación?}
    VF -- Sí --> V422[422 Unprocessable]
    VF -- No --> PS[IncomingAppointmentService\n::processWebhook]
    PS --> NW[Normalizar payload\na estructura interna]
    NW --> RD[resolveDoctor\n1. unique_id\n2. nombre exacto\n3. nombre parcial\n4. crear nuevo]
    RD --> RS[fetchOrCreateByName\nespecialidad]
    RS --> RP[resolvePerson\n1. teléfono/email\n2. smd_patient_id\n3. crear nuevo]
    RP --> CL[createLead\ncon person_id]
    CL --> SM[statusMapper.toLeadStageId\nmapear status → stage_id]
    SM --> CA[activityRepository.create\ntype=meeting, participants=doctor+person\nadditional.source=ShareMeData]
    CA --> AS[activity.leads sync]
    AS --> RES[201 Created\nsuccess:true, lead_id, activity_id]
```

***

### Flujo C: Webhook Dropbox — notificación de cambios (tiempo real)

```mermaid
flowchart TD
    D([Dropbox envía notificación\nde cambio en carpeta]) --> VE{¿GET o POST?}
    VE -- GET con ?challenge --> CH[Responder 200\ncon challenge en text/plain]
    VE -- POST notification --> SV{Validar firma HMAC\nX-Dropbox-Signature vs\nhash_hmac sha256 del body}
    SV -- Inválida o vacía --> F403[403 Invalid signature]
    SV -- Válida --> DJ[ProcessDropboxNotification::dispatch\nel job va a cola]
    DJ --> R200[200 ok:true\nRespuesta inmediata a Dropbox]
    DJ -.->|async| JOB[ProcessDropboxNotification::handle]
    JOB --> JC{¿Cursor en\ntable settings?}
    JC -- No --> JW[Log warning\nse requiere inicialización del cursor]
    JC -- Sí --> JL[DropboxService::listChanges\nPOST /files/list_folder/continue]
    JL --> JT{¿Token 401?}
    JT -- Sí --> JR[Renovar token\nOAuth2 refresh_token grant]
    JR --> JL2[Reintentar listChanges]
    JT -- No --> JE[Iterar entries\n.json files únicamente]
    JL2 --> JE
    JE --> JD[DropboxService::downloadJson\nContent API /files/download]
    JD --> JI{¿JSON tiene _id?}
    JI -- No --> JErr[errors++\ncontinuar]
    JI -- Sí --> JES{¿Existe en\nsmd_synced_events?}
    JES -- Sí --> JHash{¿Mismo hash\nmd5 payload?}
    JHash -- Igual --> Skip[skipped++]
    JHash -- Diferente --> JUpdate[IncomingAppointmentService\n::updateDropbox]
    JUpdate --> JDB2[UPDATE smd_synced_events]
    JES -- No --> JCancel{archived:true\no isCancelled?}
    JCancel -- Sí --> JC2[IncomingAppointmentService::cancelDropbox\nLead → stage cancelled, closed_at, tag]
    JC2 --> JCA[UPDATE smd_synced_events\narchived_at]
    JCancel -- No --> JP[IncomingAppointmentService\n::processDropbox]
    JP --> JPS[normalizeDropbox\nextrae physician/patient de attendances]
    JPS --> JP2[resolveDoctor + resolvePerson\ncreateLead + createActivity]
    JP2 --> JDB[INSERT smd_synced_events\nexternal_id, source_file, activity_id, lead_id]
    JDB --> JCursor[UPDATE settings\ndropbox_cursor = cursor nuevo]
    JCursor --> JMore{has_more?}
    JMore -- Sí --> JL
    JMore -- No --> JDone[Log resumen\nprocesados/actualizados/cancelados/skipped/errores]
```

***

### Flujo D: Polling Dropbox — sincronización periódica (respaldo)

```mermaid
flowchart TD
    SC([Scheduler\ncada 5 minutos]) --> CMD[php artisan smd:sync-dropbox --days=2]
    CMD --> DL[Iterar fechas: hoy y ayer]
    DL --> LF[DropboxService::listFilesForDate\nPOST /files/list_folder\npath = folder/YYYY-MM-DD]
    LF --> R409{¿Status 409\ncarpeta no existe?}
    R409 -- Sí --> Skip2[Retornar array vacío\npasar a siguiente fecha]
    R409 -- No --> Files[Filtrar entries .json]
    Files --> DW[DropboxService::downloadJson\npor cada archivo]
    DW --> Check{¿JSON válido\ncon _id?}
    Check -- No --> Err[errors++]
    Check -- Sí --> Existing{¿Existe en\nsmd_synced_events?}
    Existing -- Sí --> HashCheck{¿Hash md5 cambió?}
    HashCheck -- No --> SkipIt[skipped++]
    HashCheck -- Sí --> UpdateIt[IncomingAppointmentService\n::updateDropbox]
    Existing -- No --> CancelCheck{archived o\nisCancelled?}
    CancelCheck -- Sí --> CancelIt[IncomingAppointmentService\n::cancelDropbox]
    CancelCheck -- No --> ProcessIt[IncomingAppointmentService\n::processDropbox]
    ProcessIt --> InsertDB[INSERT smd_synced_events]
    UpdateIt --> UpdateDB[UPDATE smd_synced_events]
    CancelIt --> CancelDB[UPDATE smd_synced_events archived_at]
    InsertDB & UpdateDB & CancelDB --> Summary[Log summary\nCreados / Actualizados / Cancelados / Sin cambios / Errores]
```

***

### Flujo E: Sincronización de paciente (CRM ↔ SMD)

```mermaid
flowchart TD
    EC([Evento Krayin\ncontacts.person.create.after]) --> SL[Listener Person - syncToSmd]
    EU([Evento Krayin\ncontacts.person.update.after]) --> UL[Listener Person - updateInSmd]

    SL --> SC{¿Ya tiene\nsmd_patient_id?}
    SC -- Sí --> SEnd[return — ya vinculado]
    SC -- No --> SP{¿Tiene teléfono?}
    SP -- No --> SND[Log debug — sin teléfono, omitir]
    SP -- Sí --> SF[Buscar en SMD:\n1. GET /patients?where=phone=/X/\n2. GET /patients?where=secondEmail=/X/]
    SF --> SF2{¿Encontrado?}
    SF2 -- No --> SC2[POST /patients/user\ncrear paciente en SMD]
    SC2 --> SD{Duplicado en SMD?\nerror.duplicatePatient}
    SD -- Sí --> SR[GET /patients?where=phone=/X/\nrecuperar ID]
    SD -- No --> SID[obtener _id]
    SR --> SID
    SF2 -- Sí --> SID
    SID --> SDBW[UPDATE persons\nsmd_patient_id = _id]

    UL --> UP{¿Tiene teléfono?}
    UP -- No --> UND[Log debug — sin teléfono, omitir]
    UP -- Sí --> UF[Buscar en SMD por teléfono/email]
    UF --> UF2{¿Encontrado?}
    UF2 -- Sí --> UU[PATCH /patients/_id\nactualizar datos]
    UU --> UNF{not found en SMD?\nerror.usercantfound}
    UNF -- Sí --> UClear[Limpiar smd_patient_id en BD\nbuscar de nuevo]
    UNF -- No y fallo --> UWarn[Log warning]
    UClear --> UF3{¿Re-encontrado?}
    UF3 -- Sí --> UU2[PATCH actualizar + guardar ID]
    UF3 -- No --> UC2[POST /patients/user crear]
    UC2 --> UD2{¿Duplicado?}
    UD2 -- Sí --> UR2[GET buscar por teléfono]
    UD2 -- No --> USID[obtener _id nuevo]
    UR2 --> USID
    UF2 -- No --> UClear
    USID --> UDBW[UPDATE persons\nsmd_patient_id si cambió]
```

***

### Flujo F: Sincronización de especialidades (SMD → CRM)

```mermaid
flowchart TD
    A([Usuario Admin\nclic Sincronizar]) --> B[POST /admin/doctors/specialties/sync]
    B --> C[ShareMeDataService::getSpecialties\nGET base_url/specialties]
    C --> D{¿Respuesta exitosa?}
    D -- No --> E[Log error\nretornar array vacío]
    D -- Sí --> F[Iterar array de especialidades]
    F --> G{¿Existe slug\nen specialties?}
    G -- Sí --> H[alreadyExistCount++]
    G -- No --> I[specialtyRepository\n::fetchOrCreateByName]
    I --> J[syncedCount++]
    H & J --> K{¿Más especialidades?}
    K -- Sí --> F
    K -- No --> L{¿syncedCount > 0?}
    L -- Sí --> M[Flash success:\nX especialidades nuevas sincronizadas]
    L -- No --> N[Flash info:\nNo hay especialidades nuevas]
    M & N --> O[Redirect specialties.index]
```

***

## 4. Diagrama de actividades — Ciclo completo

```mermaid
flowchart TD
    IDLE([Sistema en espera])

    IDLE --> PC[Crear paciente en CRM]
    PC --> PS[Evento person.create.after\nSincronizar con SMD]
    PS --> PL{smd_patient_id\nguardado?}
    PL -- Si --> IDLE
    PL -- No --> MS[Admin sincroniza manualmente\nPOST /sync-smd]
    MS --> PL

    IDLE --> AR[POST /api/v1/activities\ntype=meeting]
    AR --> VL[AppointmentService\nValidar turno y conflictos locales]
    VL -- Error --> ERRL([Error local 422])
    VL -- OK --> RD[Resolver doctor en SMD\nGET /schedule/availability]
    RD -- No encontrado --> ERRD([Error: doctor no vinculado en SMD])
    RD -- Vinculado --> CA[Verificar disponibilidad SMD]
    CA -- No disponible --> ERRA([Error: sin disponibilidad])
    CA -- Disponible --> SP[Sincronizar paciente\nPOST o GET /patients]
    SP --> CE[SMD POST /schedule/createEvent]
    CE -- Error --> ERRE([Error al crear evento en SMD])
    CE -- OK --> CL[BD: crear Lead + Activity\nStage Confirmada]
    CL --> OK1([200 OK])

    IDLE --> DW[POST /api/webhooks/dropbox]
    DW --> HV{Firma HMAC\nX-Dropbox-Signature valida?}
    HV -- No --> ERR403([403 Forbidden])
    HV -- Si --> DJ[Job despachado a cola\nRespuesta 200 inmediata a Dropbox]
    DJ --> JB[Job: leer cursor\nGET list_folder/continue]
    JB --> TR{Token Dropbox\nvalido?}
    TR -- 401 --> RT[Renovar via OAuth2\nrefresh_token grant]
    RT --> JB
    TR -- OK --> PF[Descargar archivos .json\nde carpeta SMD en Dropbox]
    PF --> EX{Existe en\nsmd_synced_events?}
    EX -- No existe --> NC{Cancelado\no archivado?}
    NC -- No --> PR[processDropbox\ncrear Lead + Activity]
    NC -- Si --> CA2[cancelDropbox\nLead cerrado + tag]
    EX -- Existe --> HC{Hash md5\ncambio?}
    HC -- No --> SK([skipped])
    HC -- Si --> UD[updateDropbox\nactualizar Lead + Activity]
    PR & CA2 & UD --> UC[Actualizar cursor en tabla settings]
    UC --> HM{has_more?}
    HM -- Si --> JB
    HM -- No --> LOGD([Log: resumen completado])

    IDLE --> SW[POST /api/webhooks/sharemedata]
    SW --> SV{Secreto\nX-Webhook-Secret valido?}
    SV -- No --> ERR401([401 Unauthorized])
    SV -- Si --> PW[processWebhook\nresolver doctor + paciente + lead]
    PW --> IA[Crear Activity\nsource = ShareMeData]
    IA --> OK2([201 Created])
```

***

## 5. Configuración y entornos

### Variables de entorno

| Variable                      | Entorno testing                                  | Entorno producción                    | Notas                                          |
| ----------------------------- | ------------------------------------------------ | ------------------------------------- | ---------------------------------------------- |
| `SHAREMEDATA_BASE_URL`        | `http://gamma.sharemedata.com:3000/api/calendar` | `https://{dominio-prod}/api/calendar` | A confirmar con SMD                            |
| `SHAREMEDATA_PATIENTS_URL`    | `https://gamma.sharemedata.com/api`              | `https://{dominio-prod}/api`          | A confirmar con SMD                            |
| `SHAREMEDATA_API_KEY`         | token de pruebas                                 | token de producción                   | A proveer por SMD                              |
| `SHAREMEDATA_WEBHOOK_SECRET`  | no definido en testing                           | definido en producción                | <br />                                         |
| `DROPBOX_REFRESH_TOKEN`       | no definido en testing                           | token OAuth2 activo                   | Credencial principal                           |
| `DROPBOX_APP_KEY`             | no definido en testing                           | `lgyil7bcotmhcjz`                     | <br />                                         |
| `DROPBOX_APP_SECRET`          | no definido en testing                           | definido                              | Validación HMAC                                |
| `DROPBOX_APPOINTMENTS_FOLDER` | no definido en testing                           | `/smd-events/{org_id}`                | El `org_id` es el ID de la organización en SMD |

### Cobertura de tests automatizados

Los siguientes escenarios cuentan con tests automáticos (Pest PHP) verificados en el repositorio:

- Validación de secreto webhook SMD (token válido, inválido, sin configuración)
- Validación de firma HMAC webhook Dropbox (firma válida, inválida, ausente)
- Handshake GET de Dropbox (challenge)
- Procesamiento nuevo, actualización por hash diferente, skip por hash igual, cancelación por `CANCELED` y por `archived`
- Paginación con `has_more=true` en el job
- Renovación de token Dropbox (401 → refresh → retry)
- Sincronización de paciente: crear, duplicado, not\_found, sin teléfono
- Búsqueda SMD: por teléfono, por CI, por email, fallback entre los tres
- Resolución de doctor: por unique\_id, por nombre exacto, por nombre parcial, creación nueva
- Mapeo de status SMD a stage\_id (todos los estados: `confirmed`, `completed`, `canceled`, `no-show`)
- Inicialización del cursor con `has_more` paginado

### Archivos clave de referencia

| Archivo                                                             | Descripción                                 |
| ------------------------------------------------------------------- | ------------------------------------------- |
| `packages/Webkul/Admin/src/Services/ShareMeDataService.php`         | Cliente HTTP completo hacia la API SMD      |
| `packages/Webkul/Admin/src/Services/AppointmentService.php`         | Flujo de creación de cita                   |
| `packages/Webkul/Admin/src/Services/IncomingAppointmentService.php` | Procesamiento de citas entrantes            |
| `packages/Webkul/Admin/src/Services/DropboxService.php`             | Cliente Dropbox con OAuth2                  |
| `packages/Webkul/Admin/src/Jobs/ProcessDropboxNotification.php`     | Job asíncrono de procesamiento              |
| `config/smd.php`                                                    | Configuración de mapeo de estados y Dropbox |
| `config/services.php`                                               | Credenciales SMD                            |
| `routes/api.php`                                                    | Definición de endpoints públicos            |

