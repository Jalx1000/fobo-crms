# Consigna para el repo del agente — payload rechazado y mensajes perdidos

> **Para**: quien mantiene el servicio Python del agente (FastAPI + LangChain).
> **Contexto completo del contrato**: [`integracion-agente.md`](./integracion-agente.md)
> **Del lado del CRM no hay nada que cambiar.** Mandar `null` es correcto y deliberado.

---

## El bug

En producción, el webhook del CRM rechaza mensajes y los pierde en silencio.<<>>

```
File "/app/app/api/v1/crm.py", line 164, in receive_crm_event
  event = CrmWebhookEvent.model_validate_json(raw)
pydantic_core.ValidationError: 1 validation error for CrmWebhookEvent
contact.phone
  Input should be a valid string [type=string_type, input_value=None, input_type=NoneType]
```

**Causa**: el CRM manda `contact.phone = null` y el modelo lo declara `str`
obligatorio.

No es un error del CRM. Facebook Messenger identifica al contacto con un PSID
(page-scoped id) y **no expone el teléfono por ninguna vía**: en ese canal
`phone` es `null` siempre, no ocasionalmente. En Kommo también puede faltar (el
CRM lo busca con una segunda llamada a la API y esa llamada puede fallar). Solo
`cloud_api` lo trae garantizado.

---

## Tareas

### 1. `contact.phone` opcional

```python
phone: str | None = None
```

### 2. Revisar el modelo completo contra la tabla de nulabilidad

Está en [`integracion-agente.md`](./integracion-agente.md), sección
*"Campos que pueden venir null"*. Son 20 campos, ya verificados contra el código
del CRM.

**El próximo crash garantizado es `message.text`**: viene `null` en audio,
sticker y ubicación. `history[].content` tiene el mismo problema.

### 3. `gateway` no debe ser un Enum cerrado

Se van a agregar canales — Instagram Direct es el próximo. Un valor desconocido
no puede reventar el parseo del evento entero.

```python
# MAL
class Channel(str, Enum):
    CLOUD_API = "cloud_api"
    KOMMO = "kommo"
    MESSENGER = "messenger"

gateway: Channel

# BIEN
gateway: str

TEXT_ONLY = {"kommo"}
NO_PHONE  = {"messenger"}
```

Regla: **canal desconocido = tratalo como solo-texto y sin teléfono.** Es el
comportamiento que funciona en todos.

### 4. Devolver el status correcto (esto es lo que hizo el bug invisible)

Hoy, cuando el parseo falla, el endpoint responde `200 OK`:

```
INFO: "POST /api/v1/crm/webhook HTTP/1.1" 200 OK
pydantic_core.ValidationError: ...
```

Desde el CRM **todo se ve bien**: no hay reintento, no hay error visible, y cada
mensaje se descarta en silencio. Por eso esto pudo estar pasando sin que nadie
lo notara.

Lo que corresponde:

| Situación | Status | Por qué |
|---|---|---|
| Payload no parseable | `422` | El CRM lo registra. **No se arregla reintentando**: el mismo cuerpo produce el mismo error las 4 veces |
| Agente caído / reiniciando / dependencia lenta | `503` o timeout | Transitorio — acá los reintentos del CRM sí rescatan el mensaje |
| Procesado OK | `200` | — |

> El CRM ya reintenta: **1 intento inicial + 3 reintentos espaciados 1 minuto**
> (`NotifyAgent.php`). El minuto es para que un agente que se está reiniciando
> alcance a levantar.
>
> Por eso importa distinguir 422 de 5xx: reintentar un error de validación
> desperdicia los 4 intentos en un fallo determinista, y el mensaje se pierde
> igual — pero cuatro minutos más tarde.

### 5. Arreglar la alerta

```
alert_email_failed event=crm_payload_parse_error error=timed out
```

El agente intenta avisar por email pero el envío da timeout. **Dos capas de
silencio**: el error se traga a sí mismo y el mecanismo de aviso está roto. Es
la combinación que dejó esto corriendo sin que nadie se enterara.

### 6. Comportamiento por canal al responder

| `gateway` | `contact.phone` | Qué puede enviar el agente |
|---|---|---|
| `cloud_api` | siempre | texto, imagen, audio, video, documento, sticker, ubicación |
| `kommo` | **puede faltar** | **solo texto** |
| `messenger` | **nunca** | texto, imagen, audio, video, documento |

- **`kommo` solo relaya texto.** Mandar una imagen o un documento por ese canal
  no llega. Degradar a texto si `gateway == "kommo"`.
- **`messenger` no tiene teléfono.** Si el flujo lo necesita, hay que pedírselo
  al cliente en la conversación, no leerlo del payload.
- **Identificar la conversación por `conversation_id`**, nunca por teléfono.
- Adaptar la redacción: *"te lo mando por WhatsApp"* no tiene sentido para
  alguien que escribió por Messenger.

---

## Mientras tanto

`MESSENGER_AI_ENABLED=false` **bloquea el agente IA en todo el canal Messenger**:
ninguna conversación lo usa, ni siquiera las que un asesor haya encendido desde
la bandeja, y el switch del inbox queda deshabilitado.

Hasta que se despliegue este fix, dejalo en `false` y reiniciá los workers. Así
los mensajes de Messenger quedan esperando a un asesor humano en vez de perderse
contra el agente.

```bash
php artisan config:clear
php artisan queue:restart
```

---

## Nota sobre dónde vive este archivo

En el repo del agente, la guía de integración está en
`app/core/prompts/imprimir.md`. Si ese directorio alimenta el system prompt del
LLM, hay una confusión de roles: esto es una especificación para **programar el
servicio**, no instrucciones de conversación para el modelo. Metida en el prompt
gasta contexto con tablas de nulabilidad que no sirven para hablar con un
cliente, y no arregla nada — el crash es de Pydantic y ocurre **antes** de que el
LLM se entere. Vale confirmar si se inyecta o si solo está guardada ahí.
