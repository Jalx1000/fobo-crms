# Tareas — Sprint 08 · Módulo WhatsApp

Spec: [`spec-sprint-08-whatsapp.md`](./spec-sprint-08-whatsapp.md) · Plan: [`plan.md`](./plan.md)
Rama: `kohlberg-wa-v2`

Ninguna tarea toca más de 5 archivos. El orden es por dependencia, no por importancia.

---

## Fase 0 · Precondiciones (antes de escribir código)

- [x] **P1 · Verificar plantillas aprobadas en Meta** — hecho 2026-09-01
  - Resultado: **una sola plantilla, y es la de ejemplo de Meta.**
    ```
    NOMBRE         IDIOMA  ESTADO     CATEGORIA  VARS
    hello_world    en_US   APPROVED   UTILITY    0
    ```
  - 🔴 **R1 materializado.** No hay ninguna plantilla de negocio aprobada, en español ni con
    variables. Ver `plan.md` §4 R1 para el impacto y la decisión pendiente.

- [ ] **P1b · Definir y dar de alta las plantillas de negocio** ← BLOQUEANTE de A6/A7/A8
  - Acceptance: al menos 2 plantillas en español, con sus variables, enviadas a aprobación de Meta
  - Verify: `curl` a `/message_templates` y verlas en `APPROVED`
  - Files: ninguno — es alta en WhatsApp Manager
  - ⚠️ Meta tarda 24-48 h. Cuanto antes se envíen, menos bloquea la fase 3

- [x] **P2 · Crear la rama**
  - Acceptance: `kohlberg-wa-v2` existe, partiendo de `kohlberg-whatsapp`
  - Verify: `git status` muestra la rama y el árbol limpio
  - Files: ninguno

- [x] **P3 · Guardarraíl de datos en los tests** — hecho 2026-09-01
  - Acceptance: un `truncate` sobre una tabla de negocio lanza excepción antes de ejecutarse
  - Verify: `tests/Feature/TestDatabaseGuardTest.php` — 5 casos que prueban el corte directamente
  - Files: `tests/TestCase.php`, `tests/Pest.php`, `tests/Feature/ProductosByCiudadSucursalTest.php`, `tests/Feature/TestDatabaseGuardTest.php`

- [x] **P4 · Cómo correr los tests** — descubierto 2026-09-01
  - `heaven_kohlberg` (el host de la base) **no resuelve desde el shell del servidor**: solo desde
    dentro del contenedor de la app. Un `php artisan test` desde afuera falla con
    `getaddrinfo ... Temporary failure in name resolution`, y los tests que no tocan la base
    "pasan" dando una falsa sensación de verde.
  - Comando correcto:
    ```bash
    docker exec heaven_kolberg_laravel.1.<id> sh -lc 'cd /code && php artisan test'
    ```
  - El código del repo está montado en `/code` dentro del contenedor.

---

## Fase 1 · D + E — sin dependencias (1,5 d)

- [ ] **D1 · Formatear la moneda en el backend**
  - Acceptance: `ChatController::person()` devuelve los importes ya formateados con el símbolo de `config('whatsapp.currency', 'BOB')`; hoy manda `number_format()` pelado
  - Verify: `curl` al endpoint del perfil y ver `"Bs 239,00"` en `products[].amount`
  - Files: `packages/Webkul/Whatsapp/src/Http/Controllers/ChatController.php`, `packages/Webkul/Whatsapp/src/Config/whatsapp.php`

- [ ] **D2 · Sacar el `$` hardcodeado del blade**
  - Acceptance: las 5 ocurrencias (líneas 445, 481, 565, 569, 617) dejan de escribir el símbolo y muestran lo que manda el backend
  - Verify: navegador — panel del cliente, pestaña Productos y pestaña Info
  - Files: `packages/Webkul/Whatsapp/src/Resources/views/inbox-component.blade.php`

- [ ] **E1 · Migración `ai_disabled_at`**
  - Acceptance: la columna existe, nullable, después de `ai_enabled`
  - Verify: `php artisan migrate` y `DESCRIBE kl_whatsapp_conversations`
  - Files: `packages/Webkul/Whatsapp/src/Database/Migrations/2026_09_01_000001_add_ai_disabled_at_to_whatsapp_conversations.php`

- [ ] **E2 · Estampar la marca al apagar el agente**
  - Acceptance: `toggleAgent()` guarda `ai_disabled_at = now()` al apagar y `null` al encender
  - Verify: apagar el switch en el inbox y mirar la fila
  - Files: `packages/Webkul/Whatsapp/src/Http/Controllers/InboxController.php`, `packages/Webkul/Whatsapp/src/Models/Conversation.php`

- [ ] **E3 · Config de reactivación**
  - Acceptance: `whatsapp.ai.reactivate` con `enabled`, `after_minutes` (30) y `after_hours` (respaldo)
  - Verify: `php artisan tinker` → `config('whatsapp.ai.reactivate')`
  - Files: `packages/Webkul/Whatsapp/src/Config/whatsapp.php`, `.env.example`

- [ ] **E4 · Comando `whatsapp:reactivate-agent`**
  - Acceptance: reactiva solo `ai_enabled = 0` explícito; usa `COALESCE(ai_disabled_at, updated_at)`; un mensaje posterior al corte reinicia la espera; `--dry-run` no escribe
  - Verify: `php artisan whatsapp:reactivate-agent --dry-run`
  - Files: `packages/Webkul/Whatsapp/src/Console/Commands/ReactivateAgent.php`, `packages/Webkul/Whatsapp/src/Providers/WhatsappServiceProvider.php`

- [ ] **E5 · Registrar en el scheduler**
  - Acceptance: corre cada 5 min con `withoutOverlapping()`
  - Verify: `php artisan schedule:list`
  - Files: `app/Console/Kernel.php`

- [ ] **E6 · Tests de reactivación**
  - Acceptance: cubre `ai_disabled_at` NULL (fila vieja), mensaje posterior al corte, y `ai_enabled` NULL que **no** se toca
  - Verify: `php artisan test tests/Feature/WhatsappReactivateTest.php`
  - Files: `tests/Feature/WhatsappReactivateTest.php`

> **Checkpoint 1** — moneda en Bs en los 5 lugares; el agente vuelve solo a los 30 min.

---

## Fase 2 · B0 — contrato del gateway (2 d)

- [x] **B0.1 · DTOs de salida**
  - Acceptance: `OutboundLocation` (con `fromArray`, `toArray`, `label`) y `OutboundMedia`
  - Verify: `php artisan test --filter=OutboundLocation`
  - Files: `Gateways/Dto/OutboundLocation.php`, `Gateways/Dto/OutboundMedia.php`

- [x] **B0.2 · Ampliar el interface + los dos gateways, en UN commit**
  - Acceptance: `Gateway` declara los tres métodos nuevos; `CloudApiGateway` los implementa; `KommoGateway` los implementa lanzando "no soportado"; el contenedor arranca
  - Verify: `php artisan about` sin fatal + `php artisan test --filter=Gateway`
  - Files: `Gateways/Contracts/Gateway.php`, `Gateways/CloudApi/CloudApiGateway.php`, `Gateways/Kommo/KommoGateway.php`
  - ⚠️ **Riesgo R2 — eliminado, no mitigado.** En vez de stubs en Kommo, los tres métodos tienen
    **implementación por defecto en `BaseGateway`** que lanza `CapabilityNotSupportedException`.
    Así, agregar un método al contrato nunca puede dejar el CRM sin arrancar: un driver que no lo
    implemente responde 501. Verificado: Kommo devuelve 501 ante una plantilla.

- [x] **B0.3 · Declarar capacidades reales**
  - Acceptance: `Capabilities::$send` de Cloud API incluye `template`, `image`, `document`, `video`, `location` — y solo lo que funciona
  - Verify: `php artisan whatsapp:webhook-info`
  - Files: `Gateways/CloudApi/CloudApiGateway.php`

- [x] **B0.4 · `SendMessage` despacha por tipo**
  - Acceptance: `match ($message->type)` enruta a cada método; el `catch` que guarda `status='failed'` + `error` se conserva
  - Verify: `php artisan test tests/Feature/WhatsappGatewayDispatchTest.php`
  - Files: `Jobs/SendMessage.php`

- [ ] **B0.5 · Guardarraíl 501**
  - Acceptance: si `canSend($type)` es falso → 501 y **no** se crea el mensaje
  - Verify: test que pide un tipo no soportado y comprueba que `whatsapp_messages` no creció
  - Files: `Http/Controllers/InboxController.php`, `tests/Feature/WhatsappGatewayDispatchTest.php`

> **Checkpoint 2** — el contenedor arranca, los dos gateways compilan, un tipo no soportado da 501.

---

## Fase 3 · A — plantillas (5 d)

- [ ] **A0 · Adelantar `ai.test_mode` desde F**
  - Acceptance: el agente solo responde a los números de la lista; lista vacía = no hace nada
  - Verify: `php artisan tinker` con un número dentro y otro fuera
  - Files: `Config/whatsapp.php`, `Jobs/IngestInboundPayload.php`, `Jobs/NotifyAgent.php`
  - Nota: es la única pieza de F que se adelanta; sirve para estrenar A y B sin tocar a todos los clientes

- [x] **A1 · `TemplateCatalog`**
  - Acceptance: lee de la Graph API, cachea por `templates.cache_minutes` (30), expone `all()` y `find($name, $lang)` con `name/language/body/variables`
  - Verify: `php artisan whatsapp:templates`
  - Files: `Services/TemplateCatalog.php`, `Config/whatsapp.php`

- [x] **A2 · Comando `whatsapp:templates --fresh`**
  - Acceptance: lista las plantillas; `--fresh` invalida el caché
  - Verify: `php artisan whatsapp:templates --fresh`
  - Files: `Console/Commands/ListTemplates.php`, `Providers/WhatsappServiceProvider.php`

- [x] **A3 · `CloudApiGateway::sendTemplate()`**
  - Acceptance: arma el payload `type: template`; idioma explícito gana sobre el de la plantilla; fallback `es`
  - Verify: envío real a un teléfono de prueba
  - Files: `Gateways/CloudApi/CloudApiGateway.php`

- [x] **A4 · Endpoints `templates` y `send-template`**
  - Acceptance: cantidad de variables distinta → 422 con motivo; variable vacía → 422; agente activo → 422; el mensaje se guarda con `payload` completo **incluso si el envío luego falla**
  - Verify: `php artisan test tests/Feature/WhatsappTemplateTest.php`
  - Files: `Http/Controllers/InboxController.php`, `Routes/admin.php`, `tests/Feature/WhatsappTemplateTest.php`

- [x] **A5 · Mockup del selector de plantillas** ← confirmar antes de A6
  - Acceptance: layout en texto/ASCII aprobado
  - Verify: aprobación explícita
  - Files: ninguno

- [x] **A6 · Selector en el composer**
  - Acceptance: lista, un input por variable, preview con variables sustituidas; aparece en el cartel de "ventana de 24 h cerrada"
  - Verify: navegador, con la ventana cerrada
  - Files: `Resources/views/inbox-component.blade.php`

- [ ] **A7 · `getTemplateSendStats()`**
  - Acceptance: agrupa por `payload->>'$.template'` y estado, filtrando `type='template'` **y** `sender='human'`, en el rango de fechas del tablero, contando por `created_at`
  - Verify: `php artisan tinker` con el helper
  - Files: `packages/Webkul/Admin/src/Helpers/Dashboard.php`, `packages/Webkul/Admin/src/Http/Controllers/DashboardController.php`

- [ ] **A8 · Card del tablero, solo admin**
  - Acceptance: barras apiladas por estado + tasa de entrega; dentro del `@if ($isAdminRole)` que ya existe en la línea 55
  - Verify: login como admin (se ve) y como Encargado (no se ve)
  - Files: `packages/Webkul/Admin/src/Resources/views/dashboard/index/plantillas-enviadas.blade.php`, `.../dashboard/index.blade.php`

> **Checkpoint 3** — una plantilla real llega a un teléfono real; el gráfico solo lo ve el admin.
>
> ✅ **Mitad de envío verificada el 2026-09-01.** `hello_world` enviada a `+59176616013` por el
> camino completo (controlador → job → gateway → Meta), con la cola en modo `sync` para que no
> quedara nada en redis que un worker pudiera volver a mandar:
> ```
> HTTP 200 · mensaje #314 · type=template
> wamid.HBgLNTkxNzY2MTYwMTMVAgARGBJEOUMxOEQ3MEQxMkNCNkMyRkEA
> status: queued → sent → delivered
> ```
> El paso a `delivered` confirma además que el webhook de recibos actualiza el estado — que es
> el dato del que vive la tasa de entrega del reporte (A8).
>
> ⏳ Falta la mitad del gráfico (A7/A8) y el selector con variables (A6), que espera a P1b.

---

## Fase 4 · B — handoff (4 d)

- [x] **B1 · Migración del handoff**
  - Acceptance: 6 columnas + índice `wa_conv_handoff_idx`; `assigned_user_id` es `unsignedInteger`
  - Verify: `php artisan migrate` y `SHOW INDEX`
  - Files: `Database/Migrations/2026_09_01_000002_add_handoff_to_whatsapp_conversations.php`
  - ⚠️ `bigInteger` falla con errno 150: `users.id` es `increments()`

- [x] **B2 · Constantes y helpers en `Conversation`**
  - Acceptance: `HANDOFF_*`, `handoffOpen()`, `handoffPooled()`, relación `assignedUser`
  - Verify: `php artisan test --filter=Conversation`
  - Files: `Models/Conversation.php`

- [x] **B3 · Config del handoff**
  - Acceptance: `handoff.exclude_roles` = todos los roles menos `Encargado` (incluye `Sistema`), y `handoff.notify`
  - Verify: `config('whatsapp.handoff')`
  - Files: `Config/whatsapp.php`

- [x] **B4 · `Services\Handoff` — request y ownerFor**
  - Acceptance: idempotente bajo `lockForUpdate()`; rutea lead → contacto → pool; excluye roles de gestión y usuarios inactivos; deja actividad `note`
  - Verify: `php artisan test tests/Feature/WhatsappHandoffTest.php`
  - Files: `Services/Handoff.php`, `Exceptions/HandoffConflictException.php`, `tests/Feature/WhatsappHandoffTest.php`

- [x] **B5 · `claim`, `reassign`, `resolve`**
  - Acceptance: `claim()` es un UPDATE condicional — dos asesores simultáneos y exactamente uno gana
  - Verify: test de carrera con dos llamadas
  - Files: `Services/Handoff.php`, `tests/Feature/WhatsappHandoffTest.php`

- [x] **B6 · Endpoint del agente**
  - Acceptance: `POST /api/v1/whatsapp/conversations/{id}/handoff`; devuelve `assigned_user` **entero** vía `toAgentArray()`; después de derivar, `POST /messages` da 409
  - Verify: test dedicado a la forma del JSON + test del 409
  - Files: `Http/Controllers/Api/AgentController.php`, `Routes/api.php`, `tests/Feature/WhatsappHandoffTest.php`
  - ⚠️ **Riesgo R3.** Avisar al equipo del agente antes de desplegar

- [x] **B7 · Aviso por mail**
  - Acceptance: `NotifyHandoff` manda al asesor asignado, o al equipo si quedó en el pool; se despacha fuera de la transacción
  - Verify: `MAIL_MAILER=log` y revisar el log
  - Files: `Jobs/NotifyHandoff.php`, `Mail/HandoffRequested.php`, `Resources/views/emails/handoff/requested.blade.php`

- [ ] **B8 · Plazo largo en la reactivación** (cierra el R6)
  - Acceptance: una derivación pedida por el cliente espera `handoff_after_minutes` (180), no 30
  - Verify: test con las dos conversaciones
  - Files: `Console/Commands/ReactivateAgent.php`, `Config/whatsapp.php`

- [x] **B9 · Mockup del inbox derivado** ← confirmar antes de B10
  - Acceptance: chip, banner y botón aprobados en texto
  - Files: ninguno

- [x] **B10 · UI del handoff**
  - Acceptance: chip "Sin asesor · Nm", banner con el motivo, botón "Tomar conversación", filtros derivadas/sin asesor/míos
  - Verify: navegador con dos usuarios
  - Files: `Resources/views/inbox-component.blade.php`, `Http/Controllers/ChatController.php`, `Routes/admin.php`

> **Checkpoint 4** — ✅ **verificado 2026-09-01**, con un cambio de diseño sobre lo planificado:
> el ruteo es **por ciudad**, no por dueño del lead. En kohlberg cada ciudad es un pipeline con
> un Encargado, y medido sobre datos reales solo 2 de 26 conversaciones tienen lead — rutear por
> dueño habría dejado 24 sin asesor.
>
> Probado en el navegador sobre la conversación #1: banner
> `DERIVADA · Santa Cruz · Atiende Liliana Alarcon`, con motivo y nota en el timeline.
> 12 tests, incluida la carrera de dos `claim()` simultáneos.
>
> Contrato para el agente: `docs/agent/derivacion-a-asesor.md`.

---

## Fase 5 · C — ubicación (2,5 d)

- [x] **C1 · `sendLocation` en los dos gateways**
  - Acceptance: Cloud API manda `type: location`; Kommo responde "no soportado"
  - Verify: envío real
  - Files: `Gateways/CloudApi/CloudApiGateway.php`, `Gateways/Kommo/KommoGateway.php`

- [x] **C2 · Endpoint `send-location`**
  - Acceptance: valida `latitude` entre -90 y 90, `longitude` entre -180 y 180; canal sin soporte → 422 antes de encolar; guarda `payload = ['location' => …]`
  - Verify: `php artisan test tests/Feature/WhatsappLocationTest.php`
  - Files: `Http/Controllers/InboxController.php`, `Routes/admin.php`, `tests/Feature/WhatsappLocationTest.php`

- [x] **C3 · Ubicaciones guardadas**
  - Acceptance: `config('whatsapp.locations')` se sirve al composer; entradas sin coordenadas se descartan
  - Verify: agregar una en config y verla en el modal
  - Files: `Config/whatsapp.php`, `Http/Controllers/InboxController.php`

- [x] **C4 · Mockup del picker** ← confirmar antes de C5
  - Acceptance: layout del modal aprobado
  - Files: ninguno

- [x] **C5 · Picker de mapa**
  - Acceptance: GPS del navegador con precisión, mapa Leaflet con pin arrastrable, lat/lng a mano, atajos guardados, campos nombre y dirección
  - Verify: navegador — arrastrar el pin y enviar a un teléfono real
  - Files: `Resources/views/inbox-component.blade.php`
  - ⚠️ Leaflet por CDN en runtime; el mapa **no** va en `data()`; invalidar el tamaño tras montar dentro del modal

- [x] **C6 · Burbuja de ubicación**
  - Acceptance: enlace a Google Maps con nombre, dirección y coordenadas; `locationOf()` lee **las dos formas** del payload (nuestra y la del proveedor)
  - Verify: mandar una desde el CRM y recibir otra desde un teléfono
  - Files: `Resources/views/inbox-component.blade.php`, `Http/Controllers/InboxController.php`

> **Checkpoint 5** — ✅ **verificado 2026-09-01.** Enviados a `+59176616013` por el camino
> completo, los tres en estado `read`:
> ```
> #314  template   read     hello_world
> #315  location   read     Kohlberg Santa Cruz (-17.78333, -63.18214)
> #320  image      read     prueba.png · image/png
> ```
> El adjunto además confirma lo que motiva a `MediaController`: Meta descargó el archivo desde
> `https://kohlberg.sofopolis.com/whatsapp/media/…` y la respuesta llegó con
> `content-type: image/png`, que es el mismo tipo que declaramos al enviar.

---

## Fase 6 · F — consolidación (3 d)

- [x] **F1 · `MediaController` con mime propio**
  - Acceptance: sirve el archivo con el mime guardado en el mensaje, no el que adivina el web server
  - Verify: mandar un audio y que WhatsApp lo acepte
  - Files: `Http/Controllers/MediaController.php`, `Routes/api.php`

- [x] **F2 · Media saliente**
  - Acceptance: el composer manda imagen, documento y audio
  - Verify: los tres a un teléfono real
  - Files: `Http/Controllers/InboxController.php`, `Gateways/CloudApi/CloudApiGateway.php`, `Resources/views/inbox-component.blade.php`

- [ ] **F3 · `ai.blocked_numbers`**
  - Acceptance: números que el agente nunca contesta, pasen lo que pasen los switches
  - Verify: test unitario del matcher
  - Files: `Config/whatsapp.php`, `Jobs/IngestInboundPayload.php`

- [ ] **F4 · Contadores del pedido de teléfono**
  - Acceptance: `phone_prompt_attempts` y `phone_rechazado` en la conversación, expuestos al agente
  - Verify: migración + endpoint
  - Files: `Database/Migrations/2026_09_01_000003_add_phone_fields_to_whatsapp_conversations.php`, `Services/AgentPayload.php`

- [ ] **F5 · `whatsapp:webhook-info` ampliado**
  - Acceptance: reporta capacidades del gateway y cantidad de plantillas cacheadas
  - Verify: `php artisan whatsapp:webhook-info`
  - Files: `Console/Commands/WebhookInfo.php`

> **Checkpoint 6** — un audio sale con el mime correcto y WhatsApp lo acepta.

---

## Resumen

| Fase | Tareas | Días |
|---|---|---|
| 0 · Precondiciones | 4 (2 hechas) | — |
| 1 · D + E | 8 | 1,5 |
| 2 · B0 | 5 | 2 |
| 3 · A | 9 | 5 |
| 4 · B | 10 | 4 |
| 5 · C | 6 | 2,5 |
| 6 · F | 5 | 3 |
| **Total** | **47** | **18** |
