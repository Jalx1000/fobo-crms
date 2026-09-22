# Tareas — Mensajes interactivos por marcador `1.-)`

Spec: [`spec-mensajes-interactivos.md`](./spec-mensajes-interactivos.md) · Plan: [`plan-mensajes-interactivos.md`](./plan-mensajes-interactivos.md)
Rama: `kohlberg-wa-v2` · 26 tareas · **25 hechas; falta 7.2 (entregar el doc al equipo del agente)**

Comando de test (siempre dentro del contenedor):
```bash
docker exec heaven_kolberg_laravel.1.ww3k6gzeul1hp7t9329x8x441 \
    sh -lc 'cd /code && php artisan test tests/Feature/WhatsappInteractiveSendTest.php'
```

---

## F1 · DTO y excepción

- [x] **1.1** Crear `Exceptions/InteractiveMessageException.php`
  - Acceptance: constructores nombrados `tooMany($que, $cuantos, $limite)` y `tooLong($campo, $largo, $limite)`; el mensaje nombra los tres datos; `status()` devuelve 422
  - Verify: `php -l` + se usa en 1.2
  - Files: `packages/Webkul/Whatsapp/src/Exceptions/InteractiveMessageException.php`

- [x] **1.2** Crear `Gateways/Dto/OutboundInteractive.php`
  - Acceptance: `final`; constantes `MAX_BUTTONS=3`, `MAX_ROWS=10`, `MAX_BUTTON_TITLE=20`, `MAX_ROW_TITLE=24`, `MAX_BODY=1024`, `MAX_FOOTER=60`; constructores `buttons()` y `list()`; constructor privado; `toArray()`
  - Verify: tests de 1.3
  - Files: `Gateways/Dto/OutboundInteractive.php`

- [x] **1.3** Tests del DTO
  - Acceptance: un test por límite — 4 botones, 11 filas, título de 21 (botón) y 25 (fila), body de 1100, footer de 70, lista vacía. Cada uno afirma el campo y el número en el mensaje, no sólo que lanzó
  - Verify: `--filter="DTO interactivo"` en verde
  - Files: `tests/Feature/WhatsappInteractiveSendTest.php`

---

## F2 · InteractiveParser — el corazón

- [x] **2.1** Agregar `interactive` a `Config/whatsapp.php`
  - Acceptance: `marker` (default `.-)`), `list_button` (default `Ver opciones`), `enabled` (default true)
  - Verify: `config('whatsapp.interactive.list_button') === 'Ver opciones'`
  - Files: `Config/whatsapp.php`

- [x] **2.2** Crear `Services/InteractiveParser.php` — detección
  - Acceptance: `parse(string $texto): ?ParsedInteractive`; regex desde config; exige ≥2 ítems y numeración consecutiva desde 1; devuelve `null` cuando no hay marcador
  - Verify: tests de 2.5
  - Files: `Services/InteractiveParser.php`

- [x] **2.3** Parser — elección de formato
  - Acceptance: 2-3 ítems ≤20 → botones; 2-10 ítems ≤24 → lista; >10 ítems o algún ítem >24 → degradar. Devuelve el motivo de la degradación como string
  - Verify: tests de 2.5
  - Files: `Services/InteractiveParser.php`

- [x] **2.4** Parser — armado de body, footer y títulos
  - Acceptance: body = texto antes del primer ítem (trim); footer = texto después del último si ≤60, si no se anexa al body; título = ítem sin el `N.-) `; ids `opt:1`…`opt:N`
  - Verify: tests de 2.5
  - Files: `Services/InteractiveParser.php`

- [x] **2.5** Tests del parser — **los 7 casos de la regla**
  - Acceptance: (a) 3 cortos → botones; (b) 7 → lista; (c) 12 → degrada; (d) uno de 40 chars → degrada; (e) numeración 1,2,4 → `null`; (f) un solo ítem → `null`; (g) **`1.` `2.` a secas → `null`, texto intacto**. Más: body/footer/títulos correctos, y el mensaje real de las ciudades de IMPRIMIR como caso de oro
  - Verify: `--filter=Parser` en verde
  - Files: `tests/Feature/WhatsappInteractiveSendTest.php`

- [x] **2.6** Reescritura del marcador al degradar
  - Acceptance: `1.-) Item` → `1. Item` en el cuerpo que sale como texto; no toca el resto del mensaje
  - Verify: test del caso (c)
  - Files: `Services/InteractiveParser.php`

---

## F3 · Contrato del gateway *(en paralelo con F2)*

- [x] **3.1** `BaseGateway::sendInteractive()` — **este commit va PRIMERO**
  - Acceptance: tira `CapabilityNotSupportedException($this->key(), 'interactive')`
  - Verify: `KommoGateway` sigue resolviendo desde el container
  - Files: `Gateways/BaseGateway.php`

- [x] **3.2** `sendInteractive()` en `Contracts/Gateway.php`
  - Acceptance: firma `(Conversation, OutboundInteractive, ?string $replyToProviderId = null): SendResult`; phpdoc con el `@throws`
  - Verify: `php artisan about` no falla
  - Files: `Gateways/Contracts/Gateway.php`

- [x] **3.3** `CloudApiGateway::sendInteractive()`
  - Acceptance: arma `type: "interactive"` con `interactive.type` `button` o `list`; reusa `post()`; soporta `context` para responder a un mensaje
  - Verify: tests de 3.5
  - Files: `Gateways/CloudApi/CloudApiGateway.php`

- [x] **3.4** Agregar `buttons` y `list` a `capabilities()`
  - Acceptance: `capabilities()->canSend('list') === true`
  - Verify: aserción directa
  - Files: `Gateways/CloudApi/CloudApiGateway.php`

- [x] **3.5** Tests del gateway
  - Acceptance: `Http::fake()`; se afirma la forma exacta del body posteado a Graph para botones y para lista; **ningún WhatsApp real**
  - Verify: `--filter=Gateway` en verde
  - Files: `tests/Feature/WhatsappInteractiveSendTest.php`

---

## F4 · El enganche

- [x] **4.1** Brazo `'interactive'` en `Jobs/SendMessage.php`
  - Acceptance: lee `payload['interactive']`, construye el DTO y llama a `sendInteractive()`
  - Verify: test con gateway falso — se afirma la llamada, no se lee el `match`
  - Files: `Jobs/SendMessage.php`

- [x] **4.2** Enganchar el parser en `AgentController::send()`
  - Acceptance: el texto pasa por el parser antes de persistir; si convierte, `type = 'interactive'` y `payload.interactive`; si degrada, `type = 'text'` con el cuerpo reescrito y `payload.interactive_skipped` con el motivo; el 409 de handoff y el 422 de ventana siguen **antes** que todo esto
  - Verify: tests de 4.3
  - Files: `Http/Controllers/Api/AgentController.php`

- [x] **4.3** Tests de la API
  - Acceptance: 3 ítems → mensaje interactivo; 12 → texto con `interactive_skipped`; handoff abierto → 409; fuera de la ventana → 422; **asesor con `1.-)` → texto plano** (criterio 15)
  - Verify: `--filter=API` en verde; teardown borra por id
  - Files: `tests/Feature/WhatsappInteractiveSendTest.php`

- [x] **4.4** 🔴 **Checkpoint: envío real al +59176616013** — hecho 2026-09-02
  - Acceptance: llega un mensaje de botones y uno de lista, tocables, con los títulos sin el `N.-) `
  - Verify: msg #576 (buttons, 3 opciones) y #577 (list, 7 opciones + footer), ambos
    `status=delivered`. Meta aceptó los dos payloads. Títulos limpios, footer separado del body.
    wamid `...QTlBNzE0QkVERThGRjNCQ0Q2` y `...NDk4ODBFQTk3NkZGREVCQjJG`
  - Files: —

---

## F5 · El viaje de vuelta

- [x] **5.1** Reportar el tap como `type: "text"` en `AgentPayload`
  - Acceptance: en `message()`, un inbound con selección interactiva se reporta `type: "text"` con el label en `text`, manteniendo el bloque `interactive`. `history()` idem
  - Verify: test de 5.2
  - Files: `Services/AgentPayload.php`

- [x] **5.2** Test de round-trip
  - Acceptance: se manda la lista, entra el webhook `list_reply` con `opt:2`, y `AgentPayload::event()` devuelve `type: "text"`, `text: "Cochabamba"`, más `interactive.id = "opt:2"`
  - Verify: `--filter="round-trip"` en verde
  - Files: `tests/Feature/WhatsappInteractiveSendTest.php`

---

## F6 · Burbuja en el inbox

- [x] **6.1** Mockup en texto de la burbuja, y confirmarlo
  - Acceptance: layout en ASCII mostrando body, opciones y footer, para botones y para lista
  - Verify: aprobación explícita antes de tocar el blade
  - Files: —

- [x] **6.2** Rama `interactive` en el blade
  - Acceptance: sale de `v-else` ("Tipo no soportado") a su propia rama; muestra body + opciones + footer; guardas contra `payload` nulo (los cuatro crashes anteriores fueron por eso)
  - Verify: browser real, consola abierta, cero errores
  - Files: `Resources/views/inbox-component.blade.php`, `Http/Controllers/ChatController.php`

---

## F7 · El prompt para el agente — **el sprint no cierra sin esto**

- [x] **7.1** Escribir `docs/agent/mensajes-interactivos.md`
  - Acceptance: formato de `derivacion-a-asesor.md`; el marcador, los límites en tabla, el bloque para pegar en el system prompt, curl de prueba, y la advertencia de la ventana de 24 h. Escrito **contra el parser implementado**, no contra el borrador de la spec
  - Verify: los límites del doc coinciden con las constantes del DTO
  - Files: `docs/agent/mensajes-interactivos.md`

- [ ] **7.2** Entregarlo al equipo del agente
  - Acceptance: el archivo está en manos del equipo y confirman que lo pueden aplicar
  - Verify: confirmación del usuario
  - Files: —

---

## Cierre

- [x] **8.1** Suite completa sin fallos nuevos
  - Acceptance: 10 fallos preexistentes, ninguno más
  - Verify: `php artisan test` en el contenedor, comparado con la línea base
  - Files: —

- [x] **8.2** Pint sobre los archivos tocados, uno por uno
  - Acceptance: `PASS` en cada uno. **Nunca sobre un directorio** — reformatea archivos ajenos y ensucia el diff
  - Verify: `./vendor/bin/pint <archivo>`
  - Files: todos los tocados
