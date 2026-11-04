# Etapa del pedido: intervención del asesor y tool `mover_etapa` (28/10)

## Lo que hace el CRM solo

Cuando un **asesor** le escribe al cliente, el pedido pasa de NO ATENDIDOS a
**SOLICITUD RECIBIDA**. Cuenta todo mensaje con `sender = "human"`:

- inbox del CRM (texto, archivo, ubicación, plantilla), Productos y el envío de
  documentos;
- lo que el asesor escribe **en Kommo** (saliente de Kommo con autor persona);
- lo que escribe **desde la app WhatsApp Business** del mismo número
  (`smb_message_echoes` de Meta).

Nunca hace retroceder un pedido que ya está más adelante. Los mensajes del
agente (`ia`) y el recontacto automático (`system`) no lo mueven.

**El agente no tiene que hacer nada para esto.**

## Tool nueva para el agente: `mover_etapa`

```
POST /api/v1/whatsapp/conversations/{id}/etapa
Authorization: Bearer <token>
{ "etapa": "solicitud-recibida" }
```

| Respuesta | Qué significa |
|---|---|
| `200 {"movido": true,  "pedido": {"id": 1075, "etapa": "solicitud-recibida"}}` | Se movió |
| `200 {"movido": false, "pedido": {"id": 1075, "etapa": "cotizacion-enviada"}}` | Ya estaba más adelante: no es error, seguir |
| `422` | Etapa no permitida, o la conversación no tiene pedido |

- Etapas permitidas hoy: solo `solicitud-recibida`, y solo desde NO ATENDIDOS.
  Se amplían en `whatsapp.ai.etapas` (config), cada una con sus etapas de origen.
- `GET /conversations/{id}/messages` ahora trae `pedido.etapa`, para ver la etapa
  antes de llamar.
- Al crear la cotización (`/cotizacion`) el pedido ya pasa a SOLICITUD RECIBIDA:
  ahí no hace falta llamar.

## Pendiente de configuración (fuera del código)

1. **Kommo:** el webhook tiene que avisar también los mensajes **salientes**. Lo
   que llegue y no se tome queda en el log como `Kommo: mensaje saliente
   descartado` con `author_type`; si el asesor llega con otro tipo que
   `internal`/`user`, se agrega en `whatsapp.gateways.kommo.echo_author_types`.
2. **Meta:** la app tiene que estar suscripta al campo `smb_message_echoes`, y el
   número en coexistencia con la app WhatsApp Business.
