# Tareas: envío de cotizaciones por plantilla HSM

Plan: `tasks/plan.md` · Spec: `docs/specs/whatsapp-quote-template.md`

Ejecutar en orden. Cada tarea deja el repo en verde.

---

## Slice 0 - Alta de la plantilla (humano, arrancar ya, en paralelo)

- [ ] **Dar de alta `cotizacion_documento` en WhatsApp Manager**
  - Aceptación: plantilla en estado *Approved*, categoría **Utility**, idioma
    `es`, header `DOCUMENT`, body con las 4 variables de la spec
  - Verificar: WhatsApp Manager → Plantillas de mensajes; confirmar que la
    categoría asignada por Meta sea Utility y no Marketing
  - Archivos: ninguno (trabajo fuera del repo)
  - Bloquea: solo el Checkpoint E2E, no la implementación

---

## Slice 1 - El gateway sabe mandar una plantilla

- [x] **Crear el DTO `OutboundTemplate`**
  - Aceptación: `final class` inmutable con `name`, `language`, `bodyParams`,
    `headerMediaId`, `headerFilename`; docblock explicando por qué el PDF va en
    el header y no en el body
  - Verificar: `./vendor/bin/pint --test`
  - Archivos: `packages/Webkul/Whatsapp/src/Gateways/Dto/OutboundTemplate.php`

- [x] **Agregar `sendTemplate()` al contrato y el default que lanza**
  - Aceptación: firma en `Gateway`; `BaseGateway` lanza
    `UnsupportedMessageException::type($this->key(), 'template')`; Kommo y
    Messenger heredan sin tocarlos
  - Verificar: `php artisan test` completo verde (nada se rompió)
  - Archivos: `Gateways/Contracts/Gateway.php`, `Gateways/BaseGateway.php`

- [x] **Implementar `CloudApiGateway::sendTemplate()`**
  - Aceptación: sube el PDF con el `uploadMedia()` existente, arma
    `type: template` con `components` (header document + body params) y postea
    por `deliver()`; devuelve el `SendResult` con el wamid
  - Verificar: tests 1, 2, 3 y 7 de la spec, con `Http::fake`
  - Archivos: `Gateways/CloudApi/CloudApiGateway.php`,
    `tests/Feature/WhatsappQuoteTemplateTest.php`

**Checkpoint 1:** `php artisan test` verde. Ningún test toca Meta.

---

## Slice 2 - La tubería elige el camino

- [x] **Agregar el bloque `templates` a la config**
  - Aceptación: `config('whatsapp.templates.quote')` devuelve `name` y
    `language`, leídos de `WHATSAPP_TEMPLATE_QUOTE` y `..._LANG`, con los
    defaults de la spec
  - Verificar: `php artisan tinker --execute='echo json_encode(config("whatsapp.templates"));'`
  - Archivos: `packages/Webkul/Whatsapp/src/Config/whatsapp.php`, `.env.example`

- [x] **Rutear por `payload['template']` en `SendMessage::deliver()`**
  - Aceptación: la rama de plantilla va **antes** de
    `Capabilities::isMedia()`, o nunca se alcanza para `document`
  - Verificar: test 5 (ventana cerrada → `sendTemplate`) y test 4 (ventana
    abierta → `sendMedia`, sin regresión)
  - Archivos: `packages/Webkul/Whatsapp/src/Jobs/SendMessage.php`

- [x] **`DocumentDispatcher::queue()` decide según la ventana**
  - Aceptación: recibe un descriptor de plantilla opcional; si la ventana está
    cerrada lo escribe en `payload['template']`, si está abierta no; la fila
    siempre queda `type = 'document'`
  - Verificar: tests 4, 5 y 8
  - Archivos: `packages/Webkul/Whatsapp/src/Services/DocumentDispatcher.php`

**Checkpoint 2:** tests 4, 5 y 8 verdes. `php artisan test` completo verde.

---

## Slice 3 - La cotización la usa

- [x] **Quitar el rechazo por ventana cerrada y armar los parámetros del body**
  - Aceptación: `sendWhatsapp()` ya no devuelve 422 por ventana; arma las 4
    variables con sus fallbacks; **ninguna** puede resolver a string vacío
  - Verificar: test 6b (ningún parámetro vacío) + test 6 (plantilla mal
    configurada → 422 con frase clara, no 500)
  - Archivos: `packages/Webkul/Admin/src/Http/Controllers/Quote/QuoteController.php`

- [x] **Flash diferenciado y log del contacto sin historial**
  - Aceptación: el vendedor lee cuál de los dos caminos ocurrió; `Log::info`
    cuando `last_inbound_at` es null, para poder auditar si cae la calidad del
    número
  - Verificar: assert del mensaje de respuesta en los tests 4 y 5
  - Archivos: `Resources/lang/es/app.php`, `Resources/lang/en/app.php`,
    `QuoteController.php`

- [x] **Contador de plantillas para el Administrador** *(pedido durante la implementación)*
  - Aceptación: chip en la cabecera de Cotizaciones con las plantillas enviadas
    en el mes en curso, visible **solo** con `role.permission_type = 'all'`;
    para el resto la vista no renderiza nada
  - Verificar: `trans('admin::app.quotes.index.whatsapp.counter')` resuelve; la
    blade compila; el controlador pasa `null` a los no-administradores
  - Archivos: `DocumentDispatcher.php`, `QuoteController.php`,
    `quotes/index.blade.php`, `lang/{es,en}/app.php`

**Checkpoint 3:** 14 tests nuevos verdes. La suite completa queda en el mismo
baseline que HEAD (78 fallos, todos por la DB inalcanzable desde el host de
desarrollo - verificado con `git stash`).

> `lang/es/app.php` no pasa `pint --test`, pero **ya fallaba en HEAD**: las
> violaciones están en bloques que este trabajo no toca (`participants`,
> `products`). Las adiciones de esta rama son puras inserciones, sin
> realineación.

---

## Checkpoint final - E2E (bloqueado por Slice 0)

- [ ] **Envío real a un número de prueba, fuera de la ventana**
  - Aceptación: llega el PDF con el body correcto; aparece en el hilo del inbox
    y en la pestaña Docs del drawer
  - Verificar: WhatsApp Manager confirma que se cobró como **Utility**. Si
    figura como Marketing, reescribir el body y volver a someter la plantilla
  - Archivos: ninguno

- [ ] **Reiniciar el worker de supervisord en el deploy**
  - Aceptación: la cola `whatsapp` corre con el código nuevo
  - Verificar: mandar una cotización de prueba después del restart
