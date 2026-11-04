# Spec: el agente IA debe solicitar el número de teléfono en Messenger

> Estado: **APROBADA** (2026-08-21). Sin preguntas abiertas - ver "Decisiones
> tomadas". Lista para planificar.
>
> Rama: `imprimir-modulo-whatsapp` · Autor: Javier Mogro / Claude

---

## Contexto: qué encontré al revisar la integración

Messenger ya está integrado como un canal más del inbox compartido
(`MessengerGateway` → `IngestInboundPayload` → `ConversationResolver`). La
arquitectura es correcta y no hay que tocarla. El problema es de **datos**, no
de plomería:

| # | Hallazgo | Evidencia |
|---|---|---|
| 1 | Un contacto de Messenger **nunca trae teléfono**. La identidad es el PSID (page-scoped id), útil solo para la página que lo emitió. | `MessengerGateway::identityOf()` (`Gateways/MessengerGateway.php:501`) construye `ContactIdentity(providerId: $psid)` - `phone` queda `null`. |
| 2 | Aun sin teléfono se **crea Person y Lead**. `resolveOrCreatePerson()` solo espera al teléfono para la familia `whatsapp`; Messenger es familia propia, así que registra el Person de una. | `ConversationResolver::resolveOrCreatePerson()` + `createPerson()` (`Services/ConversationResolver.php`). |
| 3 | Ese Person se crea **sin `contact_numbers`** y con nombre `"Messenger 123456"` cuando el perfil de Graph no responde. | `createPerson()`: el spread de `contact_numbers` se omite si no hay dígitos. |
| 4 | Un contacto de Messenger **nunca matchea** con un contacto ya existente en el CRM: sin teléfono no hay clave cross-canal. Es una decisión deliberada y correcta (duplicar es reversible, fusionar mal no), pero deja duplicados acumulándose. | Comentario en `resolveOrCreatePerson()`. |
| 5 | El agente IA **recibe `phone: null`** y sabe en qué canal está, pero nada en el contrato le dice que tiene que pedir el número. | `AgentPayload::contact()` (`Services/AgentPayload.php`). |
| 6 | **No existe forma de que el agente devuelva el teléfono.** La API del agente expone solo `GET/POST messages` y `POST handoff`. Si el cliente dicta su número, queda enterrado en el cuerpo de un mensaje. | `Routes/api.php` + `Api/AgentController.php`. |
| 7 | El agente **hoy ni siquiera atiende Messenger**: `ai_default` del canal es `env('MESSENGER_AI_ENABLED', false)`. | `MessengerServiceProvider.php:79`. |

**Conclusión del review:** el trabajo no es "decirle al agente que pida el
teléfono" en un prompt. Un prompt sin endpoint de retorno produce una
conversación donde el cliente da el número y el CRM lo ignora. Hay que cerrar el
circuito completo: *señal → pedido → captura → persistencia → deduplicación*.

---

## Supuestos que estoy haciendo

Corregime ahora o procedo con estos:

1. El agente IA es un **proyecto externo** (n8n / servicio propio) que consume
   `whatsapp.ai.webhook_url` y responde por la API con token Sanctum. No
   controlamos su prompt desde este repo: el CRM le manda **datos**, y el prompt
   del lado del agente reacciona a esos datos.
2. Queremos el teléfono porque es **la única clave que identifica a un humano
   entre canales** (ya lo dice el comentario del resolver) y porque sin él no se
   puede cotizar por WhatsApp ni llamar al lead.
3. El número se pide **conversacionalmente**, no con un formulario. Messenger
   no tiene un campo estructurado de teléfono que podamos leer.
4. Bolivia es el país por defecto para normalizar números sin prefijo (`+591`),
   consistente con cómo `PhoneNumber` ya trata los números guardados.
5. El agente puede equivocarse extrayendo el número: la captura tiene que
   **validarse en el CRM**, no confiar en el JSON del agente.
6. Alcance: **solo Messenger**. Kommo y Cloud API ya traen teléfono.
7. `MESSENGER_AI_ENABLED=true` se activa como parte de este trabajo; sin eso
   nada de esto corre en producción.

---

## Objetivo

Que toda conversación de Messenger termine con un teléfono verificado en el
Person correspondiente, capturado por el agente IA durante la conversación, y
que ese teléfono sirva para **fusionar** el contacto con el que ya existía en el
CRM cuando corresponda.

Usuario: el asesor comercial, que hoy recibe leads de Messenger imposibles de
contactar fuera de Messenger.

---

## Alcance

### Dentro

1. **Señal en el contrato del agente.** `AgentPayload::event()` y
   `AgentController::messages()` incorporan un bloque nuevo:

   ```json
   "contact": {
     "phone": null,
     "channel": "messenger",
     "name": "Ana Pérez",
     "person_id": 412,
     "lead_id": 890,
     "phone_required": true,
     "phone_prompt_state": "pending"
   }
   ```

   `phone_required` es **derivado del canal**, no una columna: verdadero cuando
   el canal no es de familia telefónica y la conversación no tiene `wa_phone`.
   `phone_prompt_state` ∈ `pending | asked | captured | refused`, persistido en
   la conversación junto con `phone_prompt_attempts` (máx. 3), para que el
   agente no vuelva a pedirlo en cada mensaje ni insista para siempre.
   `phone_required: true` significa "falta el dato", no "pedilo ahora": el
   momento lo decide el agente, al calificar la conversación (ver Decisiones).

2. **Endpoint de captura**:
   `POST /api/v1/whatsapp/conversations/{id}/contact-phone`
   Body: `{"phone": "+591 700 12345", "source": "ai", "confidence": "stated"}`
   El CRM valida, normaliza, escribe en `conversations.wa_phone`, agrega el
   número a `Person.contact_numbers` y, si el número ya pertenece a otro Person,
   marca `duplicate_of_person_id` en la conversación sin fusionar (ver Decisiones). Devuelve el
   Person resultante y la marca de duplicado, si la hubo.

3. **Endpoint de "no lo dio"**:
   el mismo endpoint con `{"refused": true}` marca `phone_prompt_state =
   refused` para que el agente deje de insistir y el asesor lo vea en el inbox.

4. **Marca de duplicado en la conversación, no en `persons`.** El puntero
   `duplicate_of_person_id` vive en `whatsapp_conversations`, no en la tabla
   `persons` del módulo Contact. Es donde lo lee el chip del inbox, y mantiene
   todo el cambio de esquema dentro del módulo Whatsapp en vez de tocar el core.

5. **Visibilidad en el inbox**: chip "Sin teléfono" en las conversaciones de
   Messenger que sigan en `pending`/`refused`.

6. **Documento de contrato para el equipo del agente** (`docs/agent/`) con el
   payload, el endpoint, los códigos de error y el texto sugerido del pedido.

### Fuera

- Cambiar el prompt del agente (es del otro proyecto; les entregamos el contrato).
- Backfill de conversaciones de Messenger viejas (queda como tarea aparte).
- Verificación del número por OTP.
- Instagram / otros canales de Meta.

---

## Comandos

```bash
composer install
php artisan migrate                              # columna phone_prompt_state
php artisan test tests/Feature/Messenger         # suite del canal
./vendor/bin/pest --filter="ContactPhone"
./vendor/bin/pint
npm run build                                    # chip del inbox
```

---

## Estructura

```
packages/Webkul/Whatsapp/src/
  Http/Controllers/Api/AgentController.php     → acción contactPhone()
  Http/Requests/ContactPhoneRequest.php        → validación del número (nuevo)
  Services/AgentPayload.php                    → phone_required / phone_prompt_state
  Services/ContactPhoneCapture.php             → normaliza, persiste, deduplica (nuevo)
  Services/PhoneNumber.php                     → reutilizado, sin cambios
  Database/Migrations/*_add_phone_prompt_state_to_conversations.php
  Database/Migrations/*_add_duplicate_of_person_id_to_conversations.php
  Routes/api.php                               → ruta nueva
docs/agent/messenger-phone-capture.md          → contrato para el proyecto del agente
tests/Feature/MessengerPhoneCaptureTest.php
```

---

## Estilo de código

El del módulo: servicio inyectado, nunca Eloquent suelto en el controlador, y
comentarios que explican **por qué**, no qué.

```php
/**
 * Registra el teléfono que el agente capturó en la conversación.
 *
 * Se valida acá y no del lado del agente a propósito: el número lo extrae un
 * LLM del texto libre del cliente, así que llega tan sucio como lo dictó ("es
 * el setecientos doce..."). Un número inválido escrito en Person.contact_numbers
 * es peor que ninguno: matchea mal en resolvePerson() y ata a un desconocido.
 */
public function capture(Conversation $conversation, string $phone): CaptureResult
```

---

## Estrategia de pruebas

Pest, en `tests/Feature/`. Casos mínimos:

- Un evento de Messenger sin teléfono lleva `phone_required: true`; uno de Cloud API, `false`.
- `POST contact-phone` con número válido → `wa_phone` seteado, número en `contact_numbers`, estado `captured`.
- Número inválido o de menos de 7 dígitos → 422 y **nada** escrito.
- Número que ya pertenece a otro Person → se marca `duplicate_of_person_id`, **ningún** Person se borra ni se mueve el lead.
- Llamada repetida con el mismo número → idempotente, sin duplicar en `contact_numbers`.
- `{"refused": true}` → suma un intento; al tercero, estado `refused` y `phone_prompt_exhausted: true`.
- Una conversación con `refused` **sí** tiene lead: la creación automática no se bloquea.

---

## Límites

- **Siempre:** validar y normalizar el número en el CRM; correr `pint` y la suite antes de commitear; mantener el canal de Messenger dentro del pipeline compartido.
- **Preguntar antes:** cualquier fusión de Persons (la automática está descartada por decisión); cambios de esquema más allá de la columna de estado; activar `MESSENGER_AI_ENABLED` en producción.
- **Nunca:** escribir un PSID en `contact_numbers` (rompe `resolvePerson()`); confiar en el número sin validar; borrar un Person existente al deduplicar.

---

## Criterios de éxito

1. Un evento `message.received` de Messenger sin teléfono trae `phone_required: true`.
2. El agente puede registrar el teléfono con una sola llamada HTTP y recibe el `person_id` final.
3. Tras la captura, el contacto aparece en el CRM con el número en su ficha y el chip "Sin teléfono" desaparece.
4. Un número que ya existía en el CRM queda marcado como posible duplicado y visible para el asesor, sin fusión automática.
5. Un número inválido nunca llega a la base.
6. Existe `docs/agent/messenger-phone-capture.md` y el equipo del agente lo aprobó.

---

## Decisiones tomadas (2026-08-21)

1. **Deduplicación: marcar, no fusionar.** Cuando el teléfono capturado ya
   pertenece a otro Person, el CRM escribe igual el número en la conversación y
   en el Person que creó Messenger, y marca la pareja como posible duplicado
   (`duplicate_of_person_id`) con un chip en el inbox. Un asesor confirma la
   fusión. Es la única dirección reversible: el número lo dictó un cliente y lo
   extrajo un LLM, y una fusión equivocada no se deshace.
2. **Momento del pedido: al calificar.** El agente pide el teléfono recién
   cuando la conversación califica - el cliente pregunta precio, pide cotización
   o algo que requiera seguimiento - no en el primer mensaje. Por eso
   `phone_required` es una señal, no una orden: el CRM dice *que falta*, el
   agente decide *cuándo* pedirlo.

3. **El agente insiste hasta 3 veces.** El CRM lleva la cuenta
   (`phone_prompt_attempts`) y la publica en el payload; al tercer pedido sin
   número, `phone_prompt_state` pasa a `refused` y `phone_required` sigue en
   `true` pero con `phone_prompt_exhausted: true`, que es la señal de dejar de
   insistir. La cuenta la lleva el CRM y no el agente porque el agente es
   stateless entre eventos: si contara él, un reinicio reiniciaría el contador y
   el cliente recibiría el pedido indefinidamente.
4. **El lead automático se crea igual.** Un contacto de Messenger que se negó a
   dar el teléfono sigue siendo demanda real y tiene que estar en el pipeline;
   el asesor lo atiende por Messenger. `refused` no bloquea nada: solo pinta el
   chip "Sin teléfono" en el inbox.

## Preguntas abiertas

Ninguna. Spec cerrada - lista para planificar.
