# Integrar formularios de WhatsApp (agendar, cancelar y reprogramar citas)

Hola equipo. Desde hoy, agendar, cancelar y reprogramar citas se hacen con **formularios nativos de
WhatsApp**, que ya están publicados en producción. El agente solo tiene que detectar la intención y
enviar el formulario. El CRM hace el resto (cédula, horarios de SMD, crear/cancelar/mover la cita)
y le confirma al paciente por el chat.

## 1. Agregar esta herramienta

```
POST https://odontoking.sofopolis.com/api/v1/whatsapp/conversations/{conversation_id}/flows
Authorization: Bearer <token actual del agente>
Content-Type: application/json

{"flow": "paciente_tercero"}   → agendar (para el paciente o para otra persona)
{"flow": "cancelar_cita"}      → cancelar
{"flow": "reprogramar_cita"}   → cambiar día u hora
```

- `conversation_id`: el del webhook `message.received` que están respondiendo.
- Respuestas: `200` = enviado; `422` = conversación con más de 24 h sin mensajes del paciente → derivar a recepción.

Definición para function calling:

```json
{
  "name": "enviar_formulario",
  "description": "Envía al paciente un formulario de WhatsApp para agendar, cancelar o reprogramar una cita. Usar SIEMPRE en lugar de pedir esos datos por chat.",
  "parameters": {
    "type": "object",
    "properties": {
      "flow": {
        "type": "string",
        "enum": ["paciente_tercero", "cancelar_cita", "reprogramar_cita"]
      }
    },
    "required": ["flow"]
  }
}
```

## 2. Agregar al prompt del sistema

```
AGENDAR, CANCELAR Y REPROGRAMAR CITAS
- Para agendar (para el paciente o para otra persona: hijo, padre, pareja, etc.), usa
  enviar_formulario con flow="paciente_tercero". No pidas por chat nombre, cédula,
  especialidad, doctor ni horario: lo pide el formulario.
- Si no sabe qué especialidad necesita, igual envía el formulario (tiene "No sé / Consulta general").
- Para cancelar: flow="cancelar_cita". Para cambiar día u hora: flow="reprogramar_cita".
  Si quiere otro doctor, se cancela y se agenda una nueva.
- Antes de enviarlo, di una frase corta: "Te envío un formulario para agendar 👇".
- Nunca crees, canceles ni muevas citas por otra vía.
- Si la herramienta da error, no insistas: deriva a recepción.

CUANDO LLEGA UN MENSAJE "flow_reply" (datos en message.flow):
- status "pending": ya se está procesando y el sistema le envía la confirmación
  ("✅ Cita confirmada / cancelada / reprogramada"). Responde breve, no repitas la acción.
- status "none": no tenía citas próximas → ofrécele agendar.
- status "handoff": no hay horarios disponibles → deriva a recepción.
```

## 3. Qué les llega cuando el paciente completa un formulario

Un `message.received` con `message.type = "flow_reply"` y los datos en `message.flow`. Por ejemplo:

```json
{"flow": "paciente_tercero", "status": "pending", "patient_name": "Juan Pérez",
 "relation": "hijo", "specialty": "Endodoncia", "doctor_name": "CAROLINA ROJAS",
 "date": "2026-10-15", "time": "10:00"}
```

Los mensajes de confirmación ("✅ …") los manda el CRM directamente al paciente; no disparan un
webhook al agente. **El CRM espera a que ustedes respondan el `flow_reply`** (su "Estoy
registrando…") y recién después envía la confirmación, para que el paciente los vea en ese orden.
Si no responden en 40 s, la confirmación sale igual.

## 4. Dato extra

En cada webhook, `patient_context.pacientes_a_cargo` trae a quiénes agenda ese contacto (hijos,
padres, etc.), y sus citas vienen en `patient_context.citas`. Les sirve para responder "¿cuándo es la
cita de mi hijo?" sin enviar un formulario.

## 5. Pruebas antes de activar

1. "Quiero una cita" → formulario → llega "✅ Cita confirmada".
2. "Cita para mi hijo" → formulario → "Otra persona".
3. "Quiero cancelar mi cita" → llega "✅ Cita cancelada".
4. "Quiero cambiar mi cita" → llega "✅ Cita reprogramada".
5. Contacto sin citas pide cancelar → `status: "none"` → ofrecer agendar.

---

Detalle técnico completo: `docs/agente-flows-instrucciones.md` (repo del CRM).
