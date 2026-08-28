# Sprint 1 — Infra Reverb + recepción multimedia + inbox visor

**Duración:** 1 semana (~5 días-dev) · **Objetivo:** ver la conversación en tiempo real dentro del
CRM (leads y persons), con render de texto e imagen/audio/documento. Todavía solo lectura.

## Historias

### HU-05 · Infra Laravel Reverb + Echo — 1d `[DEVOPS · REDIS]`
- Instalar/configurar Reverb; proceso corriendo en easyPanel (puerto expuesto + env
  `REVERB_APP_*`, `BROADCAST_CONNECTION=reverb`).
- Configurar Echo en el front (`resources/js`) apuntando a Reverb.
**AC:** un evento de prueba emitido en el server llega a una pestaña abierta (visto en consola).

### HU-06 · Normalización de entrantes + descarga de media — 1.5d `[BACK]`
Extender `IngestJob` para todos los tipos entrantes (text, image, audio, document, y guardar crudo
video/sticker/location/contact). Para media: `GET /{media-id}` → url → descargar → `storage`.
**AC:**
- Cada tipo entrante persiste con `type`, `body`/`media_path`/`media_mime` correctos.
- El archivo de media queda accesible por una ruta firmada/privada del CRM.

### HU-07 · Evento broadcast + endpoint de histórico — 1d `[BACK]`
- Evento `MessageReceived` broadcast en canal privado `whatsapp.conversation.{id}` (autorizado por
  ACL del usuario CRM).
- `GET /api/v1/whatsapp/conversations/{id}/messages?before=&limit=` (paginado hacia atrás).
**AC:** el endpoint pagina el histórico; el broadcast emite al crear un mensaje entrante.

### HU-08 · Inbox visor (Blade + Vue) en leads y persons — 1.5d `[FRONT]`
Reescribir el componente `historial-ia.blade.php` (compartido por `leads/view` y
`persons/view`) a un inbox:
- Burbujas por `direction` (entrante izquierda / saliente derecha).
- Render de imagen (thumb), audio (player), documento (link con nombre).
- Suscripción a Echo → mensaje nuevo aparece **sin recargar**; autoscroll.
- Carga inicial vía el endpoint de histórico (reemplaza `api/public/chat-history`).
**AC:**
- Mensaje entrante aparece en pantalla en ≤1s sin recargar.
- El mismo componente funciona idéntico en la pestaña de lead y de person.

## Dependencias
- Sprint 0 completo (webhook + persistencia + resolución de teléfono).
- Reverb (HU-05) es prerequisito de HU-08.

## Definición de Hecho del sprint
Con el chat abierto en el navegador, enviar un WhatsApp real (texto + una imagen) al número
aparece en el inbox en tiempo real, tanto desde la vista de lead como de person.
