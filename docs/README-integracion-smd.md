# Integración con ShareMeData (SMD) - README de traspaso

> **Para quién es esto:** cualquiera que retome la integración SMD ↔ Odontoking CRM sin contexto previo.
> Está pensado para leerse de arriba hacia abajo. Si solo vas a tocar la sincronización de citas,
> lo mínimo indispensable son las secciones **2**, **4**, **5** y **8**.

**Última actualización:** 2026-07-14
**Rama de trabajo:** `feature/dropbox-fixes`
**Documento de detalle del último cambio:** `planning/10-actualizacion-eventstatus-dropbox/00.contexto-y-alcance.md`

---

## 1. Qué es SMD y qué hace la integración

**ShareMeData (SMD)** es el sistema de agenda médica que usa Odontoking. El CRM (Krayin) se integra con él en **dos direcciones independientes**:

| Dirección | Mecanismo | Para qué |
|---|---|---|
| **CRM → SMD** (saliente) | API HTTP REST | Consultar disponibilidad, crear citas, crear/buscar/actualizar pacientes |
| **SMD → CRM** (entrante) | **Dropbox** (archivos JSON) | Recibir las citas que se crean o modifican directamente en SMD |

> ⚠️ **No hay un webhook HTTP de SMD hacia nosotros.** Todo lo que SMD nos comunica llega como **archivos JSON en una carpeta compartida de Dropbox**. El "webhook de Dropbox" que sí existe es de *Dropbox* avisándonos que un archivo cambió - no de SMD.

**Ambiente actual:** `gamma` (pre-producción de SMD). Base: `https://gamma.sharemedata.com`

---

## 2. Arquitectura de la sincronización entrante (lo importante)

```
SMD  ──escribe JSON──►  Dropbox  ──┬──► [1] cron  smd:sync-dropbox --days=2   (cada 5 min)
                                    ├──► [2] webhook Dropbox → ProcessDropboxNotification (job)
                                    └──► [3] botón "Sincronizar" en la UI → SyncSmdAppointmentsJob
                                                    │
                                                    └──► SmdAppointmentSyncService::processPayload()
                                                              │
                                                              └──► IncomingAppointmentService
                                                                     └──► Lead + Activity + Person + Doctor
```

**Los tres orígenes convergen en `SmdAppointmentSyncService::processPayload()`.** Ese es el único lugar donde se decide crear / actualizar / cancelar / descartar. Si tocás la lógica de sync, tocala ahí.

> 📌 **Histórico:** `ProcessDropboxNotification` tenía una **copia entera** de esa lógica. Se unificó el 14/07/2026. No la vuelvas a duplicar: fue la causa de que un fix se aplicara en un camino y no en el otro.

### Diferencia entre los tres orígenes

| | Cómo descubre los archivos | Alcance |
|---|---|---|
| **[1] cron** | `listFilesForDate()` sobre `config('smd.dropbox.appointments_folder')/<fecha>` | Solo las carpetas de los últimos N días |
| **[2] webhook** | `listChanges($cursor)` - cursor guardado en `settings.dropbox_cursor` | **La carpeta con la que se creó el cursor** |
| **[3] UI** | Igual que el cron, con `--days=7` | Idem cron |

> ⚠️ **Trampa conocida:** el cursor del webhook queda **atado a la carpeta con la que se inicializó**. Cambiar `DROPBOX_APPOINTMENTS_FOLDER` en `.env` **no lo actualiza**. Si cambiás la carpeta, hay que re-ejecutar `php artisan smd:dropbox-init-cursor`, o el webhook seguirá leyendo la carpeta vieja mientras el cron lee la nueva. Esto ya pasó una vez (julio/2026).

---

## 3. API saliente (CRM → SMD)

Autenticación: header **`apikey`** (valor estático, en `SHAREMEDATA_API_KEY`).

| Método | Ruta | Implementado en |
|---|---|---|
| `GET` | `/api/calendar/specialties` | `ShareMeDataService::getSpecialties()` |
| `GET` | `/api/calendar/schedule/availability` | `ShareMeDataService::checkAvailability()` |
| `POST` | `/api/calendar/schedule/createEvent` | `ShareMeDataService::createEvent()` |
| `GET` | `/api/patients` | `searchPatient()` / `searchPatientByCi()` / `searchPatientByEmail()` |
| `POST` | `/api/patients/user` | `createPatient()` |
| `PATCH` | `/api/patients/{id}` | `updatePatient()` |

> ⚠️ Los pacientes usan **otra base URL** que el calendario: `https://gamma.sharemedata.com/api` vs `.../api/calendar`. Por eso hay dos configs (`base_url` y `patients_url`).

---

## 4. Anatomía del payload de Dropbox

### Estructura de carpetas y nombres

```
/smd-events/<clinicId>/<YYYY-MM-DD>/event-<YYYY-MM-DD>-<HHMM>-<_id>.json
```

- `<clinicId>` - identifica la clínica. **La real de Odontoking es `60ccc528c1c48400065e3861`.**
  (`69bd99417549b10008e0ab5f` es la carpeta de **pruebas** que usó SMD para el cambio de julio.)
- `<YYYY-MM-DD>` de la carpeta = **el día en que se escribió el archivo**, NO el día de la cita.
- El nombre lleva fecha+hora de escritura y el `_id` del evento.

### Ejemplo real (recortado)

```json
{
  "_id": "6a562447af84d70008cec797",
  "eventId": "1783993397672",
  "eventStatus": "CREATED",
  "__v": 0,
  "archived": false,
  "status": "",
  "startDate": "2026-07-17T13:00:00.000Z",
  "endDate": "2026-07-17T14:00:00.000Z",
  "summary": "test",
  "created_at": "2026-07-13T14:16:50.325Z",
  "updated_at": "2026-07-14T11:57:59.765Z",
  "attendances": [
    { "_id": "6a19eb6f...", "fullName": "Alejandro ...", "type": "patient",   "phone": "59176616013" },
    { "_id": "69d904e4...", "fullName": "Adriana Soria", "type": "physician", "phone": "" }
  ],
  "owner": { "_id": "69bd9a6a...", "fullName": "Recepcionista Odontoking", "type": "entity:staff" }
}
```

### Los campos que importan

| Campo | Qué es | Cuidado |
|---|---|---|
| `_id` | **Identificador de la cita.** Estable entre versiones. | Es la clave del CRM (`smd_synced_events.external_id`) |
| `eventId` | Otro id, también estable | **No se usa.** `_id` alcanza |
| `eventStatus` | `CREATED` \| `UPDATED` | Agregado en julio/2026. **No se usa para decidir** (ver §5) |
| `__v` | Versión, incremental (0, 1, 2, …) | Útil para depurar |
| `updated_at` | Marca de la versión, **estrictamente creciente** | **Es la clave de la corrección** (ver §5) |
| `status` | `""` \| `"CANCELED"` \| `"completed"` \| `"no-show"` | `""` = **confirmada** (decisión de negocio) |
| `archived` | bool | `true` cancela, **independiente** de `status` |
| `startDate` / `endDate` | ISO **UTC** | Bolivia es UTC−4. `13:00Z` = **09:00 local** |
| `attendances[]` | Paciente y médico | Ver la trampa de la hidratación abajo |
| `owner` | **Quién creó el evento** | ⚠️ **NO es el médico.** Suele ser la recepcionista |

### 🚨 La trampa de la hidratación (crítico)

**Los `CREATED` vienen hidratados; los `UPDATED` vienen deshidratados.**

```
__v=0 (CREATED) → Juan Perez [type=patient, phone=12345678]   ← completo
__v=1 (UPDATED) → Juan Perez [sin type, sin phone]            ← solo _id, name, lastName, fullName
__v=2 (UPDATED) → Juan Perez [sin type] | Adriana Soria [sin type]
```

Consecuencia: **no se puede usar `type` para saber cuál asistente es el médico**, ni matchear al paciente por teléfono.

**Cómo lo resolvimos (14/07/2026):** por el **`_id`**, que sí viene siempre y sí cruza con
`doctors.unique_id` / `persons.smd_patient_id` (ver §6). `normalizeDropbox()` identifica al
médico buscando qué asistente es un doctor registrado; `type` quedó como fallback para los
`CREATED` hidratados. Además, `resolvePerson()` **respalda el `smd_patient_id`** cuando
encuentra al paciente por teléfono: sin eso, un `UPDATED` deshidratado (sin `phone`) no lo
encontraría y crearía un duplicado.

### 🚨 Cambio de julio/2026 - un archivo por cada modificación

Desde el 13/07/2026, **cada modificación de una cita genera un archivo nuevo** en la carpeta **del día en curso**, y **los archivos viejos NO se borran**.

Es decir: una cita creada el 13 y modificada tres veces el 14 tiene **4 archivos** repartidos en 2 carpetas, todos con el mismo `_id`. Cualquier código que recorra carpetas va a ver varias versiones de la misma cita **en la misma corrida**.

---

## 5. Reglas de oro (invariantes que NO hay que romper)

1. **La versión que gana es la de `updated_at` más alto. Siempre.**
   `processPayload()` descarta cualquier payload cuyo `updated_at` sea anterior al ya aplicado (`smd_synced_events.source_updated_at`). Sin esto, el archivo viejo pisa al nuevo y **revierte la modificación** - pasó en producción, con una cita real corrida 4 días.

2. **No confíes en el orden en que Dropbox devuelve los archivos.**
   Se ordenan por nombre (que es cronológico por el formato `event-YYYY-MM-DD-HHMM-…`), pero eso es solo una **optimización**. La corrección la garantiza el descarte por `updated_at`.

3. **`eventStatus` no decide nada.**
   El CRM ya sabe si crear o actualizar por el `_id`. Además, los archivos anteriores a julio/2026 **no traen `eventStatus`** y hay que seguir procesándolos. Úsalo como verificación, no como lógica.

4. **`status: ""` significa confirmada**, no "sin estado". Ver `SmdStatusMapper`.

5. **`archived: true` y `status: "CANCELED"` son mecanismos independientes.** Cualquiera de los dos cancela.

6. **Un mismo archivo puede traer cancelación + edición.** Hay que aplicar el contenido *y* cerrar el lead. No retornes temprano.

7. **Si el payload es idéntico al guardado, no hagas nada.** El chequeo de hash va **antes** de la rama de cancelación, o una cita cancelada se re-cancela cada 5 minutos.

8. **A las personas se las identifica por `attendances[]._id`, nunca por nombre ni por `type`.**
   El `_id` siempre viene y cruza con `doctors.unique_id` / `persons.smd_patient_id`; `type`
   falta en la mayoría de los payloads.

9. **El `owner` NO es el médico** - es quien creó el evento (la recepcionista). Nunca lo uses
   como fallback de doctor: es lo que generó el doctor basura `id=458`.

10. **Si un payload no identifica al médico o al paciente, conserva los actuales.**
    `updateDropbox()` solo reasigna participantes cuando puede identificarlos; si no, dejaría
    la cita colgada de un placeholder "Doctor SMD" / "Paciente".

---

## 6. Modelo de datos

### `smd_synced_events` - el registro de lo sincronizado

| Columna | Para qué |
|---|---|
| `external_id` | El `_id` de SMD. **UNIQUE** - es la idempotencia |
| `source_file` | Path del último archivo aplicado |
| `activity_id` / `lead_id` | Qué creó en el CRM |
| `raw_payload` | El JSON aplicado. Su hash detecta "sin cambios" |
| `source_updated_at` | El `updated_at` del payload. **Descarta los obsoletos** (agregado 14/07/2026) |
| `status` / `archived_at` | Último estado conocido |

### ✅ Los ids de `attendances[]` SÍ cruzan con el CRM

> **⚠️ Corrección del 14/07/2026.** Una versión anterior de este documento afirmaba que
> existían "tres espacios de identificadores que no cruzan". **Era falso.** Ese diagnóstico
> se hizo contra la carpeta de **pruebas** (`/smd-events/69bd9941…`), donde SMD usó
> entidades descartables (un "Juan Perez" y una "Adriana Soria" de test con ids inventados).

Verificado contra la carpeta **real** (`/smd-events/60ccc528…`), sobre 30 archivos y 52 asistentes:

| Chequeo | Resultado |
|---|---|
| `attendances[]._id` → `doctors.unique_id` | **23 matches** |
| `attendances[]._id` → `persons.smd_patient_id` | **28 matches** |
| Sin match | **1** |
| **Physicians sin match** | **0** |

**El `_id` del asistente es el id de SMD y coincide con lo que guardamos.** Por eso el
médico se identifica **por id**, no por nombre ni por `type`.

> 💡 **Corolario clave:** como el `_id` matchea, **no hace falta `type`** para distinguir
> médico de paciente - basta con ver en qué tabla cae el id. Eso vuelve irrelevante la
> deshidratación de los `UPDATED` y **evita tener que pedirle nada a SMD**.

---

## 7. Estado actual

### ✅ Corregido y verificado en producción (14/07/2026)

| Bug | Qué pasaba |
|---|---|
| BUG-1 | El archivo viejo revertía cada modificación |
| BUG-2 | Un fallo transitorio de Dropbox envenenaba el token cacheado 3h30m |
| BUG-3 | `listChanges()` lanzaba `TypeError` y perdía la notificación del webhook |
| BUG-5 | Sin protección de concurrencia (cron + webhook duplicaban Lead/Activity) |
| BUG-12 | Una cancelación que además editaba el título perdía la edición |
| - | Una cita cancelada se re-cancelaba en cada corrida del cron |
| **BUG-4** | El médico no se identificaba (los `UPDATED` no traen `type`) → caía al `owner` → citas colgadas del doctor basura "Recepcionista Odontoking". **Ahora se identifica cruzando `attendances[]._id` contra `doctors.unique_id`, y el fallback al `owner` se eliminó** |
| **BUG-11** | Un cambio de paciente o médico en SMD no se reflejaba. **Ahora `updateDropbox()` re-resuelve `participants` y `doctor_activities`**, y reapunta el `person_id` del lead |
| **BUG-10** | ❌ **No existía.** Se diagnosticó contra la carpeta de pruebas (ver §6) |

**Evidencia:** corrida real sobre la carpeta de producción (`--days=2`, 330 archivos):
`Creados: 6 | Actualizados: 4 | Cancelados: 6 | Sin cambios: 257 | Obsoletos: 57 | Errores: 0`

### ⚠️ Deuda que dejó BUG-4

**19 citas siguen colgadas de `doctors.id = 458` ("Recepcionista Odontoking").** No se
arreglan solas: su payload no cambió, así que el sync las marca `sin cambios` y **nunca
las re-resuelve**. Hace falta un backfill que re-aplique el `raw_payload` guardado en
`smd_synced_events` para esas citas. Recién después se puede borrar el doctor 458.

### 🟡 Menores sin tocar

- **BUG-6** - Una cita reactivada queda "perdida": `cancelDropbox()` pone `status=0`/`lost_reason` y `updateDropbox()` nunca los revierte.
- **BUG-7** - `listFilesForDate()` ignora `has_more` (no pagina). Hoy no duele; dolería con carpetas grandes.
- **BUG-8** - `DropboxWebhookController:51` loguea la **firma HMAC esperada**. Con acceso a logs se puede forjar un request.
- **BUG-9** - `.env.example` **no tiene ninguna** variable de SMD/Dropbox.
- `closed_at` no persiste en el Lead (probablemente no está en `$fillable`), aunque `status` y `lost_reason` sí.
- `normalizeDropbox()` hardcodea `specialty = 'General'`: el payload de Dropbox **no trae el servicio**. Por eso el KPI "servicios solicitados" da 0 - no es un bug de código.

---

## 8. Entorno - trampas que te van a hacer perder tiempo

### 🚨 `.env.testing` apunta a la base de datos de PRODUCCIÓN

```
.env          DB_HOST=heaven_odontoking-bd   DB_DATABASE=odontoking
.env.testing  DB_HOST=172.17.0.1             DB_DATABASE=odontoking   ← el mismo MySQL
```

`172.17.0.1` es el gateway de Docker hacia **el mismo servidor**. **La suite de tests corre contra 1134 pacientes y 1717 leads reales.** Se salva porque todos los tests usan `DatabaseTransactions` y hacen rollback.

> **Si escribís un test nuevo, usá `DatabaseTransactions`. Un solo `RefreshDatabase` borra la producción.**
> (Decisión consciente del equipo, no cambiar sin avisar.)

### El código de este directorio ES el de producción

`/etc/easypanel/projects/heaven/odontoking-crm/code` está montado en el contenedor `heaven_odontoking-crm`. **No hay build ni deploy:** editar un `.php` acá impacta producción de inmediato.

### El cron sí funciona (aunque no lo parezca)

Supervisor corre `schedule` (`php artisan schedule:work`) junto a `ide`, `nginx`, `php` y `queue`. Config: `/etc/easypanel/projects/heaven/odontoking-crm/generated/supervisord.conf`.

**El scheduler manda su salida a `/dev/null`, así que no deja rastro en ningún log.** Para verificar que corre:

```bash
docker exec $(docker ps --format '{{.ID}}\t{{.Names}}' | grep odontoking-crm | cut -f1) supervisorctl status
docker logs --since 30m <container> 2>&1 | grep smd:sync-dropbox
```

### Los logs de producción no están en `storage/logs/laravel.log`

Ese archivo solo tiene entradas `testing.*` - o sea, **el ruido de la suite de tests**. Los logs reales van a stdout del contenedor (`docker logs`).

### Desde el shell del host, `artisan` no llega a la BD ni a Redis

- `heaven_odontoking-bd` **no resuelve** → usá `DB_HOST=172.17.0.1`.
- El PHP del host **no tiene la extensión `Redis`** → usá `CACHE_DRIVER=file` para corridas manuales.

```bash
# Ejecutar el sync manualmente con credenciales reales de Dropbox y BD alcanzable
DB_HOST=172.17.0.1 CACHE_DRIVER=file php artisan smd:sync-dropbox --days=2

# Consultas de solo lectura (usa .env.testing → misma BD)
php artisan tinker --env=testing --execute="echo DB::table('doctors')->count();"
```

### Inspeccionar Dropbox sin tocar la BD

```bash
DB_HOST=172.17.0.1 CACHE_DRIVER=file php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$svc = app(\Webkul\Admin\Services\DropboxService::class);
foreach ($svc->listFilesForDate("2026-07-14") as $f) {
    $j = $svc->downloadJson($f["path_lower"]);
    printf("%s __v=%s %s status=\"%s\" %s\n", $f["name"], $j["__v"] ?? "?",
        $j["eventStatus"] ?? "(legacy)", $j["status"] ?? "", $j["summary"] ?? "");
}'
```

### `Http::fake()` acumula stubs

En tests, llamar `Http::fake()` dos veces **no reemplaza** el primero: gana el anterior. El `Factory` además es **singleton**. Para re-fakear en el mismo test:

```php
app()->forgetInstance(\Illuminate\Http\Client\Factory::class);
Http::clearResolvedInstances();
Http::fake(...);
```

---

## 9. Comandos y archivos

### Comandos

```bash
php artisan smd:sync-dropbox --days=2          # sync (lo que corre el cron cada 5 min)
php artisan smd:sync-dropbox --date=2026-07-14 --days=1
php artisan smd:dropbox-init-cursor            # re-inicializa el cursor del webhook
php artisan test --filter "Dropbox|Smd"        # 144 tests
```

### Configuración

| Variable | Valor actual |
|---|---|
| `DROPBOX_APPOINTMENTS_FOLDER` | `/smd-events/60ccc528c1c48400065e3861` |
| `SHAREMEDATA_BASE_URL` | `https://gamma.sharemedata.com/api/calendar` |
| `SHAREMEDATA_PATIENTS_URL` | `https://gamma.sharemedata.com/api` |
| `SMD_STAGE_*` | Mapa de stages, ver `config/smd.php` |

### Archivos clave

```
config/smd.php                                          # stages, carpeta Dropbox, flags
packages/Webkul/Admin/src/
  Services/DropboxService.php                           # token OAuth, list, download
  Services/SmdAppointmentSyncService.php                # ★ processPayload() - el corazón
  Services/IncomingAppointmentService.php               # normaliza payload → Lead/Activity
  Services/SmdStatusMapper.php                          # status SMD → stage del CRM
  Services/ShareMeDataService.php                       # API saliente
  Jobs/ProcessDropboxNotification.php                   # webhook (delega en processPayload)
  Jobs/SyncSmdAppointmentsJob.php                       # botón de la UI
  Http/Controllers/DropboxWebhookController.php         # handshake + firma HMAC
  Console/Commands/SyncDropboxAppointments.php          # comando del cron
app/Console/Kernel.php                                  # */5 min, withoutOverlapping
tests/Feature/SmdStalePayloadSyncTest.php               # regresiones del cambio de julio
```

### Tests

`144` en verde para `Dropbox|Smd`. La suite completa da **8 fallos preexistentes** (`AgentInsuranceVerifyTest`, `LeadReuseTest`, `LeadUnassignedOwnerTest`, `ReassignSyncedLeadsCommandTest`) - **verificado con `git stash` que ya fallaban antes**. Son de seguros y de "Sin asignar", ajenos a esta integración.

---

## 10. Próximos pasos sugeridos

1. **Backfill de las 19 citas colgadas del doctor 458** ("Recepcionista Odontoking"):
   re-aplicar su `raw_payload` guardado para que se re-resuelva el médico. Recién después,
   borrar el doctor 458.
2. Barrer los menores (BUG-6 a BUG-9) en un PR de limpieza.
3. ~~Escribirle a Mauri pidiendo que hidraten los `UPDATED`~~ → **ya no hace falta**: el
   `_id` de `attendances[]` alcanza para identificar a todos (ver §6).

## Historial

| Fecha | Qué |
|---|---|
| 2026-05 | Integración inicial: API de calendario, pacientes, ingesta Dropbox (fases 00–06 en `planning/`) |
| 2026-07-13 | SMD despliega `eventStatus` y el esquema de un-archivo-por-cambio |
| 2026-07-14 | Se detectan y corrigen BUG-1/2/3/5/12; migración `source_updated_at`; se documentan BUG-4/10/11 |
| 2026-07-14 (2ª) | **BUG-10 resulta falso** (se había diagnosticado contra la carpeta de pruebas). Con eso se corrigen **BUG-4** (médico por `attendances[]._id` → `doctors.unique_id`, sin fallback al `owner`) y **BUG-11** (re-resolución de participantes en `updateDropbox()`). `.env` vuelve a la carpeta real y se re-inicializa el cursor. Queda el backfill de 19 citas |

**Contactos:** Mauri Pache (SMD) - vía WhatsApp.
