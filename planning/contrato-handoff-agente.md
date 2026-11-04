# Contrato del bloque `handoff` - CRM ↔ agente IA

> **Para el equipo del agente LangChain.** Este documento describe un incidente de
> producción del 30/07/2026, el arreglo ya aplicado en el CRM, y los dos cambios
> que quedan pendientes del lado del agente.
>
> Complementa [`integracion-agente.md`](./integracion-agente.md) y
> [`derivacion-asesor.md`](./derivacion-asesor.md).

---

## 1. Qué pasó

El agente dejó de responder a todos los mensajes de una conversación en cuanto un
asesor la reclamaba desde el CRM.

```
crm_payload_parse_error
1 validation error for CrmWebhookEvent
handoff.assigned_user
  Input should be a valid integer
  [type=int_type, input_value={'id': 138, 'name': 'Hola'}, input_type=dict]
  File "/app/app/api/v1/crm.py", line 164, in receive_crm_event
```

El CRM mandaba `handoff.assigned_user` como **objeto**; el modelo `CrmWebhookEvent`
lo tipaba como **entero**. Pydantic rechazaba el evento entero antes de llegar al
LLM: sin respuesta, sin turno, sin nada.

### Por qué tardó en aparecer

Mientras nadie tomaba la derivación, el campo viajaba en `null` y `Optional[int]`
lo aceptaba sin ruido. El bug se activaba recién al asignar un asesor.

| Fecha/hora (local) | `assigned_user` | Resultado |
|---|---|---|
| 29/07 20:52 · 30/07 00:54 | `null` | ✅ respondió |
| 30/07 09:51 (conv 3) | `null` | ✅ respondió |
| **30/07 15:41 y 15:43 (conv 2)** | `{id: 138, name: "Hola"}` | ❌ `crm_payload_parse_error` |

### Por qué nadie se enteró

**El agente devuelve `200 OK` incluso cuando el parseo falla.**

```
INFO: 100.64.0.8:43106 - "POST /api/v1/crm/webhook HTTP/1.1" 200 OK   ← detrás del traceback
```

Desde el CRM la cadena se veía perfecta: el job `NotifyAgent` hace `->throw()`,
así que un 4xx/5xx habría disparado 3 reintentos y habría caído en `failed_jobs`.
Con un 200, el job terminó "bien" y el fallo quedó invisible. Hubo que comparar
`last_used_at` de los tokens Sanctum contra la hora de los mensajes para detectar
que el agente no estaba llamando de vuelta.

### La causa de fondo

Ninguno de los dos lados "rompió" el contrato: **la documentación nunca especificó
la forma no-nula**. `derivacion-asesor.md` solo mostraba ejemplos con
`"assigned_user": null` y lo describía como *"El asesor, o `null` si está en el
pool"*. Cada lado infirió un tipo distinto y ambos eran razonables.

---

## 2. Qué se arregló en el CRM (ya desplegado)

`Handoff::toArray()` alimenta la UI del inbox, que necesita el **nombre** del asesor
para el badge del header. No se puede aplanar sin romper la vista.

Se agregó `Handoff::toAgentArray()`, que aplana solo lo que va hacia el agente:

```php
$payload['assigned_user']      = is_array($user) ? ($user['id'] ?? null) : $user;
$payload['assigned_user_name'] = is_array($user) ? ($user['name'] ?? null) : null;
```

Aplicado en las **cuatro** superficies que consume el agente:

| Dónde | Qué es |
|---|---|
| `AgentPayload::event()` | el evento `message.received` |
| `AgentController::messages()` | `GET /api/v1/whatsapp/conversations/{id}/messages` |
| `AgentController::handoff()` | `POST /api/v1/whatsapp/conversations/{id}/handoff` |
| `AgentController::send()` (409) | rechazo cuando la conversación ya fue derivada |

La UI sigue recibiendo el objeto por `toArray()`. Los dos formatos conviven a
propósito y están separados por método, no por condicional.

---

## 3. El contrato, ahora sí explícito

Bloque `handoff` tal como lo recibe el agente, **en todas** las superficies:

```json
{
  "state": "assigned",
  "open": true,
  "pooled": false,
  "reason": "El cliente pide hablar con un asesor de ventas",
  "source": "ai",
  "requested_at": "2026-07-30T09:14:22-04:00",
  "assigned_user": 138,
  "assigned_user_name": "Ana Pérez",
  "assigned_at": "2026-07-30T09:20:01-04:00",
  "is_mine": false,
  "changed": true
}
```

| Campo | Tipo | Notas |
|---|---|---|
| `state` | `string \| null` | `requested` · `assigned` · `resolved` · `null` (nunca hubo derivación) |
| `open` | `bool` | `true` = hay una derivación viva. **Si es `true`, el agente no responde.** |
| `pooled` | `bool` | `true` = derivada pero sin dueño; la toma el primero que llegue |
| `reason` | `string \| null` | Texto para humanos, se muestra al asesor |
| `source` | `string \| null` | `ai` o `human` |
| `requested_at` | `string \| null` | ISO-8601 con offset |
| **`assigned_user`** | **`int \| null`** | **ID del asesor.** `null` = en el pool o sin derivación |
| **`assigned_user_name`** | **`string \| null`** | **Campo nuevo.** Nombre del asesor, `null` si no hay |
| `assigned_at` | `string \| null` | ISO-8601 con offset |
| `is_mine` | `bool` | Siempre `false` para el agente (es relativo a un usuario del CRM) |
| `changed` | `bool` | **Solo** en la respuesta de `POST /handoff`. Ausente en el resto |

Reglas que conviene fijar en el modelo:

- **Toda clave es siempre presente** salvo `changed`, que aparece únicamente en las
  respuestas de acción. El CRM manda los `null` explícitos a propósito.
- El agente debería **tolerar claves desconocidas** (`model_config =
  ConfigDict(extra="ignore")` en Pydantic v2). `assigned_user_name` llega nuevo hoy;
  mañana puede llegar otro y no debería tumbar el evento.

---

## 4. Lo que falta hacer del lado del agente

### 4.1 Devolver error cuando el parseo falla (importante)

Hoy `receive_crm_event` atrapa el `ValidationError`, lo loguea y responde `200 OK`.
Eso convierte cualquier desajuste de contrato en una falla silenciosa: el CRM lo
cuenta como entrega exitosa y no reintenta.

```python
# app/api/v1/crm.py, alrededor de la línea 164
try:
    event = CrmWebhookEvent.model_validate_json(raw)
except ValidationError as exc:
    logger.error("crm_payload_parse_error", error=str(exc))
    raise HTTPException(status_code=422, detail="invalid payload")  # ← en vez de 200
```

Con un 422, `NotifyAgent` reintenta 3 veces (backoff 10s/30s/60s) y después deja el
job en `failed_jobs` del CRM, visible y con el payload completo. Cualquier
incidente futuro de este tipo se detecta en minutos, no en horas.

> Ojo: **no** devuelvas 5xx para errores de negocio legítimos (por ejemplo
> `ai_enabled: false`), o vas a generar reintentos inútiles. 422 es para "este
> payload no lo puedo parsear".

### 4.2 Alinear el modelo con el contrato de arriba

```python
class HandoffBlock(BaseModel):
    model_config = ConfigDict(extra="ignore")   # tolera campos nuevos del CRM

    state: str | None = None
    open: bool = False
    pooled: bool = False
    reason: str | None = None
    source: str | None = None
    requested_at: str | None = None
    assigned_user: int | None = None            # ← int, coincide con el CRM
    assigned_user_name: str | None = None       # ← nuevo, aprovechable en prompts
    assigned_at: str | None = None
    is_mine: bool = False
    changed: bool | None = None                 # solo en respuestas de acción
```

Con el arreglo del CRM ya desplegado, esto **no es urgente para destrabar** - el
agente vuelve a responder sin tocar nada. Es para que el modelo refleje la realidad
y para poder usar `assigned_user_name` en los prompts ("te está atendiendo Ana").

### 4.3 Sugerencia menor: `alert_email_failed`

En el mismo incidente apareció:

```
alert_email_failed event=crm_payload_parse_error error=timed out
```

La alerta por mail del propio agente se cayó por timeout, así que tampoco avisó por
ese canal. Vale la pena revisar ese path - era la última red de contención.

---

## 5. Cómo verificar que quedó bien

Con una conversación **con asesor asignado** (`state: assigned`), mandá un mensaje
de WhatsApp y confirmá los tres puntos:

1. En los logs del agente aparece `crm_agent_turn_started` - o sea, pasó el parseo.
2. No aparece `crm_payload_parse_error`.
3. En el CRM, el mensaje de respuesta queda con `sender = 'ia'`:

```sql
SELECT id, direction, sender, LEFT(body,40), created_at
FROM kl_whatsapp_messages
WHERE conversation_id = <id> ORDER BY id DESC LIMIT 5;
```

Si `handoff.open` viene en `true`, el comportamiento correcto sigue siendo **no
responder**: la conversación es del asesor. Lo que se arregló es que el agente
pueda *leer* el evento, no que conteste por encima de una persona.
