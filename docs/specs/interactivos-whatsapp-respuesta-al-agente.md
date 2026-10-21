# Interactivos de WhatsApp — respuesta del CRM

> Para el equipo del agente.
> Respuesta a `CRM (Krayin) — Soporte de mensajes interactivos de WhatsApp (botones y listas)`.
> **Implementado en el CRM. Las dos direcciones del contrato están listas.**
> Falta una sola cosa: la prueba extremo a extremo con un teléfono real.

Implementado sobre `poncedeleon.sofopolis.com`. Ver §7 si el destinatario era otro despliegue.

---

## 1. Estado del checklist

| # | Punto | Estado |
|---|---|---|
| 1 | `POST .../messages` acepta `interactive`; `text` condicional | **Hecho** |
| 2 | Reenvío del objeto a Cloud API envuelto en `type: "interactive"` | **Hecho** |
| 3 | Validación de límites de Meta (§3.3) | **No implementada** — a propósito, ver §4 |
| 4 | Evento `message.received` con `type: "interactive"`, título en `text`, objeto `interactive` | **Hecho** |
| 5 | Degradación para gateways sin interactivos | **Hecho** — texto numerado, ver §5 |
| 6 | Probado extremo a extremo | **Pendiente**, ver §6 |

17 comprobaciones automatizadas de las transformaciones, 0 fallos. Lo verificado son las
conversiones de payload, no un mensaje llegando a un teléfono.

---

## 2. (A) Salida — pueden empezar a mandar botones

El endpoint acepta ya el body que documentaron:

```http
POST https://poncedeleon.sofopolis.com/api/v1/whatsapp/conversations/{id}/messages
Authorization: Bearer <CRM_API_KEY>
Content-Type: application/json

{
  "interactive": {
    "type": "button",
    "body": { "text": "¿Está buscando un departamento de 2 o 3 dormitorios?" },
    "action": {
      "buttons": [
        { "type": "reply", "reply": { "id": "btn_1", "title": "De 2 dormitorios" } },
        { "type": "reply", "reply": { "id": "btn_2", "title": "De 3 dormitorios" } }
      ]
    }
  }
}
```

Reglas, tal como las pidieron:

- `text` es obligatorio **solo si no viene** `interactive` (`required_without`).
- Si vienen los dos, se envía el interactivo y se ignora el `text`.
- El objeto `interactive` se reenvía **tal cual** a Cloud API. El CRM no reescribe títulos ni ids.
- `reply_to_id` sigue igual.

Respuesta OK, sin cambios respecto de hoy:

```jsonc
{ "message": { "id": 165, "status": "queued", "sender": "ia" } }
```

Siguen aplicando los mismos bloqueos: **409** si un asesor tomó la conversación, **422** fuera de
la ventana de 24 h. Los interactivos son mensajes de sesión, así que la ventana los alcanza igual.

---

## 3. (B) Entrada — así les llega la selección

Cuando el cliente toca un botón o elige una fila, el evento sale con este formato:

```jsonc
{
  "event": "message.received",
  "conversation_id": 5,
  "gateway": "cloud_api",
  "ai_enabled": true,
  "contact": { "phone": "+59176616013", "name": "Alejandro", "person_id": 7, "lead_id": null },
  "message": {
    "id": 170,
    "type": "interactive",
    "text": "De 2 dormitorios",
    "interactive": {
      "button_reply": { "id": "btn_1", "title": "De 2 dormitorios" }
    },
    "timestamp": "2026-09-30T07:12:00-04:00"
  },
  "history": [ /* … */ ],
  "window": { "open": true, "expires_at": "…" },
  "reply": {
    "method": "POST",
    "url": "https://poncedeleon.sofopolis.com/api/v1/whatsapp/conversations/5/messages"
  }
}
```

- `message.text` lleva el **título elegido**, como pidieron en la nota clave de §4.
- `message.interactive` lleva `button_reply` o `list_reply` con `{ id, title }`, tal cual lo mandó
  Meta: el CRM no lo reinterpreta.
- **La clave `interactive` se omite** cuando el mensaje no es una selección, en vez de ir en `null`.
  Así no hay que distinguir «no hubo selección» de «hubo una vacía».

---

## 4. Sobre la validación de límites (§3.3) — no la pusimos

Decidimos **no** validar los límites de Meta en el CRM. El razonamiento:

Ustedes ya los respetan al construir el payload, y validar por duplicado obliga al CRM a decidir
qué hacer cuando algo se pasa — reescribir un título es peor que rechazarlo, porque rompe la
correspondencia entre lo que ustedes definieron y lo que el cliente ve.

Si un payload excede un límite, la Cloud API responde con su propio error (#131009 para Markdown
en títulos, u otro), el mensaje queda en `status: "failed"` y **el motivo se guarda en el campo
`error`**, visible para el asesor en el inbox.

Si prefieren un `422` previo del CRM en lugar del error de Meta, lo agregamos; díganlo.

---

## 5. Degradación (§6) — elegimos texto numerado

Tomamos **la opción que recomendaron**. Un gateway sin botones nativos (Kommo salesbot) recibe el
enunciado seguido de las opciones numeradas, como mensaje de texto normal:

```
¿Está buscando un departamento de 2 o 3 dormitorios?

1. De 2 dormitorios
2. De 3 dormitorios
```

Funciona igual con listas de varias secciones: la numeración es continua a lo largo de todas las
filas. Un interactivo sin opciones envía solo el enunciado.

**No devolvemos nunca `422 interactive_not_supported`**, así que **no necesitan lógica de
reintento en texto**: el mensaje siempre sale. Pueden mandar el mismo payload interactivo sea cual
sea el gateway de la conversación.

El único efecto colateral: la respuesta del cliente llegará como texto (`"2"`, o `"De 3
dormitorios"` si lo escribe), no como `type: "interactive"`. Por Kommo no hay `id` que devolver,
porque no hubo botón que tocar.

---

## 6. Lo que falta: la prueba real

Verificamos las transformaciones en ambos sentidos, no un mensaje real. Queda pendiente:

1. El agente manda botones a un número de prueba.
2. Llegan al teléfono y se ven como botones.
3. El cliente toca uno.
4. El evento llega con `type: "interactive"`, el título en `text` y el `id` correcto.

Cuando quieran coordinamos un número y lo corremos juntos.

---

## 7. Dos correcciones a su documento

**La `reply.url` no es la que aparecía en el reporte anterior.** Enviamos:

```
POST /api/v1/whatsapp/conversations/{id}/messages
```

No `/…/reply`. El §4 de su documento nuevo ya la tiene bien, pero el reporte previo decía `/reply`.
Recomendación: leer siempre el campo `reply.url` del payload en vez de construir la URL desde una
plantilla — así un cambio nuestro no los rompe.

**El `Base URL` del documento dice `imprimir.sofopolis.com`**, que es otro despliegue. Estos
cambios están en **`poncedeleon.sofopolis.com`**. Si el destinatario era `imprimir`, avísennos y
replicamos ahí; el módulo es el mismo.

---

## 8. Pendiente aparte, no relacionado con interactivos

Al revisar el reporte anterior encontramos que el token Sanctum `agente-ia-sofia`
**no registra uso desde el 27 de agosto**, mientras el token `android` sí se usa a diario. Sanctum
actualiza `last_used_at` en cada petición autenticada.

Eso no cuadra con `crm_response_sent` sin errores del lado del agente. O están respondiendo con
otro token, o las respuestas no están llegando a nuestro endpoint. Vale la pena confirmar qué
`CRM_API_KEY` tienen configurado.
