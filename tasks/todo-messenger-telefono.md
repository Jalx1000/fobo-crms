# Tareas - el agente IA solicita el teléfono en Messenger

> Plan: `tasks/plan-messenger-telefono.md` · Spec: `docs/specs/messenger-agente-solicita-telefono.md`
> Orden por dependencia. Ninguna tarea toca más de 5 archivos.

---

## Rebanada A - el agente sabe que falta el teléfono

- [ ] **T1. Migración: estado del pedido de teléfono**
  - Acepta: `whatsapp_conversations` gana `phone_prompt_state` (enum-string, default `pending`), `phone_prompt_attempts` (unsigned tinyint, default 0) y `duplicate_of_person_id` (unsigned bigint nullable). Las tres en `$fillable` de `Conversation`.
  - Verifica: `php artisan migrate` y `php artisan migrate:rollback` sin errores; `Conversation::create()` acepta los campos nuevos.
  - Archivos: `Whatsapp/src/Database/Migrations/2026_08_21_000010_add_phone_prompt_to_whatsapp_conversations.php`, `Whatsapp/src/Models/Conversation.php`

- [ ] **T2. `phone_required` en el contrato del agente**
  - Acepta: `AgentPayload::contact()` agrega `phone_required` (derivado: familia no telefónica **y** sin `wa_phone`), `phone_prompt_state`, `phone_prompt_attempts` y `phone_prompt_exhausted` (attempts >= 3). Aparece tanto en el evento como en `GET .../messages`.
  - Verifica: `./vendor/bin/pest --filter="ContactPhone"` - evento de Messenger da `true`, evento de Cloud API da `false`.
  - Archivos: `Whatsapp/src/Services/AgentPayload.php`, `tests/Feature/MessengerPhoneCaptureTest.php`

---

## Rebanada B - el agente puede devolverlo

- [ ] **T3. `ContactPhoneCapture`: validar, persistir, marcar duplicado**
  - Acepta: recibe conversación + número; normaliza con `PhoneNumber::normalize()`; escribe `wa_phone`; agrega el número a `Person.contact_numbers` sin duplicarlo; si `resolvePerson()` encuentra otro Person con ese número, escribe `duplicate_of_person_id` y **no** fusiona ni mueve el lead; deja `phone_prompt_state = captured`. Idempotente. Nunca lanza: un fallo se loguea y devuelve el resultado sin capturar.
  - Verifica: tests de número válido, número repetido, número que ya pertenece a otro Person.
  - Archivos: `Whatsapp/src/Services/ContactPhoneCapture.php`, `tests/Feature/MessengerPhoneCaptureTest.php`

- [ ] **T4. Endpoint `POST conversations/{id}/contact-phone`**
  - Acepta: ruta bajo `auth:sanctum` junto a las del agente; `ContactPhoneRequest` valida `phone` (mín. 7 dígitos tras `digits()`) o `refused: true`; con `refused` suma un intento y al tercero deja `refused` + `phone_prompt_exhausted`. Responde 200 con el bloque `contact` actualizado; 422 si el número es inválido, sin escribir nada. Anotado con `@OA` como el resto de la API del agente.
  - Verifica: `./vendor/bin/pest --filter="ContactPhone"`; `curl` manual con token contra una conversación de Messenger real.
  - Archivos: `Whatsapp/src/Routes/api.php`, `Whatsapp/src/Http/Controllers/Api/AgentController.php`, `Whatsapp/src/Http/Requests/ContactPhoneRequest.php`, `tests/Feature/MessengerPhoneCaptureTest.php`

---

## Rebanada C - el humano lo ve

- [ ] **T5. Chip "Sin teléfono" y aviso de duplicado en el inbox**
  - Acepta: la lista de conversaciones marca las que tienen `phone_required` sin capturar; la ficha del contacto avisa "posible duplicado de <contacto>" con enlace, cuando `duplicate_of_person_id` está seteado. Funciona en claro y oscuro.
  - Verifica: `npm run build` + revisión visual del inbox en ambos temas.
  - Archivos: `Whatsapp/src/Http/Controllers/InboxController.php`, `Whatsapp/src/Resources/views/inbox-component.blade.php`, `Admin/src/Resources/lang/es/app.php`, `Admin/src/Resources/lang/en/app.php`

---

## Rebanada D - el otro equipo lo implementa y se enciende

- [ ] **T6. Contrato para el proyecto del agente**
  - Acepta: `docs/agent/messenger-phone-capture.md` documenta el payload (con ejemplo real), el endpoint, los códigos de error, el tope de 3 intentos, **cuándo** pedirlo (al calificar, no en el primer mensaje) y un texto sugerido en español boliviano. Incluye el `curl` de ejemplo.
  - Verifica: el equipo del agente lo lee y confirma que puede implementarlo sin preguntas.
  - Archivos: `docs/agent/messenger-phone-capture.md`

- [ ] **T7. Encender el agente en Messenger** - *requiere aprobación explícita*
  - Acepta: `MESSENGER_AI_ENABLED=true` en producción, workers reiniciados, y una conversación de prueba end-to-end donde el agente pide el número, el cliente lo da y aparece en la ficha del contacto.
  - Verifica: prueba manual end-to-end + `php artisan whatsapp:webhook-info messenger`.
  - Archivos: `.env` (producción), reinicio del `queue:work` en supervisord.
