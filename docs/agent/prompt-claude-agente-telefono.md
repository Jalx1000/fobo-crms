# Prompt para el Claude del proyecto del agente

> Copiá todo lo que está debajo de la línea y pegalo como primer mensaje en el
> repo del agente IA. Es autocontenido: no asume que ese Claude conozca el CRM.
>
> **Estado del lado del CRM:** el contrato está aprobado, pero los campos y el
> endpoint que se describen abajo **todavía no están desplegados** (tareas T1–T4
> de `tasks/todo-messenger-telefono.md`). Avisale al equipo si van a
> implementar antes: el código debe tolerar que los campos nuevos aún no
> lleguen (ver "Compatibilidad" en el prompt).

---

Trabajás en el proyecto del agente IA que atiende las conversaciones de un CRM
Krayin (Laravel). El CRM te manda un webhook por cada mensaje entrante del
cliente y vos respondés llamando a su API REST.

## Qué necesito que implementes

El CRM ahora atiende también **Facebook Messenger**, además de WhatsApp. La
diferencia importante: **un contacto de Messenger no tiene número de teléfono**.
La identidad que Meta nos da es un PSID (id interno de la página), inútil fuera
de Messenger. Eso deja leads en el pipeline que ningún asesor puede llamar ni
cotizar por WhatsApp.

Tu trabajo: **cuando el CRM avise que falta el teléfono, pedírselo al cliente en
la conversación y devolvérselo al CRM por su API.**

## El contrato

### 1. Lo que recibís

El evento `message.received` que ya conocés ahora trae campos nuevos dentro de
`contact`:

```json
{
  "event": "message.received",
  "conversation_id": 1487,
  "gateway": "messenger",
  "ai_enabled": true,
  "contact": {
    "phone": null,
    "channel": "messenger",
    "name": "Ana Pérez",
    "person_id": 412,
    "lead_id": 890,

    "phone_required": true,
    "phone_prompt_state": "pending",
    "phone_prompt_attempts": 0,
    "phone_prompt_exhausted": false
  },
  "message": { "id": 90211, "type": "text", "text": "hola, cuánto sale un banner?", "timestamp": "2026-08-21T18:04:11-04:00" },
  "history": [ { "role": "user", "content": "...", "type": "text" } ],
  "handoff": { "state": null, "open": false, "pooled": false, "reason": null },
  "window":  { "open": true, "expires_at": "2026-08-22T18:04:11-04:00" },
  "reply":   { "method": "POST", "url": "https://.../api/v1/whatsapp/conversations/1487/messages" }
}
```

Los mismos campos aparecen en `GET /api/v1/whatsapp/conversations/{id}/messages`.

| Campo | Significado |
|---|---|
| `phone_required` | `true` = a este contacto le falta el teléfono. **Es una señal de que el dato falta, no una orden de pedirlo ahora.** |
| `phone_prompt_state` | `pending` (nunca se pidió) · `asked` (ya se pidió, sin respuesta) · `captured` (ya lo tenemos) · `refused` (se agotaron los intentos) |
| `phone_prompt_attempts` | Cuántas veces se pidió. Lo cuenta el CRM, no vos. |
| `phone_prompt_exhausted` | `true` = ya se pidió 3 veces. **Dejá de pedirlo.** |

### 2. Cuándo pedirlo - importante

**No lo pidas en el primer mensaje.** Pedilo recién cuando la conversación
califica: el cliente pregunta precio, pide una cotización, consulta plazos de
entrega o cualquier cosa que requiera seguimiento fuera de Messenger. Pedir el
teléfono de entrada espanta consultas casuales y baja la tasa de respuesta.

La secuencia natural es: respondés la consulta primero, y en el mismo mensaje o
el siguiente pedís el número para poder mandarle la cotización.

Reglas duras:

- Si `phone_required` es `false` → nunca lo pidas (ese canal ya tiene teléfono).
- Si `phone_prompt_state` es `captured` → nunca lo pidas.
- Si `phone_prompt_exhausted` es `true` → nunca lo pidas. Seguí atendiendo
  normalmente por Messenger.
- Máximo **3 pedidos** por conversación. El CRM lleva la cuenta: guiate por
  `phone_prompt_attempts`, no por un contador tuyo (te reiniciás entre eventos).
- Nunca condiciones la respuesta a que te den el número. Si el cliente no quiere
  darlo, atendelo igual.

Tono: español boliviano, informal pero respetuoso (voseo). Algo como
*"Para pasarte la cotización por WhatsApp, ¿me compartís tu número?"* - pedilo
con un motivo concreto, no en abstracto.

### 3. Cómo lo devolvés

Cuando el cliente da el número, o cuando se niega, llamás:

```
POST /api/v1/whatsapp/conversations/{conversation_id}/contact-phone
Authorization: Bearer <el mismo token que ya usás>
Content-Type: application/json
```

Capturado:

```json
{ "phone": "+591 70012345", "source": "ai", "confidence": "stated" }
```

Se negó (o cambió de tema y no lo va a dar):

```json
{ "refused": true }
```

Respuestas:

| Código | Qué pasó | Qué hacés |
|---|---|---|
| `200` | Registrado. El cuerpo trae el bloque `contact` actualizado. | Seguí la conversación normalmente. No lo vuelvas a pedir. |
| `422` | El número no es válido (menos de 7 dígitos, basura). **No se guardó nada.** | Repreguntá una vez, si te quedan intentos. |
| `404` | La conversación no existe. | No reintentes. |
| `401` | Token inválido. | No reintentes; es un problema de configuración. |

Notas:

- **Mandá el número tal cual lo dictó el cliente.** El CRM normaliza y valida:
  no intentes limpiarlo, formatearlo ni completar el código de país vos.
- Si el cliente dicta el número en palabras ("setecientos doce, cuarenta y
  cinco...") **no adivines**: pedí que lo escriba en dígitos. Un número mal
  extraído se pega a la ficha de un desconocido.
- El endpoint es **idempotente**: llamarlo dos veces con el mismo número no
  duplica nada ni suma intentos. Si tu workflow reintenta, no hay problema.

### 4. Compatibilidad

El CRM despliega esto por etapas. Tu código tiene que tolerar que los campos
nuevos **no vengan**: tratá `phone_required` ausente como `false` y no pidas
nada. Nunca asumas que `contact.phone` existe: en Messenger es `null` siempre
hasta que vos lo captures.

## Lo que quiero de vos

1. Leé cómo está armado hoy el manejo del evento `message.received` en este repo.
2. Proponeme dónde encaja esta lógica **antes de escribir código**: dónde vive
   la decisión de "esta conversación calificó", dónde se guarda el estado si
   hace falta, y cómo se llama al endpoint nuevo.
3. Recién con eso aprobado, implementalo, con tests del caso feliz, del 422 y
   del `exhausted`.

No toques el prompt del modelo sin mostrarme el diff: el tono y el momento del
pedido son decisiones de negocio, no detalles de implementación.
