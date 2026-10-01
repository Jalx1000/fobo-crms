# Prompt para el Claude del proyecto del agente — enviar material del producto

> **Contexto.** El agente hoy solo puede mandar **texto**
> (`POST /api/v1/whatsapp/conversations/{id}/messages`, campo `text`). Cuando el
> cliente pide "mandame fotos de la caja" o "pasame la ficha técnica", el agente
> no tiene con qué: describe el producto con palabras y el cliente se queda sin
> ver nada.
>
> Los endpoints de abajo **ya están implementados y desplegados** en el CRM. Lo
> que falta es que el agente los conozca y los use.

---

## Qué necesito que implementes

Dos herramientas nuevas para el agente, y las reglas de cuándo usarlas.

La idea central, y la razón de que la API tenga esta forma:

> **El agente pide "3 imágenes del SKU X"; el CRM decide cuáles, en qué orden y
> por qué vía. Las rutas de los archivos nunca pasan por el modelo.**

El agente nunca ve un path, ni una URL de storage, ni un id de archivo. Solo el
SKU —que ya es público, aparece en las cotizaciones— y cuántos adjuntos hay de
cada tipo.

---

## El contrato

Todo bajo `https://imprimir.sofopolis.com`, autenticado con el **mismo Bearer
token** que ya usa el agente para `/api/v1/whatsapp/...`.

### 1. Buscar en el catálogo

```
GET /api/v1/productos/catalogo?q=<texto>&limit=<1..50>
```

`q` busca por nombre o SKU. Devuelve **solo productos que tienen adjuntos**: si
un producto no aparece, no hay material para mandar y no hay que prometerlo.

```json
{
  "data": [
    {
      "sku": "CM_00013",
      "nombre": "Bolsa Plástica tipo camiseta",
      "precio": "1.5000",
      "adjuntos": { "imagen": 5, "documento": 1, "enlace": 0 }
    }
  ]
}
```

`adjuntos` son **conteos**, no archivos. Sirven para decidir cuántos pedir.

### 2. Ver la ficha completa

```
GET /api/v1/productos/catalogo/{sku}
```

Lo mismo más `descripcion` y `caracteristicas` (un mapa etiqueta → valor, ya
legible: `{"Gramaje": "300 g/m²"}`). Es lo que el agente usa para describir el
producto con datos reales en vez de inventar.

404 si el SKU no existe, con el mensaje ya redactado.

### 3. Enviar el material

```
POST /api/v1/productos/conversations/{conversation_id}/media

{
  "sku": "CM_00013",
  "tipo": "imagen",        // imagen | documento | enlace
  "cantidad": 3            // opcional; default 3, techo duro 10
}
```

`conversation_id` es el que el CRM ya inyecta en cada webhook — el mismo que se
usa para responder texto.

**Respuesta 200:**

```json
{ "message": "Enviado.", "enviados": 3, "sku": "CM_00013" }
```

**Errores, y qué significan para el agente:**

| Código | Qué pasó | Qué hace el agente |
|---|---|---|
| `404` | El producto no tiene material de ese tipo | Decírselo al cliente y ofrecer otra cosa. **No reintentar.** |
| `404` | El SKU no existe | Buscar de nuevo en el catálogo. |
| `409` | La conversación fue derivada a un asesor | **Dejar de responder.** Un humano tomó el hilo. |
| `422` | Fuera de la ventana de 24 h, o un archivo no pasa los límites de WhatsApp | El mensaje viene redactado; no reintentar. |

El `404` de "no tiene imágenes" es deliberado y **no** es un 200 con
`enviados: 0`. Si el agente ya dijo "te mando las fotos", tiene que enterarse en
el mismo turno de que no salió ninguna, para corregirse.

---

## Reglas de uso — esto es lo que más importa

**1. Primero el material, después la descripción.**
El endpoint manda los archivos **sin pie de foto**, a propósito. El agente manda
su descripción como un mensaje de texto aparte, *después* de las imágenes. Si se
manda antes, el cliente lee una descripción de algo que todavía no vio.

**2. No prometer lo que no existe.**
Antes de decir "te mando las fotos", consultar el catálogo. Si `adjuntos.imagen`
es 0, no hay fotos: ofrecer el documento, o describir con palabras.

**3. Tres imágenes, no diez.**
El default es 3 y está elegido: una tanda larga satura el chat y el cliente deja
de mirar. Pedir más solo si el cliente lo pidió explícitamente. El techo de 10 lo
aplica el CRM aunque se pida más — no es negociable desde el prompt.

**4. Un envío por producto, no por mensaje.**
Si el cliente pregunta por tres productos, mandar el material de uno, dejar que
responda, y recién después el siguiente. Tres tandas seguidas es spam.

**5. Los documentos son para lo que el cliente pidió leer.**
Fichas técnicas, especificaciones, listas de precios. No mandarlos "de yapa"
junto con las fotos: un PDF sin pedir es ruido.

**6. `tipo: "enlace"` va como texto.**
El CRM lo manda como mensaje de texto para que WhatsApp arme la previsualización.
El agente no tiene que hacer nada distinto, pero conviene que sepa que no va a
aparecer como archivo adjunto.

---

## Lo que quiero de vos

1. Agregar las dos herramientas al agente con estos contratos.
2. Escribir las reglas de arriba en el system prompt, con tus palabras.
3. Decirme si alguna regla choca con algo del flujo actual — sobre todo la de
   "material primero, descripción después", que cambia el orden de los mensajes.
4. Confirmarme qué pasa hoy cuando el cliente pide fotos: si el agente improvisa
   una respuesta, quiero saber cuál, para comparar.
