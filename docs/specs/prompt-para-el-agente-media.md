Contexto: sos el ingeniero del agente de IA "Sofía" (FastAPI + LangGraph) de CENTURY 21 BLU.
El equipo del CRM (Krayin, https://c21.sofopolis.com) terminó el soporte de envío de media y
necesitamos cablearlo del lado del agente. El endpoint YA EXISTE, está probado punta a punta contra
la instancia real y no hay que pedirle nada más al CRM.

TAREA
Agregar al grafo la tool `enviar_media`, para que Sofía pueda mostrar las fotos de un inmueble
además de describirlo.

ENDPOINT

  POST {CRM_BASE_URL}/api/v1/inmobiliaria/conversations/{conversation_id}/media
  Authorization: Bearer {CRM_API_KEY}
  Content-Type: application/json

  { "codigo": "C21-0001", "tipo": "foto", "cantidad": 4 }

  200 → { "message": "Enviado.", "enviados": 4, "codigo": "C21-0001" }

  - tipo: "foto" | "plano" | "video" | "tour"
  - cantidad: opcional, por defecto 4, TOPE DURO 8 (pedir 50 devuelve 8)
  - El CRM decide QUÉ archivos manda y en qué orden: portada primero, después el orden que fijó el
    asesor. El agente nunca ve ni maneja URLs de imágenes.
  - Las fotos van SIN caption, ninguna. La descripción la manda el agente como texto aparte,
    después de las fotos.

CÓDIGOS DE ERROR — respetalos, son los mismos que ya usa el resto del contrato

  404  el código no existe, O el inmueble no tiene ese tipo de archivo cargado → decírselo al
       cliente, NO reintentar
  409  la conversación fue derivada a un asesor → callarse, la atiende un humano
  422  fuera de la ventana de 24 h → descartar, NO reintentar
  501  el canal no soporta media → no pasa con Cloud API, NO reintentar
  5xx  transitorio → reintentar con backoff

LA TOOL

  El conversation_id se inyecta server-side y el modelo NUNCA lo ve, igual que el resto de los ids
  internos. Lo único que el LLM decide es el `codigo` —que es público, el cliente lo vio en el
  anuncio— y cuántas fotos mandar.

  import os, httpx
  from langchain_core.tools import tool
  from langchain_core.runnables import RunnableConfig

  CRM_BASE = os.environ["CRM_BASE_URL"]
  CRM_KEY  = os.environ["CRM_API_KEY"]

  class MediaNoDisponible(Exception):
      """El inmueble no tiene ese tipo de archivo cargado."""

  @tool
  def enviar_media(codigo: str, tipo: str = "foto", cantidad: int = 4,
                   *, config: RunnableConfig) -> str:
      """Envía al cliente fotos, planos, video o el tour virtual de un inmueble.

      Usar SOLO después de haber confirmado con get_inmueble que el inmueble tiene
      ese material (campos tiene_fotos / tiene_plano / tiene_tour).

      Args:
          codigo: código público del inmueble, tal como aparece en el anuncio.
          tipo: "foto", "plano", "video" o "tour".
          cantidad: cuántos archivos enviar. Máximo 8.
      """
      conversation_id = config["configurable"]["conversation_id"]

      r = httpx.post(
          f"{CRM_BASE}/api/v1/inmobiliaria/conversations/{conversation_id}/media",
          json={"codigo": codigo, "tipo": tipo, "cantidad": min(cantidad, 8)},
          headers={"Authorization": f"Bearer {CRM_KEY}", "Accept": "application/json"},
          timeout=20,
      )

      if r.status_code == 404:
          raise MediaNoDisponible(r.json().get("message", "Sin material cargado."))
      if r.status_code == 409:
          return "La conversación ya fue derivada a un asesor. No respondas más."

      r.raise_for_status()
      return f"Enviados {r.json()['enviados']} archivos del inmueble {codigo}."

  Si tu grafo ya inyecta los ids de otra forma (contextvar, o un wrapper que cierra sobre el
  conversation_id al construir el toolset), usá esa. Lo único innegociable es que el
  conversation_id NO sea un parámetro que el modelo complete.

AJUSTE AL PROMPT DE SOFÍA — esto es lo más importante

  1. CONSULTAR ANTES DE PROMETER. get_inmueble devuelve tiene_fotos, tiene_plano y tiene_tour.
     Sofía debe mirarlos antes de ofrecer. Si dice "te mando las fotos" y después recibe un 404,
     ya prometió algo que no puede cumplir, y corregirse en el mensaje siguiente queda pésimo.
     Hoy mismo los dos casos aparecen en la misma demo: el galpón C21-0001 tiene 11 fotos y la casa
     110748 tiene 0.

  2. CUÁNDO MANDARLAS. Justo después de presentar la ficha, antes de preguntar por la visita:

       buscar_inmuebles       → 2-3 opciones
       get_inmueble(codigo)   → ficha completa + tiene_fotos
       enviar_media(codigo)   ← acá, solo si tiene_fotos es true
       "¿te gustaría agendar una visita?"
       registrar_solicitud    → con visita_preferencia
       derivar_a_asesor       → avisar al cliente PRIMERO, después llamar

  3. NO PEDIR MÁS DE 8. El tope es duro y silencioso: pedir 20 devuelve 8, no un error. Para una
     primera muestra, 3 o 4 fotos alcanzan; el cliente que quiere ver más lo pide.

  4. LOS GUIONES DE C21 NO CONTEMPLAN MANDAR FOTOS. Hay que agregarlo: es la diferencia entre
     describir un galpón y mostrarlo.

CÓMO VERIFICARLO

  export CRM_KEY='<el token que les pasamos>'

  # ¿tiene fotos?
  curl -s -H "Authorization: Bearer $CRM_KEY" -H "Accept: application/json" \
    https://c21.sofopolis.com/api/v1/inmobiliaria/inmuebles/C21-0001

  # mandarlas — OJO: esto le manda fotos de verdad a un cliente real, usá una conversación de prueba
  curl -s -X POST -H "Authorization: Bearer $CRM_KEY" -H "Content-Type: application/json" \
    -d '{"codigo":"C21-0001","tipo":"foto","cantidad":4}' \
    https://c21.sofopolis.com/api/v1/inmobiliaria/conversations/<ID>/media

ESTADO VERIFICADO POR EL EQUIPO DEL CRM

  - C21-0001 (galpón): 11 fotos, las 11 con media_id vigente en Meta hasta el 26/09.
  - 110748 (casa Hamacas): 0 fotos → enviar_media devuelve 404 hasta que el asesor las cargue.
  - Envío probado punta a punta: enviados=4, los cuatro por media_id, caption solo en el primero.

RECORDATORIO PENDIENTE
  Sigue sin confirmarse de su lado que aceptan conversation_id en vez de wa_id como clave de todas
  las tools. Ya está construido así. Si lo rechazan, díganlo ahora.
