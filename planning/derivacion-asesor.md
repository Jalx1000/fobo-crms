# Derivación a un asesor humano — guía para el agente IA

Complemento de [`integracion-agente.md`](./integracion-agente.md). Ahí está cómo el
agente recibe eventos y responde texto; acá, cómo **suelta** la conversación cuando
el cliente pide hablar con una persona.

Base URL: `https://imprimir.sofopolis.com`

## El flujo

```
Cliente: "quiero hablar con alguien"
      │
      ▼
CRM ──evento message.received──► AGENTE
                                   │
                                   │ 1. avisa al cliente en lenguaje natural
                                   │ 2. llama a la tool
                                   ▼
      POST /api/v1/whatsapp/conversations/{id}/handoff
      Authorization: Bearer <API_KEY_DE_USUARIO>
                    │
                    ▼
              CRM  ├─ apaga el agente en esa conversación
                   ├─ decide a quién le toca:
                   │    dueño del lead → dueño del contacto → POOL
                   │    (saltea los roles de gestión)
                   ├─ deja nota en el timeline del lead y del contacto
                   └─ manda mail al asesor (o al equipo, si quedó en el pool)
                    │
                    ▼
              INBOX  chip "Sin asesor 3m" + banner con el motivo
                     y botón "Tomar conversación"
```

A partir del `200`, **el agente deja de recibir eventos de esa conversación**. Es lo
que garantiza que la IA no le hable por encima al asesor.

---

## 1. La tool

```json
{
  "name": "derivar_a_asesor",
  "description": "Deriva la conversación a un asesor humano del equipo de ventas. Usala cuando el cliente pida explícitamente hablar con una persona, cuando muestre enojo o frustración, o cuando la consulta exceda lo que podés resolver (precios especiales por volumen, reclamos, temas de pago o facturación). Después de llamarla NO debés escribir nada más al cliente: pasa a atenderlo una persona.",
  "parameters": {
    "type": "object",
    "properties": {
      "conversation_id": {
        "type": "integer",
        "description": "El conversation_id que vino en el evento message.received"
      },
      "reason": {
        "type": "string",
        "description": "Motivo en una frase, en español, para que el asesor entienda el contexto sin leer todo el chat. Ej: 'Pide cotización por 5000 volantes con descuento por volumen'"
      }
    },
    "required": ["conversation_id", "reason"]
  }
}
```

El request que ejecuta:

```bash
curl -X POST https://imprimir.sofopolis.com/api/v1/whatsapp/conversations/5/handoff \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer 12|AbCdEf...' \
  -d '{ "reason": "El cliente pide hablar con un asesor de ventas" }'
```

Respuesta:

```json
{
  "handoff": {
    "state": "requested",
    "open": true,
    "pooled": true,
    "reason": "El cliente pide hablar con un asesor de ventas",
    "source": "ai",
    "requested_at": "2026-07-30T09:14:22-04:00",
    "assigned_user": null,
    "assigned_user_name": null,
    "assigned_at": null,
    "changed": true
  },
  "agent": { "enabled": false, "effective": false }
}
```

Y una vez que un asesor la reclama:

```json
"assigned_user": 138,
"assigned_user_name": "Ana Pérez",
"assigned_at": "2026-07-30T09:20:01-04:00"
```

| Campo | Qué significa |
|---|---|
| `state` | `requested` (nadie la tomó) · `assigned` (tiene asesor) · `resolved` (cerrada) |
| `pooled` | `true` = quedó en el pool del equipo, la toma el primero que llegue |
| `assigned_user` | **`int \| null`** — ID del asesor, `null` si está en el pool |
| `assigned_user_name` | **`string \| null`** — nombre del asesor, `null` si está en el pool |
| `changed` | `false` = ya había una derivación abierta, no se hizo nada nuevo |

> Este doc mostraba antes **solo** el caso `null`, sin decir de qué tipo era el campo
> poblado. El agente lo tipó `int` y el CRM mandaba un objeto: el resultado fue que
> el agente dejó de responder en cuanto alguien tomaba una conversación. Ver
> [`contrato-handoff-agente.md`](./contrato-handoff-agente.md).

Es el mismo `reason` que ve el asesor en el banner del inbox y en el mail. Escribilo
para una persona, no para un log.

---

## 2. El prompt

Pegá este bloque en el system prompt del agente.

```
## Derivación a un asesor humano

Tenés la herramienta `derivar_a_asesor`. Usala cuando:

- El cliente pida hablar con una persona, un asesor, un humano, un
  encargado o "alguien de verdad".
- El cliente esté molesto, frustrado o repita un reclamo.
- La consulta exceda lo que podés resolver: descuentos por volumen,
  precios especiales, reclamos por un trabajo entregado, temas de pago,
  facturación o cambios sobre un pedido ya confirmado.
- Hayas intentado resolver algo dos veces y el cliente siga sin quedar
  conforme.

Reglas al derivar:

1. Antes de llamar a la herramienta, avisale al cliente en un mensaje
   breve y natural. Ejemplo: "Te comunico con un asesor del equipo, en
   un momento te escriben por acá." No prometas tiempos concretos.
2. Recién después llamá a `derivar_a_asesor` con un `reason` claro.
3. Una vez que la herramienta responda OK, NO vuelvas a escribirle al
   cliente en esa conversación, aunque siga mandando mensajes. La
   atiende una persona.
4. Si la herramienta ya fue llamada antes en esta conversación, no la
   llames de nuevo: ya hay un asesor asignado.
5. No le digas al cliente que fue "derivado en el sistema" ni menciones
   herramientas, tickets ni el CRM. Hablá siempre en lenguaje natural.

NO derives por: consultas de precios de lista, tiempos de entrega,
formatos, materiales, horarios o dirección. Eso lo resolvés vos.
```

El punto 1 existe porque **el CRM no le manda nada al cliente al derivar**: fue una
decisión de diseño para que no lleguen mensajes robóticos. Si el agente no avisa,
el cliente queda en silencio hasta que un asesor le escriba.

---

## 3. Chequear el estado antes de actuar

El evento `message.received` ya trae el bloque:

```jsonc
{
  "event": "message.received",
  "conversation_id": 5,
  "contact": { "phone": "+591...", "person_id": 7, "lead_id": 11 },
  "message": { "text": "quiero hablar con alguien", "...": "..." },
  "history": [ /* ... */ ],
  "handoff": { "state": null, "open": false, "assigned_user": null, "assigned_user_name": null },
  "window": { "open": true, "expires_at": "..." }
}
```

Si `handoff.open` viene en `true`, **no respondas**: ya hay alguien atendiendo.

También está en `GET /api/v1/whatsapp/conversations/{id}/messages`, por si tu
workflow prefiere consultarlo en vez de leerlo del evento.

---

## 4. Errores

| Código | Cuándo | Qué hacer |
|---|---|---|
| `200` con `changed: false` | Ya había una derivación abierta | Nada. **No es error**: es idempotente a propósito para que un reintento no te meta en loop |
| `409` en `POST /messages` | Intentaste mandar un mensaje con la conversación ya derivada | Terminá el flujo. **No reintentar.** Pasa legítimamente: si el asesor deriva desde el inbox mientras pensabas la respuesta, tu mensaje llega tarde y el CRM lo rechaza |
| `404` | `conversation_id` inexistente | Revisar el id del evento |
| `401` | Token inválido o ausente | Revisar el Bearer |

Cuerpo del `409`:

```json
{
  "message": "Esta conversación fue derivada a un asesor. El agente ya no debe responder.",
  "handoff": { "open": true, "state": "requested" }
}
```

---

## 5. A quién le llega

El ruteo es: **dueño del lead → dueño del contacto → pool del equipo**.

Los roles de gestión (`WHATSAPP_HANDOFF_EXCLUDE_ROLES`, por defecto
`Administrador,Supervisores,Sistema`) no son destinatarios válidos: si el dueño del
lead tiene uno de esos roles, la conversación cae al pool en vez de quedar parada en
alguien que no atiende WhatsApp.

Configuración en el `.env` del CRM:

```dotenv
# Avisar por mail al derivar (el chip del inbox funciona igual)
WHATSAPP_HANDOFF_NOTIFY=true

# Roles que NO atienden derivaciones
WHATSAPP_HANDOFF_EXCLUDE_ROLES=Administrador,Supervisores,Sistema

# Acotar el pool a un grupo de Krayin (vacío = todos los usuarios activos)
WHATSAPP_HANDOFF_POOL_GROUP_ID=

# Ids de usuario que nunca reciben mail
WHATSAPP_HANDOFF_EXCLUDE_USERS=

# Direcciones extra además de los usuarios (casilla compartida)
WHATSAPP_HANDOFF_POOL_EMAILS=
```

Después de tocar el `.env`:

```bash
php artisan config:clear
php artisan queue:restart   # el mail sale de un job en la cola whatsapp
```

> El mail es **refuerzo**, no el mecanismo. La señal en tiempo real es el chip
> **"Sin asesor"** con la antigüedad en la lista del inbox, que se refresca solo.
> Si el mail falla o cae en spam, la conversación sigue visible.

---

## 6. Del lado del asesor

- **Tomar conversación** — la saca del pool y se la asigna. Si dos hacen clic a la
  vez, gana uno y el otro ve un aviso; nunca quedan dos dueños.
- **Escribir sin tomarla** — si está en el pool, escribir ya la reclama. Sobre una
  asignada a otro se puede escribir igual (supervisores, reemplazos), con el banner
  "Atiende Fulano" a la vista.
- **Cerrar derivación** — devuelve la conversación al agente IA. El checkbox viene
  tildado por defecto, para que no quede en tierra de nadie: IA apagada y sin
  derivación abierta.
- **El switch de Agente IA queda bloqueado** mientras haya una derivación abierta.
  Hay que cerrarla para reactivarlo.
- **Filtros de la lista:** "Sin asesor" (pool), "Míos", y "Sin contacto" (que es
  otra cosa: conversaciones sin persona/lead vinculado).

---

## 7. Checklist para conectar

- [ ] Token Sanctum del usuario `Agente` (#13) — es **distinto** de
      `WHATSAPP_AGENT_TOKEN`: ese es el que el CRM te manda a vos, este es el que
      vos usás para volver al CRM.
- [ ] Tool `derivar_a_asesor` registrada en el workflow.
- [ ] Bloque del punto 2 agregado al system prompt.
- [ ] El workflow corta después de un `200`: no manda más mensajes en esa
      conversación.
- [ ] El workflow trata el `409` como fin de flujo, no como error a reintentar.
- [ ] Hay al menos un usuario con rol de ventas y correo válido, si no el pool se
      queda sin destinatarios.
- [ ] Probado punta a punta: mandar "quiero hablar con un asesor" por WhatsApp real
      → el agente avisa y deriva → aparece el chip en el inbox → llega el mail →
      el asesor la toma y responde → cierra y la IA vuelve a atender.

## Nota de auditoría

Aunque el token sea del usuario `Agente`, la nota que queda en el timeline del lead
dice *"Derivación solicitada por el agente IA"*, no el nombre del usuario. Es a
propósito: nadie debería leer el historial y creer que una persona pidió la
derivación.
