"""
Herramientas del agente para enviar material de productos por WhatsApp.

Implementación de referencia contra la API del CRM de imprimir. Está pensada
para copiarse al proyecto del agente (LangChain 1.0 / LangGraph) y adaptarse a
sus convenciones de settings y logging.

──────────────────────────────────────────────────────────────────────────────
La idea que gobierna el diseño de la API, y que conviene no perder al integrar:

    El agente pide "3 imágenes del SKU X". El CRM decide cuáles, en qué orden y
    por qué vía. Las rutas de los archivos NUNCA pasan por el modelo.

El modelo solo manipula el `sku` —que es público, aparece en las cotizaciones—
y ve *cuántos* adjuntos hay de cada tipo. Nunca un path, ni una URL de storage,
ni un id de archivo.
──────────────────────────────────────────────────────────────────────────────
"""

from __future__ import annotations

import httpx
import structlog
from langchain_core.runnables import RunnableConfig
from langchain_core.tools import tool
from pydantic_settings import BaseSettings, SettingsConfigDict

log = structlog.get_logger(__name__)


class CrmSettings(BaseSettings):
    """
    El token es el MISMO que el agente ya usa para
    /api/v1/whatsapp/conversations/{id}/messages. No hay credencial nueva.
    """

    model_config = SettingsConfigDict(env_prefix="CRM_", extra="ignore")

    base_url: str = "https://imprimir.sofopolis.com"
    token: str = ""
    timeout: float = 20.0


settings = CrmSettings()


def _client() -> httpx.AsyncClient:
    """
    Un cliente por llamada.

    Si el proyecto ya tiene un AsyncClient compartido en el lifespan de FastAPI,
    usar ese: abrir uno por tool-call desperdicia el pool de conexiones. Se deja
    así para que el módulo sea copiable sin arrastrar dependencias.
    """
    return httpx.AsyncClient(
        base_url=f"{settings.base_url}/api/v1/productos",
        headers={
            "Authorization": f"Bearer {settings.token}",
            "Accept": "application/json",
        },
        timeout=settings.timeout,
    )


def _client_whatsapp() -> httpx.AsyncClient:
    """
    Los botones y listas NO son del módulo de productos: son una capacidad
    genérica de WhatsApp, y viven en `/api/v1/whatsapp`. Por eso el prefijo
    distinto — es la misma separación que mantiene el CRM del lado del servidor.
    """
    return httpx.AsyncClient(
        base_url=f"{settings.base_url}/api/v1/whatsapp",
        headers={
            "Authorization": f"Bearer {settings.token}",
            "Accept": "application/json",
        },
        timeout=settings.timeout,
    )


def _conversation_id(config: RunnableConfig) -> int | None:
    """
    El id de conversación sale del config, NO del modelo.

    Es la decisión de seguridad más importante de este módulo: si se expone como
    argumento de la herramienta, el modelo va a inventar un número y el material
    le va a llegar a otro cliente. `RunnableConfig` lo inyecta LangChain y queda
    fuera del schema que ve el LLM.

    Quien construye el grafo lo pasa así:

        agent.ainvoke(
            {"messages": [...]},
            config={"configurable": {"conversation_id": 123, "thread_id": "..."}},
        )
    """
    return (config.get("configurable") or {}).get("conversation_id")


@tool
async def buscar_productos(busqueda: str) -> str:
    """Busca productos del catálogo que tengan material para enviar.

    Devuelve el SKU, nombre, precio y cuántos adjuntos de cada tipo (imagen,
    documento, enlace) tiene cada producto. Solo aparecen productos que SÍ
    tienen material: si un producto no está en la lista, no hay nada que
    mandar. Usala ANTES de ofrecerle fotos o fichas al cliente.

    Args:
        busqueda: Texto a buscar en el nombre o el SKU del producto.
    """
    async with _client() as http:
        r = await http.get("/catalogo", params={"q": busqueda, "limit": 10})

    if r.status_code != 200:
        return _error(r, "No se pudo consultar el catálogo.")

    productos = r.json().get("data", [])

    if not productos:
        return f"No hay productos con material que coincidan con «{busqueda}»."

    return "\n".join(_linea_catalogo(p) for p in productos)


def _precio_texto(p: dict) -> str:
    """Cómo se nombra el precio de un producto en una línea de catálogo.

    Un producto de precio variable NO tiene precio hasta que el cliente elige.
    Se dice explícitamente por qué ejes varía, para que el modelo pregunte eso y
    no invente un número: es el error más caro que puede cometer el agente.
    """
    if p.get("precio_variable"):
        ejes = ", ".join(p.get("ejes_precio") or []) or "la variante"

        return f"precio según {ejes} — usá precio_producto"

    return f"Bs {p['precio']}"


def _linea_catalogo(p: dict) -> str:
    return "{sku} — {nombre} ({precio}) · imágenes: {imagen}, documentos: {documento}, enlaces: {enlace}".format(
        sku=p["sku"],
        nombre=p["nombre"],
        precio=_precio_texto(p),
        **p["adjuntos"],
    )


@tool
async def ficha_producto(sku: str) -> str:
    """Devuelve la descripción y las características técnicas de un producto.

    Usala para describirle el producto al cliente con datos reales del catálogo
    en vez de improvisar.

    Args:
        sku: SKU exacto del producto, tal como lo devolvió buscar_productos.
    """
    async with _client() as http:
        r = await http.get(f"/catalogo/{sku}")

    if r.status_code == 404:
        return r.json().get("message", f"No existe un producto con el SKU {sku}.")

    if r.status_code != 200:
        return _error(r, "No se pudo consultar la ficha.")

    d = r.json()

    lineas = [f"{d['sku']} — {d['nombre']}", f"Precio: {_precio_texto(d)}"]

    # La lista completa entra en la ficha para que el modelo pueda comparar
    # («la de 50 sale un poco más»), pero el precio que se le DICE al cliente
    # sale siempre de precio_producto: es el CRM el que resuelve la fila.
    for fila in d.get("precios") or []:
        combo = " · ".join(str(v) for v in (fila.get("valores") or {}).values())
        monto = f"Bs {fila['precio']}" if fila.get("cotiza") else "sin precio por chat"
        extra = " · ".join(
            x for x in (fila.get("unidad"), fila.get("minimo") and f"mín. {fila['minimo']}", fila.get("nota")) if x
        )

        lineas.append(f"  {combo}: {monto}" + (f" ({extra})" if extra else ""))

    if d.get("descripcion"):
        lineas.append(d["descripcion"])

    # `caracteristicas` es SIEMPRE un objeto, nunca una lista. Vacío es {}.
    for etiqueta, valor in (d.get("caracteristicas") or {}).items():
        lineas.append(f"{etiqueta}: {valor}")

    adj = d["adjuntos"]

    lineas.append(
        f"Material disponible — imágenes: {adj['imagen']}, "
        f"documentos: {adj['documento']}, enlaces: {adj['enlace']}"
    )

    return "\n".join(lineas)


@tool
async def precio_producto(sku: str, criterios: dict[str, str] | None = None) -> str:
    """Da el precio de un producto según lo que el cliente eligió.

    Usala SIEMPRE antes de decir un precio de un producto que se cotiza por
    variante (buscar_productos te avisa cuáles). Nunca calcules, estimes ni
    redondees un precio vos: el CRM es el que sabe cuánto se cobra.

    Pasá en `criterios` lo que el cliente ya te dijo. Si falta algo, la
    herramienta te devuelve QUÉ preguntar y con qué opciones — preguntá eso y
    volvé a llamarla.

    Args:
        sku: SKU exacto del producto, tal como lo devolvió buscar_productos.
        criterios: Lo que el cliente eligió, por eje. Ej.:
            {"Tamaño": "50 L", "Canal": "HORECA"}
    """
    async with _client() as http:
        r = await http.post(f"/catalogo/{sku}/precio", json={"criterios": criterios or {}})

    if r.status_code == 404:
        return r.json().get("message", f"No existe un producto con el SKU {sku}.")

    if r.status_code != 200:
        return _error(r, "No se pudo consultar el precio.")

    d = r.json()

    if d["estado"] == "ambiguo":
        faltan = ", ".join(d.get("faltan") or []) or "más datos"
        opciones = "\n".join(
            f"  {eje}: " + ", ".join(str(v) for v in valores)
            for eje, valores in (d.get("opciones") or {}).items()
        )

        return (
            f"Todavía no puedo dar un precio de {d['nombre']}: falta saber {faltan}.\n"
            f"Preguntale al cliente y volvé a llamarme.\n{opciones}"
        )

    if d["estado"] == "sin_datos":
        return d.get("message", "Esa combinación no tiene precio cargado. Derivá a un asesor.")

    fila = d["precio"]
    combo = " · ".join(str(v) for v in (fila.get("valores") or {}).values())

    if not fila.get("cotiza", fila.get("precio") is not None):
        # La combinación existe pero a propósito no se cotiza por chat. La nota
        # dice qué corresponde hacer; no ofrezcas otro precio en su lugar.
        return f"{d['nombre']}{f' ({combo})' if combo else ''}: {fila.get('nota') or 'no se cotiza por chat, derivá a un asesor.'}"

    partes = [f"{d['nombre']}{f' ({combo})' if combo else ''}: Bs {fila['precio']}"]

    if fila.get("unidad"):
        partes.append(fila["unidad"])

    if fila.get("minimo"):
        partes.append(f"pedido mínimo {fila['minimo']}")

    if fila.get("nota"):
        partes.append(fila["nota"])

    return " · ".join(partes)


@tool
async def enviar_material(
    sku: str,
    tipo: str,
    cantidad: int = 3,
    *,
    config: RunnableConfig,
) -> str:
    """Envía al cliente por WhatsApp el material de un producto.

    El CRM elige qué archivos y en qué orden (la portada primero). Los envía sin
    pie de foto a propósito: vos mandás tu descripción como un mensaje de texto
    aparte, DESPUÉS de que salió el material.

    Args:
        sku: SKU del producto cuyo material se envía.
        tipo: "imagen", "documento" o "enlace".
        cantidad: Cuántos enviar. 3 por defecto; el CRM no acepta más de 10.
    """
    conversation_id = _conversation_id(config)

    if not conversation_id:
        # Falla de wiring, no del modelo: sin conversación no hay a quién
        # mandarle nada. Se devuelve como texto para no cortar el turno.
        log.error("crm.enviar_material.sin_conversation_id", sku=sku)

        return "No hay una conversación activa para enviar material. Avisá al equipo técnico."

    async with _client() as http:
        r = await http.post(
            f"/conversations/{conversation_id}/media",
            json={"sku": sku, "tipo": tipo, "cantidad": cantidad},
        )

    if r.status_code == 200:
        d = r.json()

        log.info("crm.enviar_material.ok", sku=d["sku"], enviados=d["enviados"])

        return f"Enviados {d['enviados']} archivo(s) de {d['sku']}. Ahora mandá tu descripción como texto."

    """
    Ningún error de acá se reintenta: todos son definitivos para este turno.
    Se devuelven como TEXTO y no como excepción — una excepción corta el turno y
    el cliente se queda sin respuesta, cuando lo que hay que hacer es contarle
    lo que pasó.
    """
    mensaje = r.json().get("message", "") if _es_json(r) else ""

    if r.status_code == 409:
        log.info("crm.enviar_material.derivada", conversation_id=conversation_id)

        return "STOP: la conversación fue derivada a un asesor humano. No respondas nada más."

    if r.status_code == 404:
        return mensaje or f"El producto {sku} no tiene material de tipo {tipo}."

    if r.status_code == 422:
        return mensaje or "No se pudo enviar el material por una restricción de WhatsApp."

    return _error(r, "No se pudo enviar el material.")


def _es_json(r: httpx.Response) -> bool:
    return r.headers.get("content-type", "").startswith("application/json")


def _error(r: httpx.Response, fallback: str) -> str:
    """
    Los errores llegan al modelo como texto legible.

    401 y 5xx son fallas de infraestructura: el agente no puede hacer nada al
    respecto, pero sí puede decirle al cliente que hubo un problema en vez de
    quedarse mudo.
    """
    log.warning("crm.http_error", status=r.status_code, url=str(r.url))

    if r.status_code == 401:
        return "El CRM rechazó las credenciales. Avisá al equipo técnico."

    mensaje = r.json().get("message", "") if _es_json(r) else ""

    return mensaje or fallback


@tool
async def mostrar_opciones(
    cuerpo: str,
    opciones: list[dict],
    encabezado: str | None = None,
    pie: str | None = None,
    texto_boton_lista: str = "Ver opciones",
    *,
    config: RunnableConfig,
) -> str:
    """Muestra opciones tocables al cliente en vez de pedirle que escriba.

    Úsala cuando el cliente tiene que elegir entre alternativas concretas: qué
    material quiere ver, cuál de varios productos, confirmar sí o no. Le evita
    tipear un SKU o un número, que es donde más se equivocan.

    Cuando el cliente toca una opción, su respuesta llega como un mensaje normal
    con el título en el texto y el id en `selection.id`. Ruteá SIEMPRE por el id.

    Args:
        cuerpo: La pregunta o el texto que acompaña las opciones. Máximo 1024.
        opciones: Lista de dicts con `id` (lo que vuelve al elegir, elegilo vos
            y que sea informativo, ej. "fotos:CM_00015"), `titulo` (lo que el
            cliente ve, máximo 20 caracteres) y `descripcion` opcional (máximo
            72, solo se muestra si son más de 3 opciones).
        encabezado: Título breve arriba del cuerpo. Máximo 60.
        pie: Texto chico abajo. Máximo 60.
        texto_boton_lista: Solo se usa si hay más de 3 opciones. Máximo 20.
    """
    conversation_id = _conversation_id(config)

    if not conversation_id:
        log.error("crm.mostrar_opciones.sin_conversation_id")

        return "No hay una conversación activa. Avisá al equipo técnico."

    if not opciones:
        return "No se puede mostrar un menú sin opciones."

    """
    El formato lo elige el código, no el modelo.

    WhatsApp admite hasta 3 botones; a partir de ahí exige una lista. Dejar esa
    decisión en el prompt significa que el modelo la va a olvidar justo cuando
    tiene 4 productos para ofrecer, y el 422 llega después de que ya le dijo al
    cliente "elegí una opción". El techo duro son 10 opciones en total.
    """
    if len(opciones) > 10:
        return (
            f"Son {len(opciones)} opciones y WhatsApp admite 10 como máximo. "
            "Mostrá las más relevantes o preguntá algo que acote la búsqueda."
        )

    es_lista = len(opciones) > 3

    cuerpo_envio: dict = {
        "format": "list" if es_lista else "button",
        "body": cuerpo,
        "header": encabezado,
        "footer": pie,
    }

    if es_lista:
        cuerpo_envio["list_button"] = texto_boton_lista
        cuerpo_envio["sections"] = [
            {
                "rows": [
                    {
                        k: v
                        for k, v in (
                            ("id", str(o["id"])),
                            ("title", o["titulo"]),
                            ("description", o.get("descripcion")),
                        )
                        if v
                    }
                    for o in opciones
                ]
            }
        ]
    else:
        # Los botones no admiten descripción: WhatsApp solo muestra el título.
        cuerpo_envio["buttons"] = [
            {"id": str(o["id"]), "title": o["titulo"]} for o in opciones
        ]

    async with _client_whatsapp() as http:
        r = await http.post(f"/conversations/{conversation_id}/interactive", json=cuerpo_envio)

    if r.status_code == 200:
        log.info("crm.mostrar_opciones.ok", formato=cuerpo_envio["format"], n=len(opciones))

        return f"Opciones enviadas ({len(opciones)}). Esperá que el cliente elija antes de seguir."

    if r.status_code == 409:
        return "STOP: la conversación fue derivada a un asesor humano. No respondas nada más."

    if r.status_code == 501:
        # El canal activo no tiene botones (Kommo, Messenger). No es reintentable.
        return (
            "Este canal no admite botones. Mandá las opciones como texto numerado "
            "y pedile al cliente que responda con el número."
        )

    if r.status_code == 422:
        # El CRM valida los límites de Meta y nombra el campo que falló.
        return _error(r, "Las opciones no cumplen los límites de WhatsApp.")

    return _error(r, "No se pudieron enviar las opciones.")


HERRAMIENTAS_CRM = [
    buscar_productos,
    ficha_producto,
    precio_producto,
    enviar_material,
    mostrar_opciones,
]
