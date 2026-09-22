# Botones y listas — contrato para el agente de WhatsApp

> **Actualización 2026-09-10 — mismo contrato que imprimir.** Kohlberg ahora expone
> **exactamente** lo que ya usás en imprimir: la opción tocada llega en `message.selection`
> con `{id, title}`, y existe `POST .../interactive` con el mismo formato. **La tool que ya
> tenés para imprimir funciona acá sin cambios.** El marcador `N.-)` de abajo sigue vivo como
> alternativa.

**Qué cambia para vos:** nada si ya usás la tool de imprimir. Si no, tenés dos caminos:
el endpoint estructurado (sección 0) o numerar las opciones con `N.-)` en el texto (secciones
1 a 4) y dejar que el CRM las convierta.

**Qué gana el cliente:** toca en vez de escribir. No puede tipear una ciudad que no existe,
ni escribir "Scz" y que se resuelva mal.

---

## 0. El endpoint estructurado (el de imprimir)

`POST /api/v1/whatsapp/conversations/{id}/interactive` — **idéntico** al de imprimir.

```json
// Botones — máximo 3
{
  "format": "button",
  "body": "¿Confirmás el pedido?",
  "footer": "Kohlberg",
  "buttons": [
    { "id": "si", "title": "Sí" },
    { "id": "no", "title": "No" }
  ]
}

// Lista — hasta 10 filas en total
{
  "format": "list",
  "body": "¿Desde qué ciudad nos escribís?",
  "list_button": "Ver ciudades",
  "sections": [
    { "title": "Oriente", "rows": [ { "id": "scz", "title": "Santa Cruz" } ] },
    { "title": "Valles",  "rows": [ { "id": "cbba", "title": "Cochabamba" }, { "id": "tja", "title": "Tarija" } ] }
  ]
}
```

Campos opcionales: `header`, `footer`, `reply_to_id`. Los límites de Meta (3 botones, 10
filas, 20/24 caracteres) se validan antes de enviar y vuelven como **422 con el motivo**.

Usalo cuando quieras control sobre los ids. Para lo demás, el marcador de abajo es más simple.

## 1. Cómo se usa (marcador en el texto)

Escribís esto en tu respuesta de siempre, con `POST /conversations/{id}/messages`:

```
¿Desde qué ciudad nos escribís?

1.-) Santa Cruz
2.-) Cochabamba
3.-) La Paz
```

Al cliente le llegan **tres botones tocables**. Cuando toca uno, vos recibís **lo mismo que
en imprimir**:

```json
{
  "message": {
    "type": "interactive",
    "text": "Santa Cruz",
    "selection": { "id": "opt:1", "title": "Santa Cruz" }
  }
}
```

- `text` trae el título — lo que el cliente ve.
- `selection.id` es por donde tenés que rutear: los títulos se recortan a 20/24 caracteres y
  pueden repetirse entre menús; el id no.
- Con el marcador `N.-)` los ids los genera el CRM (`opt:1`, `opt:2`…); con el endpoint
  estructurado son los que vos mandaste.

> ⚠️ Si en algún momento kohlberg te mandó `type: "text"` para un tap, ya no: ahora es el tipo
> real `interactive` + `selection`, igual que imprimir. Un solo contrato para los dos tenants.

---

## 2. Botones o lista: lo decide el CRM

No elegís vos. Según cuántas opciones y qué tan largas sean:

| Opciones | Largo máximo | Qué sale |
|---|---|---|
| 2 a 3 | 20 caracteres | **botones** (se ven de una) |
| 2 a 10 | 24 caracteres | **lista** (abre un menú) |
| más de 10, o alguna más larga | — | **texto plano**, numerado `1.` `2.` |

Ese último caso **no es un error y no te devuelve 4xx**. El mensaje sale igual, con
numeración normal, y el cliente responde escribiendo. Preferí opciones cortas para evitarlo.

---

## 3. 🔴 `N.-)` es SOLO para menús

Este es el punto que importa.

**Sí — el cliente tiene que elegir:**
```
¿Desde qué ciudad nos escribís?

1.-) Santa Cruz
2.-) Cochabamba
```

**No — le estás contando algo, usá `1.` normal:**
```
Alejandro, tenés 4 pedidos en estado "Cliente confirmado" en Santa Cruz:

1. Pedido de 2 Promo Varietales: 2 Promo Varietales, total Bs 478.00.
2. Pedido de 3 vinos: incluye 1 Promo Varietales y 2 Pack Red Blend + Copa.

¿Querés que te ayude con algo más?
```

Ese segundo es un mensaje real tuyo, del historial de producción. Si lo marcaras con `N.-)`
le estarías pidiendo al cliente que elija un pedido que nadie le ofreció. Los `1.` `2.`
normales **nunca** se convierten — es exactamente para eso que el marcador es `.-)` y no `.`.

---

## 4. Reglas de armado

1. **La pregunta va ANTES de las opciones.** Ese texto es el cuerpo del mensaje y es
   obligatorio: si mandás sólo las opciones sin nada arriba, WhatsApp lo rechaza y el CRM lo
   degrada a texto.
2. **El texto de después es una nota al pie** (máx. 60 caracteres). Si es más largo, se pega
   al final del cuerpo.
3. **No numeres dentro de la opción** ni digas "escribí 1 para Santa Cruz". El cliente toca.
4. **Una opción por línea.** El marcador tiene que estar al principio de la línea.
5. **Numeración consecutiva desde 1.** Si salteás (1, 2, 4) no se convierte nada.
6. **Fuera de la ventana de 24 h no funciona**, igual que cualquier mensaje libre. Ahí hace
   falta una plantilla aprobada. El CRM te devuelve **422** con ese mensaje.

---

## 5. El prompt

Pegá este bloque en el system prompt del agente.

```
## Opciones para que el cliente elija

Cuando le ofrezcas al cliente un conjunto cerrado de opciones —ciudades,
sucursales, categorías, sí/no— numeralas con el marcador `N.-)`, una por
línea:

    ¿Desde qué ciudad nos escribís?

    1.-) Santa Cruz
    2.-) Cochabamba
    3.-) La Paz

El CRM las convierte automáticamente en botones o en una lista tocable de
WhatsApp. El cliente toca en vez de escribir, y vos recibís la etiqueta
exacta que tocó ("Santa Cruz") como si la hubiera tipeado. No tenés que
llamar ninguna herramienta ni cambiar cómo respondés.

Reglas:

1. Máximo 10 opciones. Cada una, 24 caracteres o menos.
   Si no entran, el CRM manda el mensaje como texto normal y el cliente
   tiene que escribir la respuesta. Preferí opciones cortas: "Santa Cruz",
   no "Santa Cruz de la Sierra (zona norte)".
2. Usá `N.-)` SOLO para opciones que el cliente debe elegir.
   Para enumerar información —los pedidos que tiene, los productos de una
   promo— usá `1.` `2.` normales. Esas NO se convierten, y está bien así:
   no le pidas al cliente que elija algo que sólo le estás contando.
3. El texto antes de la lista es la pregunta; el de después, una nota al
   pie corta. Poné la pregunta ANTES de las opciones, siempre.
4. No numeres dentro de la opción ni repitas el número en el texto
   ("Escribí 1 para Santa Cruz"). El cliente toca, no escribe.
5. Fuera de la ventana de 24 h de WhatsApp esto no funciona, igual que
   cualquier mensaje libre. Ahí hace falta una plantilla aprobada.
```

---

## 6. Cómo probarlo

```bash
export CRM_TOKEN='...'
CONV=42   # id de una conversación con mensaje del cliente en las últimas 24 h

# Botones: 3 opciones cortas
curl -sS -X POST "https://kohlberg.sofopolis.com/api/v1/whatsapp/conversations/$CONV/messages" \
  -H "Authorization: Bearer $CRM_TOKEN" -H 'Content-Type: application/json' \
  -d '{"text":"¿Desde qué ciudad nos escribís?\n\n1.-) Santa Cruz\n2.-) Cochabamba\n3.-) La Paz"}'

# Lista: 7 opciones
curl -sS -X POST "https://kohlberg.sofopolis.com/api/v1/whatsapp/conversations/$CONV/messages" \
  -H "Authorization: Bearer $CRM_TOKEN" -H 'Content-Type: application/json' \
  -d '{"text":"¿Desde qué ciudad?\n\n1.-) Santa Cruz\n2.-) Cochabamba\n3.-) La Paz\n4.-) Potosí\n5.-) Oruro\n6.-) Sucre\n7.-) Tarija"}'

# Degradación: 12 opciones → sale como texto, no como error
# (mirá el mensaje en el inbox: la numeración queda "1." normal)

# El endpoint estructurado, con tus propios ids
curl -sS -X POST "https://kohlberg.sofopolis.com/api/v1/whatsapp/conversations/$CONV/interactive" \
  -H "Authorization: Bearer $CRM_TOKEN" -H 'Content-Type: application/json' \
  -d '{"format":"button","body":"¿Confirmás el pedido?","buttons":[{"id":"si","title":"Sí"},{"id":"no","title":"No"}]}'
```

Para confirmar qué se envió, `GET /conversations/$CONV/messages` y mirá el último mensaje.

---

## 7. Códigos de respuesta

| Código | Qué pasó | Qué hacer |
|---|---|---|
| `200` | Encolado. Salga como menú o como texto, el cliente lo recibe | seguir normal |
| `409` | La conversación fue derivada a un asesor | soltar el turno, no responder más |
| `422` | Fuera de la ventana de 24 h | usar una plantilla aprobada |
| `401` | Token inválido | revisar el Bearer |
| `422` *(sólo `/interactive`)* | Un límite de Meta violado — el mensaje dice cuál | corregir y reintentar |
| `501` *(sólo `/interactive`)* | El canal activo no tiene botones | mandar las opciones como texto |

**No hay un código para "el menú no entraba".** Es deliberado: eso se degrada a texto y sale
igual. Nunca vas a quedarte mudo frente al cliente por un problema de formato.

---

## 8. Lo que el CRM NO hace, para que lo cubras vos

- **No valida que las opciones tengan sentido.** Si ofrecés una ciudad donde no hay sucursal,
  el cliente la va a poder tocar.
- **No recuerda qué menú mandaste.** Si el cliente responde con texto libre en vez de tocar,
  te llega como texto libre y lo resolvés vos.
- **No reenvía el menú** si el cliente lo ignora. Si querés insistir, lo mandás de nuevo.
- **No traduce el `id`.** Los ids son `opt:1`, `opt:2`… en el orden en que los escribiste. Si
  necesitás mapear a algo del CRM, hacelo por el `title`, que es la etiqueta exacta.
