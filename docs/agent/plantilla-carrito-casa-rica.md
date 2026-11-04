# Plantilla de marketing «carrito abandonado» - Casa Rica

| | |
|---|---|
| Nombre | `carrito_abandonado_casa_rica` |
| Id en Meta | `2336001903885069` |
| Idioma / categoría | `es` / `MARKETING` |
| Estado | **APPROVED** (creada y aprobada el 16/09/2026) |
| WABA | `1984681318732899` (la de Imprimir) |
| Prueba enviada | 16/09/2026 a 59176616013 → `accepted`, wamid `HBgLNTkxNzY2MTYwMTMVAgARGBJBMTBCMzg3MUJDN0ZBRjk2OTYA` |

## El mensaje

```
Tu canasta quedó a un paso

🥬 Hola {{1}}, todavía guardamos tu carrito con {{2}}.

Confirmá hoy y entra en el reparto de mañana temprano,
con todo recién cosechado.

Tocá el botón y terminá tu pedido 👇

Casa Rica · Frutas y verduras
──────────────────────────
       🛒 Ir a mi carrito
```

El emoji va en el cuerpo y no en el título: Meta rechazó la primera versión
con «el título del mensaje no puede contener emojis, saltos de línea ni
asteriscos».

El carrito es WooCommerce con el plugin **Abandoned Cart Lite (WCAL)**, que
manda un webhook por cada carrito abandonado. De ahí salen las variables:

| Parámetro | Del JSON de WCAL | Ejemplo |
|---|---|---|
| `to` | `"591" + customer.phone` (viene sin código de país) | `59176616013` |
| `{{1}}` body | `customer.first_name` | `Javier` |
| `{{2}}` body | `cart.items_count` + `" producto"` / `" productos"` | `1 producto` |
| `{{1}}` botón | `cart.recovery_url` **sin el dominio**, url-encodeado | ver abajo |

`{{2}}` incluye la palabra a propósito: si mandaras solo el número, un carrito
de un ítem diría «1 productos».

### El parámetro del botón

El botón quedó registrado como `https://casarica.com.bo/{{1}}`: Meta solo deja
variable el **final** de la URL. Así que al enviar se manda todo lo que sigue al
dominio del `recovery_url`, **url-encodeado**:

```
recovery_url: https://casarica.local/?wcal_action=track_links&user_email=fperez@fobo.digital&validate=GQBf…ui+W3ixSd2HQ+nhC…
                                      └──────────────────── esto es lo que viaja ────────────────────┘

parámetro:    ?wcal_action=track_links&user_email=fperez%40fobo.digital&validate=GQBf…ui%2BW3ixSd2HQ%2BnhC…
```

Dos reemplazos que no se pueden saltear: `@` → `%40` y **cada `+` del token →
`%2B`**. Sin eso, WordPress lee los `+` como espacios y el token no valida.
El `.` y los `=` y `&` van tal cual.

En PHP: `rawurlencode()` sobre la query entera **no** sirve (codifica los `&`
y `=`); hay que codificar cada valor por separado, o usar `http_build_query`
con los valores ya limpios.

## Cómo se creó (referencia: ya está creada y aprobada)

Solo hace falta volver a correrlo si se borra o si se cambia el texto -en ese
caso con otro `name`, porque el nombre no se libera hasta borrar la anterior.

```bash
TOKEN=$(grep -E '^WHATSAPP_TOKEN=' /etc/easypanel/projects/heaven/imprimir_laravel/code/.env | cut -d= -f2- | tr -d '"')
WABA=1984681318732899

curl --location "https://graph.facebook.com/v21.0/$WABA/message_templates" \
  --header "Authorization: Bearer $TOKEN" \
  --header 'Content-Type: application/json' \
  --data @- <<'JSON'
{
  "name": "carrito_abandonado_casa_rica",
  "language": "es",
  "category": "MARKETING",
  "components": [
    {
      "type": "HEADER",
      "format": "TEXT",
      "text": "Tu canasta quedó a un paso"
    },
    {
      "type": "BODY",
      "text": "🥬 Hola {{1}}, todavía guardamos tu carrito con {{2}}.\n\nConfirmá hoy y entra en el reparto de mañana temprano, con todo recién cosechado.\n\nTocá el botón y terminá tu pedido 👇",
      "example": { "body_text": [["Fabi", "1 producto"]] }
    },
    {
      "type": "FOOTER",
      "text": "Casa Rica · Frutas y verduras"
    },
    {
      "type": "BUTTONS",
      "buttons": [
        {
          "type": "URL",
          "text": "Ir a mi carrito",
          "url": "https://casarica.com.bo/{{1}}",
          "example": ["https://casarica.com.bo/?wcal_action=track_links&user_email=fperez%40fobo.digital&validate=GQBfDKxXqGrjJ5N2LVmkUhhmvU4oTGkVui%2BW3ixSd2HQ"]
        }
      ]
    }
  ]
}
JSON
```

Respondió `{"id":"2336001903885069","status":"PENDING","category":"MARKETING"}`
y pasó a `APPROVED` en menos de un minuto.

## Enviarla (curl probado el 16/09)

Este es exactamente el que se corrió para la prueba a 59176616013:

```bash
TOKEN=$(grep -E '^WHATSAPP_TOKEN=' /etc/easypanel/projects/heaven/imprimir_laravel/code/.env | cut -d= -f2- | tr -d '"')

curl --location 'https://graph.facebook.com/v21.0/1243549565512876/messages' \
  --header "Authorization: Bearer EAAgnG0ZBsEl8BSenolnDBnAhLPQyQoltn5MTYAXIp6wzzMDZBunzCacig2BenIUJkxZBLmJzJ6DZAwsUQNuBp0aZCLVJDxlcb0zERyPoZAq1vrnAIZAqVOzEq3b1ZCom2xAGSHMLTZCQhF6wMZAXsLVR6TgYb7WMebw26nxrGzrBOgu9mRlfMpp5j6AlCsRIytEwZDZD" \
  --header 'Content-Type: application/json' 
{
  "messaging_product": "whatsapp",
  "recipient_type": "individual",
  "to": "59176616013",
  "type": "template",
  "template": {
    "name": "carrito_abandonado_casa_rica",
    "language": { "code": "es" },
    "components": [
      {
        "type": "body",
        "parameters": [
          { "type": "text", "text": "Javier" },
          { "type": "text", "text": "1 producto" }
        ]
      },
      {
        "type": "button",
        "sub_type": "url",
        "index": "0",
        "parameters": [
          { "type": "text", "text": "?wcal_action=track_links&user_email=fperez%40fobo.digital&validate=GQBfDKxXqGrjJ5N2LVmkUhhmvU4oTGkVui%2BW3ixSd2HQ%2BnhCXv4j0%2BjSvnrV.422a1218734408e12b2a346ca242ff73ceb837387f961a02a033c962ba3353aa" }
        ]
      }
    ]
}

JSON
```

curl --location 'https://graph.facebook.com/v21.0/1243549565512876/messages' \
--header 'Cookie: fr=0Wn3ZtcgDpWDg0cMh..BqhGYs..AAA.0.0.BqhGb_.AWdNT6KkWNonlhIBYj9Mo7Vc63Y; sb=LGaEaumS4A-2wAvuKS7ftMnK' \
--header 'Content-Type: application/json' \
--header 'Authorization: Bearer EAAgnG0ZBsEl8BSenolnDBnAhLPQyQoltn5MTYAXIp6wzzMDZBunzCacig2BenIUJkxZBLmJzJ6DZAwsUQNuBp0aZCLVJDxlcb0zERyPoZAq1vrnAIZAqVOzEq3b1ZCom2xAGSHMLTZCQhF6wMZAXsLVR6TgYb7WMebw26nxrGzrBOgu9mRlfMpp5j6AlCsRIytEwZDZD' \
--data '{
  "name": "carrito_abandonado_casa_rica",
  "language": "es",
  "category": "MARKETING",
  "components": [
    {
      "type": "HEADER",
      "format": "TEXT",
      "text": "Tu canasta quedó a un paso"
    },
    {
      "type": "BODY",
      "text": "Hola Danitza, todavía guardamos tu carrito con {{2}}.\n\nConfirmá hoy y entra en el reparto de mañana temprano, con todo recién cosechado.\n\nTocá el botón y terminá tu pedido 👇",
      "example": { "body_text": [["Danitza", "5 productos"]] }
    },
    {
      "type": "FOOTER",
      "text": "Casa Rica · Frutas y verduras"
    },
    {
      "type": "BUTTONS",
      "buttons": [
        {
          "type": "URL",
          "text": "Ir a mi carrito",
          "url": "https://casarica.com.bo/carrito",
          "example": ["https://casarica.com.bo/carrito"]
        }
      ]
    }
  ]
}'

Respuesta:

```json
{
  "messaging_product": "whatsapp",
  "contacts": [{ "input": "59176616013", "wa_id": "59176616013" }],
  "messages": [{ "id": "wamid.HBgLNTkxNzY2MTYwMTMVAgARGBJBMTBCMzg3MUJDN0ZBRjk2OTYA", "message_status": "accepted" }]
}
```

`accepted` significa que Meta lo tomó, no que el cliente lo recibió. La
entrega llega después por webhook (`sent` → `delivered` → `read`).

El token de esa prueba es del entorno local (`casarica.local`): el botón arma
bien la URL, pero en `casarica.com.bo` no recupera ningún carrito. Con un
`recovery_url` de producción funciona directo.

## Lo que hay que saber antes de usarla

- **Es MARKETING**: solo a quien dio su consentimiento para recibir promociones.
  Sin opt-in, los reportes de spam bajan la calidad del número y Meta termina
  limitando cuántos mensajes se pueden enviar por día.
- **El footer no admite variables.** «Casa Rica» queda fijo; otra tienda obliga a
  otra plantilla.
- **El body no puede empezar ni terminar con variable.** Por eso dice «Hola
  {{1}}» y no «{{1}}, todavía…»: así pasa la revisión.
- **Cambiar el texto obliga a recrearla** y a esperar la aprobación de nuevo. El
  nombre (`carrito_abandonado_casa_rica`) no se puede reutilizar hasta borrar la
  anterior.
- **La WABA es la de Imprimir.** El cliente ve el número y el nombre verificado
  de Imprimir, no de Casa Rica, y Meta puede rechazar la plantilla por no
  corresponder al negocio de la cuenta. Para enviar en serio, Casa Rica necesita
  su propia WABA.

## Ver el estado de las plantillas

```bash
TOKEN=$(grep -E '^WHATSAPP_TOKEN=' /etc/easypanel/projects/heaven/imprimir_laravel/code/.env | cut -d= -f2- | tr -d '"')

curl -s 'https://graph.facebook.com/v21.0/1984681318732899/message_templates?fields=name,status,language,category,rejected_reason&limit=50' \
  -H "Authorization: Bearer $TOKEN" | jq '.data[] | {name, language, status, category, rejected_reason}'
```
