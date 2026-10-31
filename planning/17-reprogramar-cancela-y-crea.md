# 17 · Reprogramar = cancelar la cita y crear una nueva

Fecha: 2026-10-30 · Estado: **implementado** (2026-10-31; falta verificación en navegador y alta de la plantilla en Meta)

## Objetivo

Reprogramar una cita deja de **mover** la misma cita. Pasa a:

1. la cita original queda **cancelada** (como cualquier cancelada);
2. se crea una **cita nueva** (lead nuevo + actividad nueva + evento SMD nuevo) con la fecha/hora nueva;
3. las dos quedan **vinculadas** entre sí.

## Decisiones (dueño: usuario, 2026-10-30)

| # | Decisión |
|---|---|
| D1 | Aplica a **dos caminos**: el formulario de WhatsApp "Reprogramar cita" y la edición de cita en el **calendario del CRM** cuando cambia fecha u hora. |
| D2 | La cita nueva lleva **lead nuevo** (no reusa el de la vieja). |
| D3 | En reportes/tablero la vieja cuenta como **cancelada** y la nueva como **nueva**. Sin categoría "reprogramada" en el tablero. |
| D4 | Orden: **validar → crear la nueva → cancelar la vieja**. Si la nueva falla, la vieja queda intacta. |
| D5 | La nueva recibe su propio recordatorio; la vieja no (está cancelada). |
| D6 | Hay **vínculo** entre la vieja y la nueva, visible en el detalle de la cita / historial, **no** en el tablero. |
| D7 | En el calendario hay un **botón "Reprogramar"** que abre una sección con los **horarios disponibles** del doctor (como el formulario de WhatsApp): se elige día y hora de la lista, no a mano. |
| D8 | Al reprogramar desde el CRM se **avisa al paciente por WhatsApp si su conversación está dentro de la ventana de 24 h de Meta** (escribió hace menos de 24 h) con un texto libre. Confirmado: "24 h" = ventana de WhatsApp, no la fecha de la cita. |
| D9 | **Bandera** para fuera de la ventana: si está encendida, el aviso sale como **plantilla aprobada**; si está apagada (por defecto), fuera de la ventana no se avisa. |

## Hechos del código actual (evidencia)

- **Hoy se mueve la cita.** `PatientAppointments::reschedule()` hace `UPDATE activities SET schedule_from/to` y `SmdAppointmentPush::push()` (PATCH al mismo evento). `packages/Webkul/Admin/src/Services/PatientAppointments.php:257`.
- **El formulario** llama a eso desde `RescheduleFlowAppointment::handle()`, tras `BookingOptions::isLiveFree()`. `packages/Webkul/Whatsapp/src/Jobs/RescheduleFlowAppointment.php`.
- **El CRM** edita por `ActivityController::updateAppointment()` (`activities-routes.php:26`, `ActivityController.php:867`); el modal del calendario lo invoca en `doctor-day-calendar.blade.php:~4130`. Hoy cambia fecha, doctores, paciente, servicio, motivo y estado en la **misma** cita.
- **Cancelar** = lead a `smd.stage_map.cancelled`, `status=0`, `lost_reason`, `closed_at` + push (SMD recibe `CANCELED`). `PatientAppointments::cancel()` `:133`.
- **Crear** = `AppointmentService::process()`: turno local → conflicto local → mismo paciente/doctor/día → disponibilidad SMD → `createEvent` en SMD → lead + actividad en transacción → `smd_synced_events`. `AppointmentService.php:46`. Sin `lead_id` crea lead nuevo (regla 1 lead = 1 cita, `:63`).
- **Dos validaciones de `process()` chocarían con la cita vieja**, porque la vieja sigue activa cuando se crea la nueva (D4):
  - *mismo paciente, mismo doctor, mismo día* → `assertPatientNotBookedThatDay()` `:641` ("…reprogramá esa cita");
  - *conflicto local de horario* `:111-156`.
- **SMD ve ocupado el horario de la vieja**: si el horario nuevo se superpone con el viejo, `assertAvailableInSmd()` `:739` falla. En el formulario no pasa (solo ofrece horarios libres en SMD); en el CRM sí puede.
- `activities.additional` es un JSON que ya se usa para metadatos (`booked_by_person_id`, `source`) → sirve para el vínculo **sin migración**.
- **El calendario no tiene hoy un selector de horarios libres**: su `availability` son los turnos locales (`doctor_shifts`) para pintar bloques (`doctor-day-calendar.blade.php:3187`, `getDoctorAvailability()` `:3348`), sin SMD ni citas descontadas.
- **Los horarios libres reales** (SMD ∩ turnos − citas) los da `AvailabilityResolverService::resolve($doctorId, $desde, $dias)` `:33`; el formulario los convierte a horarios de inicio con `BookingOptions::hourly()` (contados desde el inicio de cada hueco, commit `d0f44c3`). Cada `resolve()` consulta SMD, que tiene **cuota contratada** (`planning/15-consumo-api-smd`).
- **Ventana de 24 h**: `MessagingWindow::compute($horas, $lastInboundAt)` (`Whatsapp/src/Gateways/Dto/MessagingWindow.php:35`), ya usada por `AgentController::sendFlow()`. Fuera de la ventana Meta solo acepta templates aprobados.
- El historial del paciente en el detalle de la cita sale de `ActivityController::personAppointments()` `:986` y se pinta en `doctor-day-calendar.blade.php:1279-1302`.

## Diseño

### Backend

**`Webkul\Admin\Services\AppointmentRescheduler`** (nuevo, único punto de verdad para los dos caminos):

```
reschedule(int $oldActivityId, Carbon $from, Carbon $to, array $overrides = [], string $origin = 'crm'|'whatsapp'): array
  → ['old_activity_id', 'new_activity_id', 'new_lead_id', 'smd' => [...]]
  throws AppointmentException (la vieja queda intacta)
```

1. Lee la vieja: paciente, doctores, servicio, motivo, `user_id` del lead (recepcionista), `additional` (quién agendó, origen).
2. Arma los datos de `process()` copiando todo eso + `$overrides` (lo editado en el modal) + **`replaces_activity_id = $oldActivityId`**.
3. **Caso normal (no se superponen):** `process()` → si OK, `cancel(old)`.
4. **Caso superpuesto (mismo doctor y el intervalo nuevo pisa el viejo):** `cancel(old)` → `process()` → si falla, **restaurar** la vieja (etapa y `status` previos, `lost_reason/closed_at` a null, push a SMD) y relanzar el error.
5. Vínculo en `additional`: nueva `rescheduled_from = old_id`; vieja `rescheduled_to = new_id`.
6. Copia al lead nuevo el `user_id` (recepcionista asignada) del lead viejo.

**`AppointmentService::process()`**: acepta `replaces_activity_id` y **excluye esa actividad** en el conflicto local y en `assertPatientNotBookedThatDay()`. Nada más cambia.

**`PatientAppointments::cancel()`**: recibe el motivo; para reprogramaciones `lost_reason = 'Reprogramada'` (D3: sigue contando como cancelada; el texto solo informa).

**Caminos que lo llaman:**
- `RescheduleFlowAppointment` → `AppointmentRescheduler::reschedule(..., origin: 'whatsapp')`. Mensaje al paciente igual que hoy.
- **Nuevo** `POST admin/activities/appointments/{id}/reschedule` `{date, time}` → `AppointmentRescheduler` (origin `crm`). Es lo que usa el botón "Reprogramar" (D7).
- `ActivityController::updateAppointment()` → si **cambió fecha u hora** respecto de la cita guardada → `AppointmentRescheduler` con los demás campos como `$overrides`; si no, sigue editando la misma cita como hoy. (Ver P2: quizá se bloquea fecha/hora en "Editar".)
- `personAppointments()` agrega `rescheduled_from` / `rescheduled_to` (id + fecha/hora de la otra cita) a cada fila.

**Horarios para reprogramar (D7)** — nuevo `GET admin/activities/appointments/{id}/reschedule-slots?from=Y-m-d`:
- Doctor principal de la cita, `from` (por defecto hoy) + `BookingOptions::days()` días (7).
- `AvailabilityResolverService::resolve()` → mismos horarios de inicio que el formulario (se hace público el cálculo de `hourly()` para no duplicarlo), con la duración de la cita.
- Excluye el horario actual de la cita (como `RescheduleFlow::withoutCurrent()`).
- Respuesta: `{days: [{date, label, times: ['07:30', …]}], source: 'smd'|'local', degraded}`. Si SMD no responde: lista vacía + mensaje, **no** se ofrecen horarios sin validar.
- Cuota SMD: 1 consulta por apertura/semana; se cachea 5 min con la misma clave que `BookingOptions`.

**Aviso al paciente (D8)** — en `AppointmentRescheduler`, solo con origin `crm`:
- Destinatario: quien agendó (`booked_by_person_id`) o el paciente, igual que los recordatorios.
- Busca su conversación; si `MessagingWindow::compute(gateway->messagingWindowHours(), last_inbound_at)->open` → encola un **texto** ("Tu cita con Dr(a). X se cambió al mar 3/11 a las 09:00. …") con `SendMessage`, `sender = system`.
- Ventana cerrada (o sin conversación todavía) → depende de la bandera D9:
  - `WHATSAPP_RESCHEDULE_TEMPLATE_ENABLED=true` → crea/usa la conversación (`ConversationResolver::findOrCreate`, como los recordatorios) y encola la **plantilla** `appointment_rescheduled` por el gateway `cloud_api` (Kommo no puede iniciar conversación; mismo criterio que `reminders.gateway`). `notified: 'template'`.
  - `false` (por defecto) → **no se manda nada**, queda en el log y la respuesta dice `notified: false` para que la recepcionista avise ella.
- Sin teléfono → no se manda nada, `notified: false`.
- Respuesta del endpoint: `notified: 'text' | 'template' | false`.
- Origin `whatsapp` no manda este aviso (el formulario ya responde "✅ Cita reprogramada").

**Plantilla (D9)** — hoy **no existe** ninguna de cambio de cita: hay `recordatorio_cita` y `recordatorio_doctores` (`Config/whatsapp.php:72-85`) y las de `Config/templates.php` (bienvenida, recontacto, saludo).
- Se define en `Config/templates.php`, categoría **UTILITY** (aviso transaccional de una cita existente):
  > Hola {{1}}, le informamos que su cita en la Clínica Odontoking fue reprogramada para el {{2}} con el Dr(a). {{3}}. Si necesita otro horario, responda a este mensaje.

  `{{1}}` nombre de quien recibe · `{{2}}` "Martes 3 de Noviembre a horas 09:30" (mismo formato que el recordatorio) · `{{3}}` doctor (con el `trim`/colapso de espacios del commit `1c09a1f`).
- Se registra en `gateways.cloud_api.templates.appointment_rescheduled` → `name: env('WHATSAPP_RESCHEDULE_TEMPLATE_NAME', 'cita_reprogramada')`, `language: es`.
- Alta en Meta: `php artisan whatsapp:templates-create cita_reprogramada`; Meta la revisa (minutos a horas); verificar con `php artisan whatsapp:templates --fresh`.
- **La bandera solo se enciende después de que Meta la apruebe.** Con la bandera encendida y la plantilla no aprobada, Meta rechaza el envío: el mensaje queda `failed` en el inbox y la respuesta dice `notified: false` (no se reintenta en loop).
- `.env`:
  ```
  WHATSAPP_RESCHEDULE_TEMPLATE_ENABLED=false
  WHATSAPP_RESCHEDULE_TEMPLATE_NAME=cita_reprogramada
  ```

### Frontend (calendario del CRM, `doctor-day-calendar.blade.php`)

Sigue el lenguaje visual existente del modal (Tailwind del Admin, `ddc-state-badge`, textos chicos grises); nada de componentes nuevos de diseño.

1. **Botón "Reprogramar"** en el detalle de la cita, junto a los botones de estado/editar. Solo en citas futuras y no canceladas. Abre la **sección de reprogramación** dentro del mismo modal (como el modo "Editar" inline), no un modal encima de otro:

   ```
   ← Reprogramar cita · MARIA PEREZ · Dra. CAROLINA ROJAS
   Ahora: vie 30/10 · 17:00

   [ lun 2/11 ] [ mar 3/11 ] [ mié 4/11 ] [ jue 5/11 ] …   ‹ semana ›
   ┌──────────────────────────────────────────────┐
   │ 07:30  08:30  09:30  10:30  11:30  12:30     │  ← horarios libres
   │ 15:30  16:30  18:00                          │
   └──────────────────────────────────────────────┘
   ↻ La cita del vie 30/10 17:00 queda cancelada y se crea
     una nueva el mar 3/11 09:30.          (role="status")
   📱 Se le avisará por WhatsApp.                       (ventana abierta)
   📱 Fuera de las 24 h: se le avisará con plantilla.   (bandera encendida)
   ⚠ Fuera de las 24 h: no se le avisará, avisale vos.  (bandera apagada)
                                    [Cancelar]  [Reprogramar]
   ```

   - Días: botones con la cantidad de horarios ("mar 3/11 · 9"); días sin horarios deshabilitados con texto "sin horarios", no solo grises. Navegación por semana (‹ ›).
   - Horarios: `role="radiogroup"` de botones; flechas/Tab + Enter; el elegido con borde y check, no solo color.
   - Estados: **cargando** (esqueleto de chips, `aria-busy`), **sin horarios** en la semana ("Sin horarios libres con la Dra. X esta semana" + ‹ semana siguiente ›), **SMD sin respuesta** ("No pudimos consultar la agenda de SMD. Probá de nuevo." + Reintentar).
   - "Reprogramar" deshabilitado hasta elegir un horario; muestra spinner mientras guarda.
   - Línea de aviso al paciente (D8) calculada antes de confirmar: el endpoint de horarios devuelve también `window_open`.
2. **Resultado**: flash con el texto del backend ("Cita reprogramada: la del 30/10 17:00 quedó cancelada y se creó la del 3/11 09:30." + "Se avisó por WhatsApp" / "No se avisó: fuera de las 24 h"). El detalle pasa a mostrar la **cita nueva**. Si falla (p. ej. el horario se ocupó), el error se muestra en la sección, la lista se recarga y la cita no cambia (D4).
3. **"Editar cita"** (ver P2): si se mantiene la edición de fecha/hora, al cambiarlas aparece la misma línea "↻ La cita … queda cancelada y se crea una nueva…" y el botón pasa a "Reprogramar".
4. **Vínculo en el detalle de la cita** (debajo del encabezado, D6):
   - en la nueva: "↻ Reprogramada desde **vie 30/10 · 17:00**" (botón → abre la vieja);
   - en la vieja: "↻ Reprogramada al **mar 3/11 · 09:00**" (botón → abre la nueva).

   Botones reales (`<button>`, foco visible como `.ddc-history-row:focus-visible`), reusando `openHistoryAppointment()`.
5. **Historial del paciente** (`:1287`): la fila de la vieja muestra su badge "Cancelada" + un sufijo "↻ → 3/11"; la nueva "↻ ← 30/10". Texto, no solo ícono.
6. **Colores**: solo los ya presentes en el modal (`apptStateBadgeStyle`), con `:style` inline si hace falta un color calculado (las clases calculadas en runtime no llegan al CSS de producción).

### Indicador de recordatorio (D10)

**Decisión (usuario, 2026-10-30):** cada cita muestra si se le envió el recordatorio.

**Hechos:**
- Cada recordatorio enviado deja una fila en `whatsapp_appointment_reminders` (`activity_id` único, `external_id`, `message_id`, `sent_at`, `response`, `responded_at`) — migraciones `2026_07_17_000008`, `2026_07_20_000009`, `2026_07_23_000010`, `2026_10_27_000001`.
- El estado de entrega lo actualiza el webhook de Meta en `whatsapp_messages.status` con rango `queued < sent < delivered < read`, `failed` (`IngestInboundPayload.php:203`).
- La idempotencia de los recordatorios compara por `activity_id` **o** `external_id` de SMD (`SendAppointmentReminders::dueAppointments()`), porque una cita re-sincronizada cambia de `activity_id`. El indicador usa la misma regla.
- Hoy en producción hay 143 mensajes de tipo plantilla en `failed` (no necesariamente recordatorios): el indicador los haría visibles.

**Estados** (derivados, sin columnas nuevas; el más informativo gana):

| Estado | Condición | Se muestra |
|---|---|---|
| `none` | sin fila de recordatorio | 🔕 "Sin recordatorio" (gris) |
| `queued` | mensaje `queued` | 🔔 "Recordatorio en cola" |
| `sent` | `sent` | 🔔 "Recordatorio enviado" ✓ |
| `delivered` | `delivered` | 🔔 "Recordatorio entregado" ✓✓ |
| `read` | `read` | 🔔 "Recordatorio leído" ✓✓ |
| `failed` | `failed` | ⚠ "Recordatorio falló" + motivo (`whatsapp_messages.error`) |
| `confirmed` / `declined` | `response` = SÍ / NO | ✅ "Confirmó por WhatsApp" / ✖ "Respondió NO" |

**Backend:**
- `ActivityController::get()` (datos del calendario) agrega `reminder_status`, `reminder_sent_at` y `reminder_response` con **un LEFT JOIN** a una subconsulta agregada por `activity_id` (y por `external_id`, como la idempotencia). Sin consultas por cita (lección del N+1 de `custom-attribute`).
- El detalle de la cita recibe además `reminder_error` cuando `failed`.

**Frontend** (`doctor-day-calendar.blade.php`):
1. **Tarjeta de la cita en el calendario:** un ícono chico de campana en la esquina (🔔 / 🔕 / ⚠ / ✅), con `title` y `aria-label` con el texto del estado ("Recordatorio entregado el vie 30/10 08:00"). El ícono + texto lo hacen legible sin depender del color. Solo en citas de paciente (`meeting`), no en bloqueos de agenda.
2. **Detalle de la cita:** una línea bajo la fecha: "Recordatorio: **Entregado** · vie 30/10 08:00 · respondió **SÍ**". Si falló: "Recordatorio: **Falló** — <motivo de Meta>" en el color de error ya usado en el modal.
3. **Historial del paciente:** sin cambios (no sobrecargar las filas).
4. Colores con `:style` inline desde un mapa estado → color (las clases calculadas en runtime no llegan al CSS de producción).

**Con la reprogramación:** la cita nueva arranca en `none` y recibe su recordatorio en la próxima corrida horaria; la vieja conserva el estado que tenía (histórico).

**Tests:**
- R1. `get()` devuelve `reminder_status` correcto para: sin fila, `sent`, `delivered`, `read`, `failed`, respondida SÍ/NO.
- R2. Cita re-sincronizada (otro `activity_id`, mismo `external_id`) muestra el recordatorio de la original.
- R3. Sin N+1: el número de consultas de `get()` no crece con la cantidad de citas (comparar 2 vs 20 citas).
- R4. El Blade del calendario incluye el indicador con `aria-label` y la línea del detalle.

### Fuera de alcance

- Pantalla clásica `activities/edit/{id}` de Krayin: no se toca; reprogramar se hace desde el calendario.
- Tablero/KPIs: sin cambios (D3).
- Citas históricas ya "movidas" antes de este cambio: no se reconstruyen.

## Tests (Pest, escritos antes del código)

Backend:
1. Formulario: reprogramar deja la vieja **cancelada** (etapa, `status=0`, SMD `CANCELED`) y crea una **nueva** (otro lead, otro evento SMD, hora nueva) con el vínculo en ambas.
2. Formulario: horario ocupado → nada cambia, mensaje al paciente.
3. CRM: cambiar fecha/hora en `updateAppointment` = test 1, y la nueva incluye lo editado (p. ej. motivo/servicio).
4. CRM: cambiar solo motivo/doctor/estado **no** reprograma: misma cita, sin lead nuevo.
5. Mismo día y mismo doctor: no lo bloquea "ya tiene una cita ese día".
6. Superpuesto + falla de SMD al crear: la vieja **vuelve** a su etapa/estado previos.
7. La nueva copia recepcionista asignada y `booked_by_person_id`.
8. Recordatorios: la nueva entra en `dueAppointments`, la vieja no.
9. `personAppointments` devuelve `rescheduled_from`/`rescheduled_to`.
10. Se actualizan los tests actuales de reprogramar (`WhatsappAppointmentChangeFlowsTest`), que hoy esperan que la cita se mueva.
11. `reschedule-slots`: devuelve los horarios libres del doctor de la cita (SMD fake ∩ turnos − citas), sin el horario actual, contados desde el inicio del turno (07:30 → 07:30).
12. `reschedule-slots` con SMD caído: lista vacía + `failed`, nunca horarios sin validar.
13. `POST …/reschedule`: horario que ya no está libre → 422, la vieja intacta.
14. Aviso D8: conversación con mensaje entrante hace < 24 h → se encola 1 **texto** con la nueva fecha (`notified: 'text'`); hace > 24 h o sin conversación con la bandera apagada → 0 mensajes y `notified: false`.
14b. Bandera D9 encendida + fuera de la ventana → 1 mensaje `type=template` con `payload.template = appointment_rescheduled`, variables `[nombre, fecha, doctor]` en orden y gateway `cloud_api` (`notified: 'template'`); dentro de la ventana sigue saliendo texto, no plantilla.
14c. La plantilla `cita_reprogramada` está en `Config/templates.php` como UTILITY con 3 variables.
15. Aviso D8 con cita agendada por un tercero → el texto va a quien agendó.
16. Origin `whatsapp` no manda el aviso D8 (sin mensaje duplicado).

Frontend (render del Blade, como el resto de la suite):
17. El detalle muestra el botón "Reprogramar" solo en citas futuras no canceladas, y la sección con sus estados (cargando / sin horarios / error SMD).
18. El detalle muestra el vínculo con su botón.

Verificación manual en el navegador (Playwright MCP): reprogramar una cita de prueba desde el calendario eligiendo día y hora de la lista; teclado (Tab/flechas/Enter) en días y horarios; estados de carga, vacío y error; sin errores de consola; modal a ancho móvil (320 px) sin scroll horizontal.

## Orden de trabajo

1. Tests backend 1-10 en rojo.
2. `process()` + `replaces_activity_id`.
3. `AppointmentRescheduler` (normal → superpuesto → restauración).
4. Formulario de WhatsApp.
5. `reschedule-slots` + `POST …/reschedule` + aviso D8 (tests 11-16).
6. `updateAppointment` + `personAppointments`.
7. UI del calendario: botón y sección de horarios, vínculo, historial (tests 17-18).
7b. Indicador de recordatorio (D10, tests R1-R4). Independiente del resto: se puede entregar primero.
8. Pint solo sobre los archivos tocados, suite completa, prueba en navegador.
9. Alta de la plantilla en Meta (`whatsapp:templates-create cita_reprogramada`) → esperar aprobación → recién ahí `WHATSAPP_RESCHEDULE_TEMPLATE_ENABLED=true` + `config:clear`.
10. `php artisan queue:restart` en el contenedor (el job del formulario corre en los workers).

## Preguntas abiertas

- ~~P1~~ Resuelta por D8: se avisa solo dentro de la ventana de 24 h.
- ~~P2~~ **Decidido (usuario, 2026-10-30):** en "Editar cita" la fecha y la hora quedan **bloqueadas** ("Para cambiar el horario usá Reprogramar"); el punto 3 de Frontend no aplica. `updateAppointment` rechaza (422) un cambio de fecha/hora que llegue por API. **El bloqueo es solo de la UI del CRM:** la integración con SMD/Dropbox **sigue pudiendo cambiar el horario** de una cita (SMD es la fuente de verdad) y la sync lo aplica sobre la misma cita, como hoy — no cancela ni crea.
- **P5 (no bloquea, a futuro):** si SMD/Dropbox mueve el horario de una cita que **ya tenía recordatorio enviado**, hoy no se manda otro (la idempotencia es por cita/`external_id`). El indicador lo mostrará como "enviado" aunque fuera para el horario anterior. Propuesta: guardar en el recordatorio la fecha recordada y mostrar "enviado para el horario anterior"; se decide aparte.
- ~~P3~~ Confirmada: ventana de 24 h de WhatsApp (D8).
- ~~P4~~ **Aprobado (usuario, 2026-10-30):** el texto de la plantilla `cita_reprogramada` propuesto arriba.
- **Orden aprobado:** primero el indicador de recordatorio (D10), después la reprogramación.
