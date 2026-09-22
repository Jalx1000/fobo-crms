# Derivación a un asesor — contrato para el agente de WhatsApp

> Para el equipo que mantiene el agente IA de kohlberg.
> Escrito por el equipo del CRM (Krayin/Laravel, `kohlberg.sofopolis.com`).
> **Estado: implementado y probado contra la instancia real.**

Cómo el agente **suelta** una conversación cuando la tiene que atender una persona.

Base URL: `https://kohlberg.sofopolis.com`
Auth: `Authorization: Bearer <API_KEY_DE_USUARIO>` (Sanctum), la misma que ya usás.

---

## 1. Lo que hace distinto a este CRM: se rutea por CIUDAD

En kohlberg **cada ciudad es un pipeline y tiene un Encargado que la atiende**:

| Ciudad | Asesor |
|---|---|
| Tarija | Daniel Escalante |
| Santa Cruz | Liliana Alarcon |
| La Paz | Stefani Mendieta |
| Cochabamba | Gabriel Muñoz |
| Sucre | Silvia Taboada |
| Potosí | María Rene Solano Ruiz |
| Oruro | Nancy Delgado |

**No hace falta que mandes el asesor.** El CRM lo resuelve. Lo que sí ayuda —y mucho— es que
mandes la **ciudad**, porque el CRM la busca en este orden:

```
1. El pipeline del lead del cliente        ← lo más confiable
2. La ciudad cargada en la ficha del cliente
3. La ciudad que mandás vos en `ciudad`    ← lo que averiguaste hablando
4. Nada  →  la conversación queda en el POOL del equipo
```

> **Por qué el paso 3 importa tanto:** medido sobre las 26 conversaciones reales, **solo 2
> tienen lead** y **9 tienen la ciudad en la ficha**. Para 17 de 26 el CRM no sabe de dónde
> escribe el cliente. Si no mandás `ciudad`, esas 17 derivaciones caen al pool y nadie las
> atiende personalmente.

**Tu ciudad nunca pisa un dato del CRM.** Va última a propósito: si el equipo ya clasificó al
cliente, ese dato manda. Pero si el CRM no la tiene, la tuya se usa **y se guarda en la ficha
del cliente**, así la próxima conversación ya la sabe.

---

## 2. La tool

```json
{
  "name": "derivar_a_asesor",
  "description": "Deriva la conversación a un asesor humano de la ciudad del cliente. Usala cuando el cliente pida explícitamente hablar con una persona, cuando muestre enojo o frustración, o cuando la consulta exceda lo que podés resolver (reclamos, temas de pago, precios especiales, cambios sobre un pedido ya confirmado). Después de llamarla NO escribas nada más al cliente: lo atiende una persona.",
  "parameters": {
    "type": "object",
    "properties": {
      "conversation_id": {
        "type": "integer",
        "description": "El conversation_id que vino en el evento message.received"
      },
      "reason": {
        "type": "string",
        "description": "Motivo en una frase, en español, para que el asesor entienda el contexto sin leer todo el chat. Ej: 'Reclama que su pedido de 3 cajas llegó incompleto'"
      },
      "ciudad": {
        "type": "string",
        "description": "Ciudad del cliente, si la sabés por la conversación. Una de: Tarija, Santa Cruz, La Paz, Cochabamba, Sucre, Potosí, Oruro. Omitila si no estás seguro; NO la inventes ni la deduzcas del código de área."
      }
    },
    "required": ["conversation_id", "reason"]
  }
}
```

### El request

```bash
curl -X POST https://kohlberg.sofopolis.com/api/v1/whatsapp/conversations/8/handoff \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer 12|AbCdEf...' \
  -d '{
        "reason": "Reclama que su pedido llegó incompleto",
        "ciudad": "Cochabamba"
      }'
```

### La respuesta `200`

```jsonc
{
  "handoff": {
    "state": "assigned",              // requested | assigned | resolved
    "open": true,
    "pooled": false,                  // true = quedó en el pool del equipo
    "reason": "Reclama que su pedido llegó incompleto",
    "source": "ai",
    "city": "Cochabamba",             // la ciudad con la que se ruteó
    "requested_at": "2026-09-01T17:40:12-04:00",
    "assigned_user": 15,              // int | null  ← OJO, ver §4
    "assigned_user_name": "Gabriel Muñoz",
    "assigned_at": "2026-09-01T17:40:12-04:00",
    "changed": true                   // false = ya había una derivación abierta
  },
  "agent": { "enabled": false, "effective": false }
}
```

Si no se pudo resolver la ciudad:

```jsonc
"state": "requested", "pooled": true, "city": null,
"assigned_user": null, "assigned_user_name": null
```

Sigue siendo un `200` y sigue siendo una derivación válida: la toma el primero del equipo que
entre al inbox. No reintentes.

### `ciudad` tolera cómo lo escriba el cliente

`potosi`, `Potosí`, `POTOSI` y `  potosi  ` resuelven todos a Potosí. No pelees con la tilde.
Lo que **no** resuelve es una ciudad que no está en la lista (`Miami` → pool).

---

## 3. Qué pasa desde el `200`

```
Cliente: "quiero hablar con alguien"
      │
      ▼
CRM ──evento message.received──► AGENTE
                                   │ 1. le avisás al cliente en lenguaje natural
                                   │ 2. llamás a derivar_a_asesor
                                   ▼
                            POST .../handoff
                                   │
                       CRM  ├─ apaga el agente en esa conversación
                            ├─ resuelve la ciudad y busca su Encargado
                            ├─ deja nota en el timeline del cliente
                            └─ manda mail al asesor (o al equipo, si es pool)
                                   │
                                   ▼
                            INBOX  banner "DERIVADA · Cochabamba · Atiende
                                   Gabriel Muñoz" + botón para cerrarla
```

Concretamente, desde ese `200`:

- **Dejás de recibir eventos** de esa conversación. Es lo que garantiza que la IA no le hable
  por encima al asesor.
- **`POST .../messages` responde `409`**, no `200`. Ver §4.
- El asesor puede **cerrar la derivación** desde el inbox, y ahí volvés a atender vos.

---

## 4. 🔴 Dos cosas que ya rompieron en otro tenant

**`assigned_user` es un `int`, no un objeto.**
En el tenant de impresión el CRM devolvía `{"id": 138, "name": "Ana"}` donde el agente tipaba
`int`. Pydantic rechazaba el evento entero, el agente dejaba de responder — **y seguía
contestando `200`**, así que del lado del CRM nunca falló nada visible y tardamos días en verlo.
Acá `assigned_user` es el id y el nombre viaja aparte en `assigned_user_name`.

**Un `409` en `POST .../messages` no es un error tuyo.**
Significa "esta conversación ya la atiende una persona". Soltá el turno y no reintentes. El
cuerpo trae el bloque `handoff` para que sepas quién la tiene:

```jsonc
// 409
{
  "message": "Esta conversación fue derivada a un asesor. El agente ya no responde acá.",
  "handoff": { "state": "assigned", "open": true, "city": "Cochabamba", "assigned_user": 15, ... }
}
```

---

## 5. Códigos de respuesta

| Código | Significado | ¿Reintentar? |
|---|---|---|
| `200` | Derivada (o ya lo estaba: mirá `changed`) | — |
| `401` | Token inválido | no |
| `404` | No existe esa conversación | no |
| `409` | En `POST /messages`: la conversación ya está derivada | no |
| `422` | Payload irreparable | no |
| `5xx` | Fallo transitorio | sí, con backoff |

**Es idempotente.** Llamarla de nuevo con una derivación abierta devuelve `200` con
`changed: false`, sin re-rutear ni volver a avisar al asesor. Un LLM tiende a pedir el handoff
en cada mensaje, y eso no debe llenar de mails a nadie.

---

## 6. El prompt

Pegá este bloque en el system prompt del agente.

```
## Derivación a un asesor humano

Tenés la herramienta `derivar_a_asesor`. Usala cuando:

- El cliente pida hablar con una persona, un asesor, un humano o
  "alguien de verdad".
- El cliente esté molesto, frustrado, o repita un reclamo.
- La consulta exceda lo que podés resolver: reclamos por un pedido
  entregado, temas de pago o facturación, precios especiales, o
  cambios sobre un pedido ya confirmado.
- Hayas intentado resolver algo dos veces y el cliente siga sin
  quedar conforme.

Sobre la ciudad:

- Si en algún momento de la conversación el cliente dijo de qué
  ciudad es, pasala en `ciudad`. Sirve para que lo atienda el asesor
  de su ciudad y no cualquiera.
- Si NO lo sabés con certeza, omití el parámetro. No la deduzcas del
  código de área, del nombre, ni de lo que parezca más probable: una
  ciudad equivocada manda al cliente con el asesor equivocado, y eso
  es peor que dejarlo en el pool del equipo.
- Si la conversación ya venía encaminada y falta poco para saberla,
  podés preguntar: "¿De qué ciudad nos escribís?" antes de derivar.

Reglas al derivar:

1. Antes de llamar a la herramienta, avisale al cliente en un
   mensaje breve y natural. Ejemplo: "Te comunico con un asesor del
   equipo, en un momento te escriben por acá." No prometas tiempos.
2. Recién después llamá a `derivar_a_asesor` con un `reason` claro.
3. Una vez que responda OK, NO vuelvas a escribirle al cliente en esa
   conversación, aunque siga mandando mensajes. Lo atiende una persona.
4. Si ya la llamaste antes en esta conversación, no la llames de
   nuevo: ya hay una derivación abierta.
5. No le digas al cliente que fue "derivado en el sistema", ni
   menciones herramientas, tickets, el CRM ni el nombre del asesor
   salvo que el CRM te lo haya devuelto.
```

---

## 7. Cómo probarlo

```bash
# 1. Derivar (sin ciudad: debería caer al pool si el cliente no la tiene cargada)
curl -sS -X POST "$BASE/api/v1/whatsapp/conversations/8/handoff" \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  -d '{"reason":"prueba"}' | jq '.handoff | {state, pooled, city, assigned_user}'

# 2. Derivar con ciudad: debería quedar assigned
curl -sS -X POST "$BASE/api/v1/whatsapp/conversations/8/handoff" \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  -d '{"reason":"prueba","ciudad":"Cochabamba"}' | jq '.handoff.changed'
# → false la segunda vez: es idempotente

# 3. Intentar responder: tiene que dar 409
curl -sS -o /dev/null -w '%{http_code}\n' -X POST \
  "$BASE/api/v1/whatsapp/conversations/8/messages" \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  -d '{"text":"hola"}'
# → 409
```

---

## 8. Lo que el CRM NO hace, para que lo cubras vos

- **No le avisa nada al cliente.** El mensaje de "te paso con un asesor" lo mandás vos, antes de
  llamar a la tool. Es deliberado: vos sabés en qué tono viene la conversación.
- **No reasigna automáticamente** si el asesor no contesta. Si hace falta, un supervisor
  reasigna a mano desde el inbox.
- **No promete tiempos de respuesta.** Tampoco los prometas vos.
