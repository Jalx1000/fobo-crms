# Tessa Spa: revisión técnica (complementa `01.revision-terminologia-spa.md`)

**Fecha:** 19 de octubre de 2026 · **Rama:** `tessa` (7eae50d) · **Estado:** diagnóstico, sin cambios aplicados

El documento 01 cubre terminología y qué sacar. Este cubre lo que hay **debajo**: riesgos, qué partes del código dependen de Odontoking y qué ya existe para reutilizar.

## 0. Riesgos a resolver primero

| # | Hallazgo | Evidencia | Impacto | Propuesta |
| --- | --- | --- | --- | --- |
| R1 | **Odontoking producción corre la rama `tessa`** | `docker inspect heaven_odontoking-crm`: `.../odontoking-crm/code → /code`; `git branch` = `tessa`, sin worktree aparte | Cualquier cambio de spa (renombrar etapas, quitar seguros, textos) **llega a Odontoking** en cuanto se toque un archivo o se haga `view:clear`. Ya está activo `TrustProxies = '*'` (7eae50d) en Odontoking. | Volver esta carpeta a la rama de Odontoking (`main`, o la que corresponda) y trabajar Tessa en `git worktree add ../tessa-code tessa`. |
| R2 | **Datos reales de pacientes versionados en `origin/tessa`** | `citas-smd/` (14 archivos en git: JSON de eventos con nombre, teléfono, nacimiento, seguro; un `.zip`; una foto de médicos). No está en `origin/main`. `.dockerignore` no lo excluye → **va dentro de la imagen de Railway**. | Datos de salud de Odontoking dentro del repo y del contenedor de otro cliente. El repo es privado (404 anónimo), pero se comparte entre clones (sensia, kohlberg, tessa). | `git rm -r --cached citas-smd` + `.gitignore`; evaluar purgar del historial de la rama. Mover los scripts útiles (`backfill.php`, `reconcile.php`) a un comando artisan sin datos. |
| R3 | **Tessa = copia completa de Odontoking a nivel de seguridad** | Memoria del proyecto: misma `APP_KEY`, dump completo de `odontoking_dev` (usuarios con sus hashes, pacientes), TCP proxy público de MySQL (`tokaido.proxy.rlwy.net:41272`) creado para la importación. | Cookies firmadas/valores cifrados válidos entre ambas apps; los usuarios de Odontoking pueden entrar a Tessa con su contraseña; base de pacientes accesible por un puerto público. | Generar `APP_KEY` propia (re-cifrar lo que dependa de ella), vaciar usuarios/pacientes, cerrar el TCP proxy cuando no se use. |
| R4 | **El scheduler de Tessa intenta sincronizar SMD** | `docker/entrypoint.sh:79` corre `schedule:run`; `app/Console/Kernel.php:23` programa `smd:sync-dropbox` cada 30 min salvo `SmdSyncState::isPaused()` | Ruido en logs/errores cada 30 min; si algún día se carga un token de Dropbox por error, importaría citas de Odontoking. | Feature flag `SMD_ENABLED` (ver §2) que apague comandos, scheduler y UI. |

## 1. Qué ya existe y se reutiliza tal cual

Krayin + lo construido para Odontoking cubre la mayor parte de un spa sin código nuevo:

| Necesidad del spa | Ya existe | Nota |
| --- | --- | --- |
| Agenda por profesional | Calendario por doctor (`doctor-day-calendar`), turnos y días libres (`Doctor/schedules`), multi-doctor por cita | Solo terminología |
| Duración por servicio | **Columna `products.duration_minutes` ya existe** (`2026_05_24_000001_add_duration_minutes_to_products_table.php`, `Product::$fillable`) | No la usa nadie todavía (§3) |
| Disponibilidad local | `DoctorAvailabilityService::slotsForRange($doctorId, $start, $days, $duration)` arma turnos con shifts + citas del CRM | Ya acepta duración variable |
| Reserva por WhatsApp | Flows de reservar / cancelar / reprogramar, recordatorios, agente IA, derivación a recepción | Depende de SMD para horarios (§3) |
| "Para otra persona" | `person_relations` + flow `paciente_tercero` | Base directa para gift cards |
| Catálogo y venta | Products con precio; Quotes | Quotes → presupuesto de paquetes |
| Retail (cremas, aceites) | `Warehouse` + stock de productos de Krayin | Hoy oculto/no usado en Odontoking; en un spa sí sirve |
| Campañas | `Marketing` (campañas/eventos), `EmailTemplate`, `Automation` (workflows, webhooks) | Cumpleaños y posvisita se arman como workflow |
| Cumpleaños | Atributo fecha de nacimiento en Persons | — |

## 2. Lo que está acoplado a Odontoking (sin flag para apagarlo)

Hoy no hay forma de "desenchufar" las integraciones dentales por configuración; solo se las deja sin credenciales.

| Acople | Dónde | Problema en Tessa |
| --- | --- | --- |
| **Horarios del formulario de WhatsApp vienen de SMD** | `BookingOptions::fetch()` (`packages/Webkul/Admin/src/Services/BookingOptions.php:179`) llama `smd->availabilityBySpecialty()` directo y empareja doctores por `unique_id` (id de médico SMD) | Sin SMD, el formulario de reserva **siempre falla** ("no se pudieron consultar los horarios"). Es el bloqueante funcional n.º 1. |
| Fallback local existe pero no se usa ahí | `AvailabilityResolverService::resolve()` ya devuelve `localResult()` si `smd.validate_availability=false` | Se puede reutilizar: `fetch()` debería pasar por el resolver (o por `DoctorAvailabilityService`) cuando SMD está apagado. |
| Duración fija de 60 min y turnos "en punto" | `BookingOptions::duration()` lee `WHATSAPP_FLOW_BOOKING_DURATION` (global); `hourly()` redondea a horas en punto | Un masaje de 90 min o una manicura de 45 no encajan. |
| Seguros | `InsuranceService` + drivers (Alianza, Nacional Vida, `OdontokingMembershipDriver`), `Listeners/Person.php:22`, Swagger de `PersonController` | Sin flag; textos y pasos del flow lo muestran. |
| Sucursal por defecto "Santa Cruz" | `config('smd.default_subsidiary', 'Santa Cruz')` | Concepto SMD, se cuela en consultas. |
| Etapas mapeadas a estados SMD | `config/smd.php` `stages.*` incluye `surgery` (Cirugía) | Al quitar la etapa Cirugía hay que revisar estas referencias. |
| Nombres de Flows de Meta | `Whatsapp/src/Config/whatsapp.php:199-222` defaults `odontoking_*` | Configurables por env, pero los defaults son de Odontoking. |
| Textos con la marca | `whatsapp.php:428,454` ("clínica Odontoking", "Clínica Odontoking"), `doctor-day-calendar.blade.php:1902-1917`, placeholder `doctor@odontoking.com` en `Doctor/.../create.blade.php:38` | Textos al cliente final con otra marca. |

**Propuesta transversal:** en lugar de mantener los cambios del spa como diferencias de rama (ya hay `sensia`, `kohlberg`, `tessa`), mover lo que varía por cliente a configuración:

- `config/brand.php`: nombre comercial, dirección y términos (`patient`/`client`, `doctor`/`therapist`), leídos por las traducciones y las plantillas.
- Flags `SMD_ENABLED`, `INSURANCE_ENABLED`, `DROPBOX_SYNC_ENABLED`, que apaguen comandos, scheduler, rutas y bloques de UI.

Así un fix en Odontoking se lleva a Tessa con un merge sin conflictos de texto, y Tessa queda como "Odontoking con flags apagados + `brand` del spa".

## 3. Duración por servicio: estado real

| Pieza | Estado |
| --- | --- |
| `products.duration_minutes` | Existe, nadie la lee |
| `specialties.estimated_duration_minutes` | Columna existe (`2026_01_27_000001_...`), pero **no se guarda**: no está en `Specialty::$fillable` ni en la validación de `SpecialtyController::store/update`. El campo solo aparece en `specialties/create.blade.php` y `edit.blade.php`, que **son vistas muertas**: los controladores renderizan `specialties/form.blade.php`, que no lo tiene. |
| `DoctorAvailabilityService` | Acepta duración variable. Avanza el cursor de a `duración` (un servicio de 90 min ofrece 09:00, 10:30, 12:00): deja huecos que un spa querría vender. Conviene separar **duración** de **paso de la grilla** (15 o 30 min). |
| Flow de WhatsApp | Duración global (env), turnos en horas en punto. |

Camino corto: que el flow pregunte **servicio** (no especialidad) y use `product.duration_minutes` → disponibilidad local con paso de 15/30 min → la actividad se crea con `schedule_to = from + duración`.

## 4. Limpieza menor

- Borrar las vistas muertas `Doctor/.../specialties/create.blade.php` y `edit.blade.php` (o mover el campo de duración a `form.blade.php` si se decide usarlo).
- `Services/MedinetGo Odontology.postman_collection.json` (ya en doc 01).
- Comentarios `TODO(odontoking)` en `PatientContext.php:208`.

## 5. Orden sugerido (técnico)

1. **R1** (worktree aparte) y **R2** (sacar `citas-smd/` del repo y de la imagen). Son urgentes y no tocan funcionalidad.
2. **R3**: `APP_KEY` propia, base limpia y cerrar el proxy de MySQL.
3. Flags `SMD_ENABLED` / `INSURANCE_ENABLED` / `DROPBOX_SYNC_ENABLED` (en `main`, para que sirvan a todos los clones).
4. Disponibilidad local en `BookingOptions` cuando SMD está apagado. Sin esto, el WhatsApp de Tessa no puede reservar.
5. Duración por servicio + paso de grilla.
6. `config/brand.php` + terminología (doc 01).
7. Mejoras de spa (doc 01 §3), empezando por paquetes de sesiones y cabinas.
