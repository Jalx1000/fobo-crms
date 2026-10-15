# Instrucciones para el agente IA: formularios de WhatsApp (Flows)

**Para:** equipo del agente (odontoking.wappy.dev) · **Fecha:** 2026-10-12 · **Estado:** en producción

Desde hoy, **agendar, cancelar y reprogramar citas se hacen con formularios nativos de
WhatsApp**, no conversando. El agente solo tiene que **detectar la intención y enviar el
formulario correcto**. El CRM hace el resto: identifica al paciente por su cédula, muestra
horarios reales de SMD, crea, cancela o mueve la cita en el CRM y en SMD, y le confirma al
usuario por el chat.

Detalle técnico completo: `docs/agente-ia-integracion.md` §7 y §8.

---

## 1. Herramienta nueva: `enviar_formulario`

```
POST https://odontoking.sofopolis.com/api/v1/whatsapp/conversations/{conversation_id}/flows
Authorization: Bearer <token del agente>
Content-Type: application/json

{"flow": "paciente_tercero" | "cancelar_cita" | "reprogramar_cita", "text": "opcional"}
```

Respuestas: `200` (enviado), `422` (ventana de 24 h cerrada, canal sin formularios o `flow` inválido), `401` (token).

Definición para function calling (adaptar al formato del framework del agente):

```json
{
  "name": "enviar_formulario",
  "description": "Envía al paciente un formulario de WhatsApp para agendar, cancelar o reprogramar una cita. Usar SIEMPRE en lugar de pedir esos datos por chat.",
  "parameters": {
    "type": "object",
    "properties": {
      "flow": {
        "type": "string",
        "enum": ["paciente_tercero", "cancelar_cita", "reprogramar_cita"],
        "description": "paciente_tercero = agendar (para sí o para otra persona); cancelar_cita = cancelar; reprogramar_cita = cambiar día u hora"
      },
      "text": {
        "type": "string",
        "description": "Mensaje corto que acompaña al botón (opcional)"
      }
    },
    "required": ["flow"]
  }
}
```

`conversation_id` es el del webhook `message.received` que se está respondiendo.

---

## 2. Bloque para el prompt del sistema (listo para pegar)

```
AGENDAR, CANCELAR Y REPROGRAMAR CITAS
- Para agendar una cita (para el paciente o para otra persona: hijo, padre, pareja, etc.),
  usa la herramienta enviar_formulario con flow="paciente_tercero". No pidas por chat el nombre,
  la cédula, la especialidad, el doctor ni el horario: todo eso lo pide el formulario.
- Si el paciente no sabe qué especialidad necesita, igual envía el formulario: tiene la opción
  "No sé / Consulta general".
- Para cancelar una cita, usa enviar_formulario con flow="cancelar_cita".
- Para cambiar el día u hora de una cita, usa enviar_formulario con flow="reprogramar_cita".
  Si quiere cambiar de doctor, explícale que se cancela la cita y se agenda una nueva.
- Antes de enviarlo, di una frase corta, por ejemplo: "Te envío un formulario para agendar 👇".
- Nunca crees, canceles ni muevas citas por otra vía (ni por la API de actividades ni por chat).
- Si enviar_formulario responde un error (la conversación lleva más de 24 h sin mensajes del
  paciente), no insistas: deriva a recepción.

CUANDO EL PACIENTE COMPLETA UN FORMULARIO
Llega un mensaje de tipo "flow_reply" con los datos en message.flow:
- status "pending": la acción ya se está procesando y el sistema le enviará la confirmación
  ("✅ Cita confirmada / cancelada / reprogramada") en segundos. Responde algo breve como
  "¡Listo! Ya estamos procesando tu pedido, en un momento te llega la confirmación." No
  repitas la acción ni inventes datos.
- status "none": no tenía citas próximas. Ofrécele agendar (flow="paciente_tercero").
- status "handoff": no hay horarios disponibles por el formulario. Deriva a recepción.
```

---

## 3. Qué le llega al agente cuando el paciente completa un formulario

Un `message.received` con `message.type = "flow_reply"`. El campo `message.text` trae un
resumen legible y `message.flow` los datos:

| `message.flow.flow` | `status` | Significa | Qué hace el agente |
|---|---|---|---|
| `paciente_tercero` | `pending` | Cita pedida. Se crea en segundos y el CRM le confirma al paciente | Responder breve. **No** agendar |
| `paciente_tercero` | `handoff` | No había horarios disponibles | `POST …/handoff` |
| `cancelar_cita` | `pending` | Cancelación en curso. El CRM confirma | Responder breve |
| `cancelar_cita` | `none` | No tenía citas próximas | Ofrecer agendar |
| `reprogramar_cita` | `pending` | Cambio de horario en curso. El CRM confirma | Responder breve |
| `reprogramar_cita` | `handoff` | No hay horarios con ese doctor | `POST …/handoff` |
| `reprogramar_cita` | `none` | No tenía citas próximas | Ofrecer agendar |

Ejemplo (agendar):

```json
"message": {
  "type": "flow_reply",
  "text": "📅 Pidió cita: Juan Pérez · CAROLINA ROJAS · 2026-10-15 10:00 (confirmando)",
  "flow": {
    "flow": "paciente_tercero", "status": "pending", "patient_status": "created",
    "patient_id": 4521, "patient_name": "Juan Pérez", "relation": "hijo",
    "specialty": "Endodoncia", "doctor_id": 10, "doctor_name": "CAROLINA ROJAS",
    "date": "2026-10-15", "time": "10:00"
  }
}
```

Los mensajes de confirmación ("✅ …") los manda el CRM con `sender: "system"`. Llegan al
paciente, pero **no** disparan un webhook al agente.

---

## 4. Contexto útil que ya viene en cada webhook

`patient_context.pacientes_a_cargo`: personas que este contacto ya agendó (hijos, padres, etc.).

```json
"pacientes_a_cargo": [{"person_id": 4521, "nombre": "Juan Pérez", "relacion": "hijo"}]
```

`relacion` = qué es el paciente para quien escribe: `hijo`, `padre_madre`, `pareja`, `hermano`,
`familiar`, `otro`. Sus citas también aparecen en `patient_context.citas`, con el campo `paciente`.
Sirve para contestar preguntas ("¿cuándo es la cita de mi hijo?") sin enviar un formulario.

---

## 5. Cómo funcionan los formularios (para explicarle al paciente)

- **Agendar:** ¿para quién? → (si es otra persona: nombre, **cédula obligatoria**, fecha de
  nacimiento, relación) → especialidad (o "No sé / Consulta general") → doctor recomendado
  (ordenados por el horario más próximo) → día → hora (**turnos de 1 hora, en punto, hasta
  fin del mes siguiente**) → confirmar.
- **Cancelar:** elige una de sus próximas citas (o de los pacientes a su cargo) → motivo
  opcional → confirmar.
- **Reprogramar:** elige la cita → nuevo día → nueva hora (**mismo doctor**) → confirmar.
- Si dos familiares agendan para la misma persona con la misma cédula, el sistema la reconoce:
  **no se duplica la ficha**.
- El recordatorio de la cita le llega a **quien la agendó**.

---

## 6. Pruebas sugeridas antes de activar en el agente

1. "Quiero una cita" → el agente envía `paciente_tercero` → completar → llega "✅ Cita confirmada".
2. "Quiero una cita para mi hijo" → igual, eligiendo "Otra persona".
3. "No sé qué especialidad" → formulario → "No sé / Consulta general".
4. "Quiero cancelar mi cita" → `cancelar_cita` → llega "✅ Cita cancelada".
5. "Quiero cambiar mi cita para el jueves" → `reprogramar_cita` → llega "✅ Cita reprogramada".
6. Un contacto sin citas pide cancelar → `flow_reply` con `status: "none"` → el agente ofrece agendar.
7. Conversación con más de 24 h sin mensajes del paciente → la herramienta da `422` → el agente deriva.
