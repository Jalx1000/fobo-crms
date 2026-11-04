# Por qué el agente no responde a 59175640698 y 59175599756 (16/09/2026)

## Resumen

El CRM está entregando los eventos. El agente los recibe y decide no contestar,
porque el historial que le mandábamos **no distinguía lo que escribió él de lo
que escribió un asesor**. Ya lo corregimos de nuestro lado; falta un ajuste del
lado de ustedes.

## Lo que se verificó en el CRM

| Comprobación | Resultado |
|---|---|
| `ai_enabled` | `true` en ambas |
| `handoff.open` | `false` (estado `resolved`) en ambas |
| `agenteDebeResponder()` | `true` en ambas |
| Cola `whatsapp` | 0 pendientes - todo procesado |
| Log de errores del webhook | sin `el agente IA rechazo el evento` ni timeouts |
| Agente vivo | sí (405 a GET, espera POST) |
| ¿Contestó a otros hoy? | **sí**: conv 2 a las 10:11 y conv 2722 a las 10:19 |

## El patrón

| Conversación | ¿Un asesor escribió después de derivar? | ¿El agente responde? |
|---|---|---|
| 3 · 59175640698 | Sí - *«Ok gracias por escribirnos»* | **No** (3 mensajes sin respuesta) |
| 2560 · 59175599756 | Sí - *«…la cantidad mínima es de 50 rollos»* | **No** (4 mensajes sin respuesta) |
| 2 | No | Sí |
| 2722 | No | Sí |

Calla exactamente en las conversaciones donde intervino un humano.

## La causa

`AgentPayload::history()` mandaba **todo** lo saliente como `role: "assistant"`:

```json
{"role":"assistant","content":"Ok gracias por escribirnos","type":"text"}
```

Ese mensaje lo escribió un asesor desde el inbox, pero al agente le llegaba
como suyo. Leyendo su propio historial, el agente ve que ya se despidió del
cliente y no tiene nada más que decir: los «Buenos días» siguientes no le
disparan ninguna acción.

## Lo que cambiamos (ya está)

Cada entrada del historial lleva ahora **`sender`**, con el valor real:

```json
{"role":"user",     "sender":"contact","content":"Hola buenos dias","type":"text"}
{"role":"assistant","sender":"ia",     "content":"¿De qué ciudad nos escribe?","type":"text"}
{"role":"assistant","sender":"human",  "content":"Ok gracias por escribirnos","type":"text"}
```

- `contact` - el cliente.
- `ia` - ustedes.
- `human` - un asesor escribiendo desde el inbox del CRM.

El `role` **no cambia** (user/assistant), para no romper lo que ya tienen
armado. `sender` viaja al lado y se puede ignorar hasta que lo usen.

## Lo que hace falta de su lado

1. **No traten `sender: "human"` como propio.** Un mensaje de asesor es
   contexto, no una acción suya ya realizada.
2. **La decisión de callar tiene que salir de `handoff.open`, no del
   historial.** El payload ya trae el bloque `handoff` con el estado real:

   ```json
   "handoff": { "state": "resolved", "open": false, "assigned_user": 256,
                "assigned_user_name": "Mariano Pinell" }
   ```

   Con `open: false` la conversación volvió a ser suya, aunque un asesor haya
   escrito antes. Con `open: true` no manden nada (el CRM igual responde 409).

3. Repasen la regla del prompt *«a partir de la derivación no vuelvas a
   escribir»*: hoy se vuelve permanente. Debe valer **mientras el handoff esté
   abierto**, no para siempre.

## Cómo reproducirlo

Manden un mensaje a una conversación con `handoff.open: false` cuyo historial
tenga un `sender: "human"` al final. Antes del cambio el agente callaba; con
`sender` ya pueden distinguirlo.
