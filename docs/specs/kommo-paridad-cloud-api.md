# Spec: Modo mixto - Kommo recibe, Cloud API envía

Estado: **BORRADOR - pendiente de aprobación**
Rama: `imprimir-modulo-whatsapp`
Decisión de origen (2026-10-01): "todo lo que sea envío, con Cloud API; Kommo solo la recepción".
Reemplaza el borrador anterior, que extendía los salesbots de Kommo para cada tipo de mensaje.

## Contexto

- Producción: `WHATSAPP_GATEWAY=kommo`. La config de Cloud API sigue completa en `.env`
  (token, phone_number_id, WABA, app secret y verify token definidos).
- `KommoGateway` solo envía texto (vía salesbot) y no tiene plantillas, archivos, ubicación ni interactivos.
- `CloudApiGateway` envía todo eso y dirige el mensaje a `PhoneNumber::digits($conversation->wa_phone)`.
- `GatewayManager::for($conversation)` elige el driver por `conversations.gateway`, y **todos** los
  envíos y chequeos de capacidades pasan por ahí (`SendMessage`, `InboxController::gatewayFor`,
  `AgentController`).

## Diseño

**Composición dentro de `KommoGateway`:** recepción propia, envío delegado.

```
webhook Kommo ──► KommoGateway::parseWebhook()  (sin cambios: recibe)
                         │
inbox / agente / ──► KommoGateway::send*() ──► CloudApiGateway::send*()  (delegado)
cotización               capabilities()      = envío de Cloud API + recepción de Kommo
                         messagingWindowHours() = 24 (igual)
```

Por qué así y no cambiando `GatewayManager`: el resto del sistema sigue preguntándole a
**un solo** gateway por conversación. Ningún controlador, job ni vista cambia, y el riesgo
queda encerrado en una clase.

El delegado se activa por config: `whatsapp.gateways.kommo.send_via = env('KOMMO_SEND_VIA', 'cloud_api')`.
Con `send_via=kommo`, vuelve el comportamiento actual por salesbot (marcha atrás sin deploy).

## Supuestos

1. **Mismo número.** El número de Cloud API (`WHATSAPP_PHONE_NUMBER_ID`) es **el mismo**
   número de WhatsApp conectado a Kommo. Si no lo es, el cliente escribe a un número y recibe la
   respuesta desde otro, y la ventana de 24 h no aplica. **Bloqueante, a confirmar.**
2. **Hace falta el teléfono.** Cloud API envía por `wa_phone`. Las conversaciones de Kommo lo
   obtienen con `fetchContactPhone()`. Si falta, el envío falla con un error claro
   ("la conversación no tiene teléfono"), no con un error de Kommo.
3. **Ventana de 24 h.** La abre el mensaje entrante que llega por Kommo, porque es el mismo número (supuesto 1).
4. **Una conversación por cliente.** Si ya existe una conversación `cloud_api` con el mismo teléfono, el
   mensaje que entra por Kommo **se une a ella** y no abre otra. Hoy el resolver usa solo el lead id de
   Kommo y duplicaría el hilo.
5. **Webhook de Meta.** Con `kommo` como gateway activo, lo que Meta envía a `/api/v1/whatsapp/webhook`
   se interpreta como Kommo y **se descarta en silencio**. Se agrega una ruta propia
   `/api/v1/whatsapp/webhook/meta` (fijada a `cloud_api`) que procesa **solo los estados**
   (entregado / leído / fallido) de lo que enviamos, e ignora los mensajes entrantes para no duplicar los de Kommo.
6. **Lo enviado no aparece en el chat de Kommo**, porque sale por otra app. Es una consecuencia aceptada del modo mixto.

## Alcance

| # | Cambio | Archivos |
|---|---|---|
| M1 | Config `send_via` + `KommoGateway` delega `sendText/Media/Location/Interactive/Template` y `capabilities()` en Cloud API | `Config/whatsapp.php`, `Gateways/Kommo/KommoGateway.php` |
| M2 | Error claro cuando la conversación no tiene `wa_phone` | `KommoGateway.php` |
| M3 | El mensaje entrante de Kommo se une a la conversación de WhatsApp existente con el mismo teléfono | `Services/ConversationResolver.php` |
| M4 | Ruta `webhook/meta` fijada a `cloud_api`, solo estados cuando `cloud_api` no es el gateway activo | `Routes/api.php`, `Jobs/IngestInboundPayload.php` |
| M2b | Comando `whatsapp:kommo-backfill-phones` que vuelve a buscar el teléfono de las conversaciones `kommo` sin `wa_phone`, registra a la Person y vincula el lead. Caso real: 2026-10-01 14:43, la búsqueda falló por falta de `KOMMO_TOKEN` en el worker y la conversación quedó sin teléfono. Además, un log de error con el `status` HTTP cuando Kommo no devuelve teléfono | `Console/Commands/`, `KommoGateway.php` |
| M6 | **Recibir archivos por Kommo:** imágenes, audios (notas de voz), videos, documentos y stickers. `attachmentFrom()` portado de odontoking (`0e85239`) mapea `picture→image`, `voice→audio`, `file→document`, `video`, `sticker`. vCard y ubicación sin archivo se guardan como texto legible ("[Contacto compartido]" / "[Ubicación compartida]"). El link de `attachment.link` viaja en `mediaId` y `KommoGateway::downloadMedia()` lo descarga, **el mismo patrón que ya usa `MessengerGateway`**, así que `IngestInboundPayload` y el DTO no cambian. Se descarga en la ingesta porque los links de `amojo.kommo.com` **expiran en pocos días** (comprobado en odontoking). Si la descarga falla, el mensaje se guarda igual | `KommoGateway.php` |
| M5 | Catálogo de plantillas disponible en las conversaciones de Kommo (lo lee de Cloud API) | `InboxController` / `TemplateCatalog` (solo si hace falta) |

**Fuera de alcance:** sincronizar lo enviado al chat de
Kommo, migrar o fusionar conversaciones duplicadas que ya existen.

## Commands

```bash
php artisan test tests/Feature/Whatsapp/KommoModoMixtoTest.php   # nuevo
php artisan test --filter=Whatsapp                                 # regresión
./vendor/bin/pint <archivo>
# Deploy: php artisan config:clear && php artisan queue:restart
# Meta: apuntar el webhook de la app a https://imprimir.sofopolis.com/api/v1/whatsapp/webhook/meta
```

## Testing Strategy (Pest + `Http::fake`)

- Una conversación `kommo` con `wa_phone`: texto, plantilla, imagen, ubicación e interactivo salen a
  `graph.facebook.com/.../messages` y **nunca** a `kommo.com`.
- `send_via=kommo`: el texto vuelve a salir por el salesbot (marcha atrás).
- Sin `wa_phone`: excepción con mensaje claro y el mensaje queda `failed`.
- `capabilities()` de Kommo = envío de Cloud API + recepción `text, image, audio, video, document, sticker`.
- Entrante de Kommo con `attachment` de cada tipo (payloads reales de odontoking como fixtures): queda
  el tipo correcto, el archivo se guarda en storage propio y `media_mime` es correcto; vCard y ubicación quedan como texto;
  si la descarga devuelve 404 el mensaje se guarda igual.
- Entrante de Kommo con teléfono de una conversación `cloud_api` existente: se agrega a esa conversación.
- `webhook/meta`: un estado actualiza el mensaje; un mensaje entrante de Meta se ignora.
- Regresión: con `WHATSAPP_GATEWAY=cloud_api` nada cambia.

## Boundaries

- **Always:** `Http::fake` en tests; `queue:restart` después del deploy; commit por tarea.
- **Ask first:** cambiar el webhook en Meta, cambiar `.env` de producción, enviar mensajes reales de prueba.
- **Never:** tocar los salesbots o datos de Kommo; enviar desde un número distinto del que el cliente escribió.

## Success Criteria

- [ ] Con `WHATSAPP_GATEWAY=kommo`, el asesor envía texto, plantilla, PDF, imagen y ubicación sin error.
- [ ] Los botones del agente IA llegan como botones reales de WhatsApp.
- [ ] La cotización automática llega como documento.
- [ ] Una foto, una nota de voz o un PDF que el cliente manda por Kommo se ve en el inbox (se puede reproducir o abrir) y sigue visible días después.
- [ ] Un cliente que ya tenía conversación no aparece duplicado cuando escribe por Kommo.
- [ ] Los mensajes enviados pasan a "entregado" y "leído".
- [ ] Tests nuevos y existentes en verde.

## Open Questions

1. **(Bloqueante) ¿El número de Cloud API es el mismo número de WhatsApp conectado a Kommo?**
2. Si Meta también envía los mensajes entrantes a nuestra app, ¿para qué se necesita Kommo en la recepción?
   (¿lo usa el equipo como CRM o para sus propios bots?) Saberlo confirma que el supuesto 5 es correcto.
