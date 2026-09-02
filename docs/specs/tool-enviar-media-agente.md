# `enviar_media` — cómo el agente manda las fotos de un inmueble

> Para el equipo del agente (Python / LangGraph).
> **No hay que habilitar nada en el CRM: el endpoint ya existe y está probado.**
> Lo que falta es la tool del lado del agente.

---

## 1. El endpoint

```http
POST https://c21.sofopolis.com/api/v1/inmobiliaria/conversations/{conversation_id}/media
Authorization: Bearer <CRM_API_KEY>
Content-Type: application/json

{ "codigo": "C21-0001", "tipo": "foto", "cantidad": 4 }
```

```jsonc
// 200
{ "message": "Enviado.", "enviados": 4, "codigo": "C21-0001" }
```

- `tipo`: `foto` | `plano` | `video` | `tour`
- `cantidad`: opcional. Por defecto 4, **tope duro 8**. Pedir 50 devuelve 8.
- El CRM elige **qué** fotos y en qué orden: portada primero, después el orden que fijó el asesor.
  El agente nunca ve ni maneja URLs de imágenes.
- Cada archivo se entrega usando el `media_id` que el CRM ya tiene cacheado en Meta, así que el
  envío es una referencia y no una descarga desde nuestro servidor.
- **Las fotos van sin caption**, ninguna. La descripción la manda el agente como texto aparte,
  después de las fotos: con un caption en la primera, el cliente veía dos descripciones.

### Códigos de error

| Código | Qué pasó | Qué hacer |
|---|---|---|
| `404` | El código no existe **o el inmueble no tiene ese tipo de archivo** | Decírselo al cliente. **No reintentar.** |
| `409` | La conversación fue derivada a un asesor | Callarse. El humano la está atendiendo. |
| `422` | Fuera de la ventana de 24 h | Descartar. No reintentar. |
| `501` | El canal activo no soporta media | No pasa con Cloud API. No reintentar. |
| `5xx` | Fallo transitorio | Reintentar con backoff. |

---

## 2. Regla de oro: consultar antes de prometer

`GET /api/v1/inmobiliaria/inmuebles/{codigo}` devuelve `tiene_fotos`, `tiene_plano` y `tiene_tour`.

**Miralos antes de ofrecer.** Si Sofía dice "te mando las fotos" y después recibe un 404, ya prometió
algo que no puede cumplir, y corregirse en el mensaje siguiente queda pésimo. Hoy mismo el galpón
`C21-0001` tiene 11 fotos y la casa `110748` tiene 0: los dos casos van a ocurrir en la misma demo.

---

## 3. La tool

El `conversation_id` **se inyecta server-side y el modelo nunca lo ve**, igual que el resto de los
ids internos. Lo único que el LLM decide es el `codigo` —que es público, lo vio el cliente en el
anuncio— y cuántas fotos mandar.

```python
import os
import httpx
from langchain_core.tools import tool
from langchain_core.runnables import RunnableConfig

CRM_BASE = os.environ["CRM_BASE_URL"]        # https://c21.sofopolis.com
CRM_KEY  = os.environ["CRM_API_KEY"]         # token Sanctum del CRM

class MediaNoDisponible(Exception):
    """El inmueble no tiene ese tipo de archivo cargado."""

@tool
def enviar_media(codigo: str, tipo: str = "foto", cantidad: int = 4,
                 *, config: RunnableConfig) -> str:
    """Envía al cliente fotos, planos, video o el tour virtual de un inmueble.

    Usar SOLO después de haber confirmado con get_inmueble que el inmueble
    tiene ese material (campos tiene_fotos / tiene_plano / tiene_tour).

    Args:
        codigo: código público del inmueble, tal como aparece en el anuncio.
        tipo: "foto", "plano", "video" o "tour".
        cantidad: cuántos archivos enviar. Máximo 8.
    """
    # El id de la conversación viene del contexto del grafo, no del modelo.
    conversation_id = config["configurable"]["conversation_id"]

    r = httpx.post(
        f"{CRM_BASE}/api/v1/inmobiliaria/conversations/{conversation_id}/media",
        json={"codigo": codigo, "tipo": tipo, "cantidad": min(cantidad, 8)},
        headers={"Authorization": f"Bearer {CRM_KEY}", "Accept": "application/json"},
        timeout=20,
    )

    if r.status_code == 404:
        # Mensaje accionable para el modelo: que se lo diga al cliente en vez
        # de reintentar o inventar.
        raise MediaNoDisponible(r.json().get("message", "Sin material cargado."))

    if r.status_code == 409:
        return "La conversación ya fue derivada a un asesor. No respondas más."

    r.raise_for_status()
    return f"Enviados {r.json()['enviados']} archivos del inmueble {codigo}."
```

Si tu grafo ya inyecta los ids de otra forma (un `contextvar`, un wrapper que cierra sobre el
`conversation_id` al construir el toolset), usá esa — lo único que importa es que **el
`conversation_id` no sea un parámetro que el modelo complete**.

---

## 4. Dónde encaja en la conversación

Según el flujo de C21, el momento es **justo después de presentar la ficha**, antes de preguntar por
la visita:

```
buscar_inmuebles         → 2-3 opciones
get_inmueble(codigo)     → ficha completa + tiene_fotos
enviar_media(codigo)     → ← acá, si tiene_fotos es true
"¿te gustaría agendar una visita?"
registrar_solicitud      → con visita_preferencia
derivar_a_asesor         → avisar al cliente PRIMERO
```

Los guiones de C21 no contemplan mandar fotos. Vale la pena agregarlo: es la diferencia entre
describir un galpón y mostrarlo.

---

## 5. Probarlo ahora mismo

```bash
export CRM_KEY='<token>'

# ¿tiene fotos?
curl -s -H "Authorization: Bearer $CRM_KEY" -H "Accept: application/json" \
  https://c21.sofopolis.com/api/v1/inmobiliaria/inmuebles/C21-0001

# mandarlas a una conversación real
curl -s -X POST \
  -H "Authorization: Bearer $CRM_KEY" -H "Content-Type: application/json" \
  -d '{"codigo":"C21-0001","tipo":"foto","cantidad":4}' \
  https://c21.sofopolis.com/api/v1/inmobiliaria/conversations/<ID>/media
```

⚠️ El segundo comando **le manda fotos de verdad a un cliente real**. Usá una conversación de prueba.

---

## 6. Estado verificado hoy

- `C21-0001` (galpón): **11 fotos**, las 11 con `media_id` vigente en Meta hasta el 26/09.
- `110748` (casa Hamacas): **0 fotos**. `enviar_media` devuelve 404 hasta que el asesor las cargue.
- Envío probado punta a punta: `enviados: 4`, los cuatro mensajes por `media_id`, con caption solo
  en el primero.
