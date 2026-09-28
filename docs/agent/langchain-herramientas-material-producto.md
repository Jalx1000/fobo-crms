# Herramientas del agente (LangChain / LangGraph) — material de productos y opciones tocables

**Para:** quien mantiene el agente Python (`langgraph-fastapi-template`).
**Estado del CRM:** ✅ endpoints implementados, testeados y desplegados.
**Falta:** las cuatro herramientas del lado del agente.

**Implementación de referencia:** [`langchain/crm_productos.py`](langchain/crm_productos.py) —
copiable, adaptá settings y logging a las convenciones del proyecto.

---

## 0. El problema

El agente hoy **solo puede mandar texto**
(`POST /api/v1/whatsapp/conversations/{id}/messages`). Cuando el cliente pide
"mandame fotos de la bolsa" o "pasame la ficha técnica", describe con palabras
y el cliente se queda sin ver nada.

La idea que gobierna el diseño de la API:

> **El agente pide "3 imágenes del SKU X". El CRM decide cuáles, en qué orden y
> por qué vía. Las rutas de los archivos nunca pasan por el modelo.**

El modelo solo manipula el **SKU** —público, ya aparece en las cotizaciones— y
ve **cuántos** adjuntos hay de cada tipo. Nunca un path, ni una URL de storage,
ni un id de archivo.

---

## 1. Autenticación

El **mismo Bearer token** que el agente ya usa para el endpoint de mensajes. No
hay credencial nueva. Es un token de Sanctum del usuario `Agente`
(`imprimir@sofopolis.com`); si se perdió el texto plano hay que regenerarlo,
porque en la base está hasheado.

```bash
CRM_BASE_URL=https://imprimir.sofopolis.com
CRM_TOKEN=<el mismo de siempre>
```

---

## 2. Los endpoints

Base: `https://imprimir.sofopolis.com/api/v1/productos`

### 2.1 `GET /catalogo?q=<texto>&limit=<1..50>`

**Respuesta real** (capturada de la API, no inventada):

```json
{
  "data": [
    {
      "sku": "CM_00015",
      "nombre": "Polipasacalle Publicitario",
      "precio": "1.0000",
      "adjuntos": { "imagen": 3, "documento": 0, "enlace": 0 }
    }
  ]
}
```

- **Solo devuelve productos que tienen adjuntos.** Si no está en la lista, no
  hay material y no hay que prometerlo.
- `adjuntos` son **conteos**, no archivos.
- `precio` es **string** con 4 decimales. Formatearlo antes de decirlo.
- `data` puede ser `[]`: no es un error, es "no encontré nada".

### 2.2 `GET /catalogo/{sku}`

```json
{
  "sku": "CM_00015",
  "nombre": "Polipasacalle Publicitario",
  "precio": "1.0000",
  "precio_variable": false,
  "ejes_precio": [],
  "adjuntos": { "imagen": 3, "documento": 0, "enlace": 0 },
  "descripcion": "Película plástica de uso promocional…",
  "caracteristicas": {},
  "precios": []
}
```

- `caracteristicas` es **siempre un objeto**, nunca lista. Vacío es `{}`. Las
  claves ya vienen legibles: `{"Gramaje": "300 g/m²"}`.
- `descripcion` puede ser `null`.
- `404` si el SKU no existe, con el mensaje ya redactado.

### 2.2.b Productos sin precio fijo

Bolsas Magia Verde, Hules y Tapas **no tienen un precio**: tienen uno por
variante. En esos productos `precio` viene en **`null`** y `precio_variable` en
`true`:

```json
{
  "sku": "CM_00002",
  "nombre": "Bolsas Magia Verde",
  "precio": null,
  "precio_variable": true,
  "ejes_precio": ["Tamaño", "Canal"],
  "precios": [
    {
      "valores": { "Tamaño": "50 L", "Canal": "HORECA" },
      "precio": "180.0000", "unidad": "por bulto", "minimo": null,
      "nota": null, "cotiza": true
    },
    {
      "valores": { "Tamaño": "50 L", "Canal": "DISTRIBUIDOR" },
      "precio": null, "unidad": null, "minimo": null,
      "nota": "Derivar al equipo comercial.", "cotiza": false
    }
  ]
}
```

`precio` es `null` **a propósito**: la columna de precio fijo de esos productos
vale 0, y devolverla haría que el agente ofrezca bolsas a 0 Bs.

Una fila con `cotiza: false` **no es un dato faltante**: es una combinación que
existe y que la empresa decidió no cotizar por chat («Para mi casa» va al
supermercado, «Quiero revender» va al equipo comercial). La `nota` dice qué
hacer. No la escondas ni la reemplaces por otra fila.

### 2.2.c `POST /catalogo/{sku}/precio`

El endpoint que resuelve cuánto se le cobra al cliente. **Es POST y no GET**:
los ejes llevan acentos (`Tamaño`) y los valores llevan espacios, y en query
string cada cliente los escapa distinto — una diferencia de encoding se vería
como «no hay precio» en vez de como un error.

```json
{ "criterios": { "Tamaño": "50 L", "Canal": "HORECA" } }
```

Los nombres de eje y los valores se comparan **sin acentos, sin mayúsculas y sin
espacios**: `{"tamano": "50l"}` encuentra la misma fila que `{"Tamaño": "50 L"}`.
Lo que **no** se tolera es un sinónimo (`"50 litros"` no coincide): aceptarlo de
más haría cotizar una combinación que el cliente no pidió.

Un criterio que no es un eje del producto (`"Ciudad"`) se **ignora**, no vacía el
resultado: mandá lo que hayas entendido de la charla sin miedo.

Tres respuestas posibles, todas `200`:

**`resuelto`** — hay una sola fila. Podés dar el precio.

```json
{
  "estado": "resuelto", "sku": "CM_00002", "nombre": "Bolsas Magia Verde",
  "precio": {
    "valores": { "Tamaño": "50 L", "Canal": "HORECA" },
    "precio": "180.0000", "unidad": "por bulto", "minimo": null,
    "nota": null, "cotiza": true
  }
}
```

**`ambiguo`** — faltan ejes. **Preguntá y volvé a llamar.** `faltan` trae solo
los ejes que de verdad cambian el precio entre las candidatas, y `opciones` los
valores que quedan vivos: preguntar un eje que no discrimina le hace contestar al
cliente algo que no sirve.

```json
{
  "estado": "ambiguo", "faltan": ["Canal"],
  "opciones": { "Canal": ["TRADICIONAL", "HORECA", "EMPRESARIAL"] },
  "candidatas": [ … ]
}
```

**`sin_datos`** — la combinación no está cargada. **No acerques la fila más
parecida**: derivá.

Un producto de precio fijo también responde `resuelto`, con su precio de tabla —
así podés llamar a esta herramienta siempre, sin decidir antes de qué tipo es.

### 2.3 `POST /conversations/{conversation_id}/media`

```json
{ "sku": "CM_00015", "tipo": "imagen", "cantidad": 3 }
```

| Campo | Obligatorio | Valores |
|---|---|---|
| `sku` | sí | El del catálogo |
| `tipo` | sí | `imagen` · `documento` · `enlace`. Otro → 422 |
| `cantidad` | no | Default **3**, techo duro **10** (lo aplica el CRM) |

**200:** `{ "message": "Enviado.", "enviados": 3, "sku": "CM_00015" }`

El envío es **asíncrono**: el 200 significa "encolado y en orden", no "ya lo vio
el cliente". Los mensajes se encadenan para que lleguen en el orden de la
galería, con la portada primero.

### 2.4 `POST /api/v1/whatsapp/conversations/{id}/interactive`

⚠️ **Prefijo distinto: `/whatsapp`, no `/productos`.** Botones y listas son una
capacidad genérica del canal, no del catálogo — la misma separación que el CRM
mantiene del lado del servidor.

**Botones** (hasta 3 opciones):

```json
{
  "format": "button",
  "body": "¿Qué querés ver del Polipasacalle?",
  "header": "CM_00015",
  "footer": "Imprimir",
  "buttons": [
    { "id": "fotos:CM_00015",  "title": "Ver fotos" },
    { "id": "precio:CM_00015", "title": "Precio" }
  ]
}
```

**Lista** (4 a 10 opciones):

```json
{
  "format": "list",
  "body": "Elegí el producto que te interesa",
  "list_button": "Ver catálogo",
  "sections": [
    {
      "title": "Publicidad",
      "rows": [
        { "id": "CM_00015", "title": "Polipasacalle", "description": "Material POP para exteriores" },
        { "id": "CM_00013", "title": "Bolsa camiseta" }
      ]
    }
  ]
}
```

**Límites de Meta** — los valida el CRM antes de encolar y devuelve **422 con el
campo nombrado**, no el 400 genérico de Meta:

| | Límite |
|---|---|
| Botones | máximo **3**; título ≤ 20 |
| Lista | máximo **10 filas en total** entre todas las secciones; ≤ 10 secciones |
| Título de fila | ≤ 24 · descripción ≤ 72 · título de sección ≤ 24 |
| Cuerpo | ≤ 1024 · encabezado ≤ 60 · pie ≤ 60 · botón de lista ≤ 20 |
| ids | únicos dentro del mensaje; ≤ 256 (botón) / ≤ 200 (fila) |

**501** si el canal activo no tiene botones (Kommo, Messenger). No es
reintentable: hay que mandar las opciones como texto numerado.

#### La respuesta del cliente

Cuando toca una opción, llega como un mensaje entrante normal en el webhook:

```json
{
  "message": {
    "type": "interactive",
    "text": "Ver fotos",
    "selection": { "id": "fotos:CM_00015", "title": "Ver fotos" }
  }
}
```

> **Ruteá por `selection.id`, no por `text`.** Los títulos se recortan a 20/24
> caracteres y pueden repetirse entre menús distintos; el id lo elegís vos y es
> único. Poné algo informativo: `"fotos:CM_00015"` en vez de `"opcion_1"`.

`selection` solo está presente cuando el mensaje vino de un botón o una lista.

---

## 3. Errores — todos definitivos, ninguno se reintenta

| Código | Cuerpo | Qué hace el agente |
|---|---|---|
| **404** | `El producto CM_00015 no tiene imágenes cargadas en el catálogo.` | Decírselo al cliente, ofrecer otra cosa |
| **404** | `No existe un producto con el SKU X.` | Volver a buscar en el catálogo |
| **409** | `Esta conversación fue derivada a un asesor…` | **Dejar de responder por completo** |
| **422** | `Fuera de la ventana de 24h de WhatsApp…` | No reintentar |
| **422** | `Un archivo supera el límite de WhatsApp para image (5 MB).` | Avisar y seguir |
| **401** | `Unauthenticated.` | Falla de config, no del agente |

**El 404 de "no tiene imágenes" es deliberado y NO es un `200` con
`enviados: 0`.** Si el agente ya dijo "te mando las fotos", tiene que enterarse
en el mismo turno para corregirse.

> ⚠️ **Devolver los errores como texto, no como excepción.** Una excepción corta
> el turno y el cliente se queda sin respuesta, cuando lo que hay que hacer es
> contarle lo que pasó. La implementación de referencia hace eso: todas las
> ramas de error terminan en un `return` de string.

---

## 4. `conversation_id` NO es un argumento de la herramienta

Es la decisión de seguridad más importante de la integración.

Si `conversation_id` se expone en el schema, el modelo va a inventar un número y
**el material le va a llegar a otro cliente**. Tiene que viajar por el
`RunnableConfig`, que LangChain inyecta y queda fuera del schema que ve el LLM:

```python
@tool
async def enviar_material(sku: str, tipo: str, cantidad: int = 3, *, config: RunnableConfig) -> str:
    conversation_id = (config.get("configurable") or {}).get("conversation_id")
```

y al invocar el grafo:

```python
await agent.ainvoke(
    {"messages": [HumanMessage(content=texto_del_cliente)]},
    config={"configurable": {"conversation_id": 123, "thread_id": f"wa-{123}"}},
)
```

> **Verificá esto primero de todo.** El único punto que no pude comprobar contra
> tu proyecto es que tu versión de `langchain-core` inyecte `RunnableConfig` en
> un parámetro *keyword-only*. Es el comportamiento documentado, pero si el
> schema que ve el modelo llegara a incluir `config`, las alternativas son
> `Annotated[int, InjectedToolArg]` o leerlo de un `contextvar` seteado en el
> handler del webhook. **Antes de conectar nada:**
>
> ```python
> print(enviar_material.args_schema.model_json_schema()["properties"].keys())
> # tiene que dar exactamente: sku, tipo, cantidad
> ```

---

## 5. Wiring

Las tres tools son objetos `BaseTool` estándar, así que sirven igual con
cualquiera de las dos formas que tenés disponibles en el lock:

```python
from crm_productos import HERRAMIENTAS_CRM

# langchain 1.0.5
from langchain.agents import create_agent
agent = create_agent(model, tools=[*HERRAMIENTAS_CRM, *tus_tools], prompt=SYSTEM)

# langgraph-prebuilt 1.0.2
from langgraph.prebuilt import create_react_agent
agent = create_react_agent(model, tools=[*HERRAMIENTAS_CRM, *tus_tools], prompt=SYSTEM)
```

**Sobre `httpx`:** la referencia abre un `AsyncClient` por llamada para ser
copiable sin arrastrar dependencias. Si el proyecto ya tiene uno en el lifespan
de FastAPI, usá ese — abrir uno por tool-call desperdicia el pool.

**Sobre `tenacity`:** **no** envuelvas estas llamadas en reintentos. Ningún error
de la tabla del §3 mejora reintentando, y un reintento sobre el POST puede
duplicar el envío al cliente. Si querés reintentar algo, que sea solo el `GET`
del catálogo ante 5xx.

---

## 6. Reglas para el system prompt

```
MATERIAL DE PRODUCTOS

Tenés cinco herramientas: buscar_productos, ficha_producto, precio_producto,
enviar_material y mostrar_opciones.

0. NUNCA digas un precio que no te dio precio_producto.
   No calcules, no estimes, no redondeés, no multipliques por cantidad y no
   repitas un precio de una conversación anterior. Hay productos —bolsas, hules,
   tapas— cuyo precio depende de lo que el cliente elija, y buscar_productos te
   avisa cuáles diciendo "precio según Tamaño, Canal".

   Si precio_producto te contesta que faltan datos, PREGUNTÁ EXACTAMENTE LO QUE
   TE DICE y volvé a llamarla. No elijas vos la variante por el cliente: cotizar
   HORECA cuando el cliente tenía una tienda es un precio equivocado dicho con
   total seguridad, que es el peor error que podés cometer acá.

   Si te contesta que esa combinación no se cotiza por chat, hacé lo que dice la
   nota (derivar al supermercado, derivar al equipo comercial). No busques un
   precio parecido para no dejarlo sin respuesta.

1. NUNCA prometas material sin verificar que existe.
   Antes de decir "te mando las fotos", llamá a buscar_productos y mirá el
   conteo. Si imágenes es 0, no hay fotos: ofrecé el documento si lo hay, o
   describí el producto con palabras.

2. PRIMERO EL MATERIAL, DESPUÉS LA DESCRIPCIÓN.
   enviar_material manda los archivos SIN pie de foto, a propósito. Vos mandás
   tu descripción como un mensaje de texto aparte, DESPUÉS de que salieron las
   imágenes. Si la mandás antes, el cliente lee la descripción de algo que
   todavía no vio.

3. TRES, NO DIEZ.
   El default de cantidad es 3 y está elegido: una tanda larga satura el chat y
   el cliente deja de mirar. Pedí más solo si el cliente lo pidió explícitamente.

4. UN PRODUCTO POR VEZ.
   Si el cliente pregunta por tres productos, mandá el material de uno, esperá
   que responda, y recién después seguí. Tres tandas seguidas es spam.

5. LOS DOCUMENTOS SOLO SI LOS PIDEN.
   Fichas técnicas y especificaciones se mandan cuando el cliente pide leer algo.
   No los mandes "de yapa" junto con las fotos.

6. SI enviar_material DEVUELVE UN TEXTO QUE EMPIEZA CON "STOP:", TERMINÁ.
   Significa que un asesor humano tomó la conversación. No mandes nada más, ni
   siquiera una despedida.

7. SI DEVUELVE QUE NO HAY MATERIAL, DECILO.
   No lo reintentes ni cambies de tipo por tu cuenta. Contale al cliente que no
   tenés fotos de ese producto y ofrecé lo que sí tenés.

OPCIONES TOCABLES (mostrar_opciones)

8. USALAS CUANDO HAY QUE ELEGIR ENTRE COSAS CONCRETAS.
   Qué material quiere ver, cuál de varios productos, confirmar sí o no. Le
   evita al cliente tipear un SKU, que es donde más se equivocan. No las uses
   para preguntas abiertas ("¿en qué te ayudo?"): ahí el cliente tiene que poder
   escribir lo que quiera.

9. IDS INFORMATIVOS, NO "opcion_1".
   El id es lo único que te vuelve. Poné "fotos:CM_00015", no "1": cuando llegue
   la respuesta vas a saber exactamente qué pidió sin tener que recordar qué
   menú mandaste.

10. UNA VEZ Y ESPERÁ.
    Después de mandar opciones, no sigas hablando. El cliente está viendo un
    menú; un mensaje más lo tapa. Esperá su elección.

11. NO REPITAS LAS OPCIONES EN TEXTO.
    Si mandaste botones, no las enumeres también escribiéndolas. El cliente ve
    las dos cosas y no sabe cuál usar.

12. SI DEVUELVE QUE EL CANAL NO ADMITE BOTONES, ENUMERALAS.
    Mandá las opciones como texto numerado y pedile que responda con el número.
```

---

## 7. Probar la API antes de conectar el agente

```bash
TOKEN='...'
BASE='https://imprimir.sofopolis.com/api/v1/productos'

# 1) ¿Qué hay?
curl -sS -G "$BASE/catalogo" --data-urlencode 'q=poli' \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' | jq

# 2) La ficha
curl -sS "$BASE/catalogo/CM_00015" \
  -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' | jq

# 3) Mandar 2 imágenes  (cambiá 123 por un conversation_id real, con la IA
#    encendida y el cliente habiendo escrito hace menos de 24 h)
curl -sS -X POST "$BASE/conversations/123/media" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"sku":"CM_00015","tipo":"imagen","cantidad":2}' | jq

# 4) El precio de una variante
curl -sS -X POST "$BASE/catalogo/CM_00002/precio" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"criterios":{"Tamaño":"50 L","Canal":"HORECA"}}' | jq

# 5) El mismo pedido con datos incompletos: mirá `faltan` y `opciones`
curl -sS -X POST "$BASE/catalogo/CM_00002/precio" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"criterios":{"Tamaño":"50 L"}}' | jq

# 6) El error que más va a ocurrir: pedir un tipo que no existe
curl -sS -X POST "$BASE/conversations/123/media" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"sku":"CM_00015","tipo":"documento"}' -w '\nHTTP %{http_code}\n' | jq
```

El paso 4 tiene que devolver **404** con
`"El producto CM_00015 no tiene documentos cargados en el catálogo."` — ese
producto tiene 3 imágenes y ningún documento. **Verificado contra la API real.**

> Si el 200 llega pero el mensaje no aparece en WhatsApp, el problema está en el
> worker de la cola, no en el endpoint. Los mensajes quedan en
> `whatsapp_messages` con `status = queued`.

---

## 8. Qué necesito de vuelta

1. Confirmación de que el `args_schema` de `enviar_material` **no** expone
   `config` ni `conversation_id` (§4).
2. Si alguna regla del §6 choca con el flujo actual — sobre todo
   **"material primero, descripción después"**, que cambia el orden de los
   mensajes que el agente venía mandando.
3. Qué hace hoy el agente cuando el cliente pide fotos, para comparar el antes y
   el después.
4. Que el ruteo de la respuesta use `message.selection.id` y no el texto — es el
   punto donde un menú "funciona" en la demo y falla en producción, cuando dos
   opciones de menús distintos comparten título.
