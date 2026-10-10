# Tareas: API mobile de WhatsApp

Spec: [spec-api-mobile-whatsapp.md](spec-api-mobile-whatsapp.md) · Plan: [plan-api-mobile-whatsapp.md](plan-api-mobile-whatsapp.md)

Tests: `docker exec -w /code heaven_kolberg_laravel.1.<id> php artisan test tests/Feature/WhatsappMobileApiTest.php`

## 1. Base de autenticación

- [ ] **1.1 Middleware y grupo de rutas**
  - Acceptance: `AuthenticateMobileUser` carga el usuario del token en el guard `user`; el grupo `api/v1/mobile/whatsapp` lleva `api, throttle:mobile-api, auth:sanctum, whatsapp.mobile-user, whatsapp.access`.
  - Verify: test → sin token 401; token de un rol sin `whatsapp` 403 JSON; la request siguiente sin token sigue dando 401 (no quedó sesión).
  - Files: `Http/Middleware/AuthenticateMobileUser.php`, `Providers/WhatsappServiceProvider.php`, `Routes/api.php`, `tests/Feature/WhatsappMobileApiTest.php`
- [ ] **1.2 Limiter `mobile-api`**
  - Acceptance: bucket por usuario, `MOBILE_API_RATE_LIMIT` (default 120); `agent-api` sin cambios.
  - Verify: test → dos usuarios distintos no comparten contador; `WhatsappContratoAgenteTest` en verde.
  - Files: `app/Providers/RouteServiceProvider.php`, `config/agent_api.php`
- [ ] **1.3 `GET /me`** + `@OA\Tag("WhatsApp Mobile")` + documentación de `/api/v1/login` y `/logout`
  - Acceptance: devuelve `{id, name, email, role, view_permission, can_whatsapp}`.
  - Verify: test 200 con la forma esperada.
  - Files: `Http/Controllers/Api/Mobile/MeController.php`, `Routes/api.php`

**Checkpoint:** curl a `/me` con y sin token.

## 2. Visibilidad (web y mobile)

- [ ] **2.1 `scopeVisibleTo($ids)` en `Conversation`**
  - Acceptance: con `null` no filtra; con una lista aplica las reglas (a) asignada, (b) lead propio por `lead_id` o `person_id`, (c) bolsa.
  - Verify: tests de unidad del scope con 4 conversaciones (propia, ajena, asignada, bolsa) y un usuario `individual`.
  - Files: `Models/Conversation.php`, `tests/Feature/WhatsappVisibilidadTest.php`
- [ ] **2.2 Aplicar el scope al listado y a la resolución por id**
  - Acceptance: `ChatController@conversations` filtra; todo método que resuelve por id o por `conversation_id`, `person_id` o `lead_id` devuelve 404 si la conversación no es visible.
  - Verify: test de 404 en hilo, envío, claim y nota con una conversación ajena; suite de WhatsApp completa en verde.
  - Files: `ChatController.php`, `InboxController.php`, `Support/VisibleConversation.php` (nuevo)

**Checkpoint:** suite completa de WhatsApp en verde → commit aislado.

## 3. Lectura

- [ ] **3.1 `GET /conversations`** (filtros: `filter`, `city`, `stage`, `search`, `page`)
- [ ] **3.2 `GET /conversations/{id}/messages?since=`**: carga inicial y modo incremental; marca como leída.
- [ ] **3.3 `GET /unread-count`**: `{conversations, messages, pool, mine}` con el scope aplicado.
  - Verify (las tres): 200 feliz, 404 si es ajena, `since` sólo devuelve lo nuevo, el contador excluye las no visibles.
  - Files: `Api/Mobile/ConversationController.php`, `Routes/api.php`, test

**Checkpoint:** listar y leer desde Swagger UI con Authorize.

## 4. Envío (gateway fake en todos los tests)

- [ ] **4.1 `POST /conversations/{id}/messages`**: texto, con `reply_to` opcional.
- [ ] **4.2 `POST /conversations/{id}/media`**: multipart; documentar los MIME y el tamaño máximo que acepta hoy `sendMedia`.
- [ ] **4.3 `POST /conversations/{id}/location`**
- [ ] **4.4 `GET /templates`** + **`POST /conversations/{id}/template`**
  - Verify: el mensaje queda con `user_id` = usuario del token; 422 con input inválido; 404 si es ajena.
  - Files: `Api/Mobile/MessageController.php`, `Routes/api.php`, test

## 5. Control de la conversación

- [ ] **5.1 `PATCH /conversations/{id}/agent`**
- [ ] **5.2 `POST .../handoff/claim`** y **`.../handoff/resolve`**: 409 si otro ya la tomó (`HandoffConflictException`).
- [ ] **5.3 `POST .../notes`**
  - Verify: el actor de la auditoría es el usuario del token, no el bot; 404 si es ajena.
  - Files: `Api/Mobile/HandoffController.php`, `Routes/api.php`, test

## 6. Contexto CRM

- [ ] **6.1 `POST /conversations/{id}/link-person`**, **`GET /persons/{id}`**, **`POST /persons/{id}/leads`**
  - Nota: `persons/{id}` respeta la misma limitación (persona sin lead visible ni conversación visible → 404).
- [ ] **6.2 `GET /stages`, `/cities`, `/products`, `/quick-replies`**
  - Verify: 200 y forma; 404 en una persona fuera de alcance.
  - Files: `Api/Mobile/CrmController.php`, `Routes/api.php`, test

## 7. Swagger

- [ ] **7.1 Schemas compartidos** (`MobileConversation`, `MobileMessage`, `MobileTemplate`, `ValidationError`, `Forbidden`, `NotFound`), con ejemplos tomados de las respuestas de los tests.
- [ ] **7.2 Generar y revisar**
  - Verify: `php artisan l5-swagger:generate` sin warnings; `/api/documentation` muestra el tag con los 18 endpoints y Authorize funciona; `php artisan test` completo en verde.
  - Files: `Api/Mobile/Schemas.php`
