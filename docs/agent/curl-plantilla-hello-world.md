# Enviar la plantilla `hello_world` por WhatsApp (curl)

Datos de Imprimir, tomados del `.env` de producción:

| Dato | Valor |
|---|---|
| `phone_number_id` | `1243549565512876` |
| `waba_id` | `1984681318732899` |
| Versión de Graph API | `v21.0` |

No los crucen: desalinear el par phone/WABA responde `132001` o
«Unsupported post request», y el mensaje no sale.

## La plantilla

`hello_world` es la de ejemplo de Meta. En esta WABA es la **única** cargada:

```
hello_world    en_US    APPROVED    UTILITY
```

**No lleva parámetros**: el cuerpo es texto fijo. Por eso el pedido va **sin
`components`** — mandárselos devuelve el error `132000` («number of parameters
does not match»).

## El curl

```bash
# El token sale del .env para no pegarlo en la terminal: lo que se escribe a
# mano queda en el historial del shell.
TOKEN=$(grep -E '^WHATSAPP_TOKEN=' /etc/easypanel/projects/heaven/imprimir_laravel/code/.env | cut -d= -f2- | tr -d '"')

curl --location 'https://graph.facebook.com/v21.0/1243549565512876/messages' \
  --header "Authorization: Bearer EAAgnG0ZBsEl8BSenolnDBnAhLPQyQoltn5MTYAXIp6wzzMDZBunzCacig2BenIUJkxZBLmJzJ6DZAwsUQNuBp0aZCLVJDxlcb0zERyPoZAq1vrnAIZAqVOzEq3b1ZCom2xAGSHMLTZCQhF6wMZAXsLVR6TgYb7WMebw26nxrGzrBOgu9mRlfMpp5j6AlCsRIytEwZDZD" \
  --header 'Content-Type: application/json' \
  --data '{
    "messaging_product": "whatsapp",
    "recipient_type": "individual",
    "to": "59175640698",
    "type": "template",
    "template": {
        "name": "hello_world",
        "language": { "code": "en_US" }
    }
}'
```

curl --location 'https://graph.facebook.com/v21.0/1243549565512876/messages' \
  --header "Authorization: Bearer EAAgnG0ZBsEl8BSenolnDBnAhLPQyQoltn5MTYAXIp6wzzMDZBunzCacig2BenIUJkxZBLmJzJ6DZAwsUQNuBp0aZCLVJDxlcb0zERyPoZAq1vrnAIZAqVOzEq3b1ZCom2xAGSHMLTZCQhF6wMZAXsLVR6TgYb7WMebw26nxrGzrBOgu9mRlfMpp5j6AlCsRIytEwZDZD" \
  --header 'Content-Type: application/json' \
  --data '{
    "messaging_product": "whatsapp",
    "recipient_type": "individual",
    "to": "59175640698",
    "type": "template",
    "template": {
        "name": "hello_world",
        "language": { "code": "en_US" }
    }

curl --location 'https://graph.facebook.com/v21.0/1243549565512876/messages' \
  --header "Authorization: Bearer " \
  --header 'Content-Type: application/json' \
  --data '{
    "messaging_product": "whatsapp",
    "recipient_type": "individual",
    "to": "59176616013”,
    "type": "template",
    "template": {
        "name": "hello_world",
        "language": { "code": "en_US" }
    }
}'

`to`: formato internacional **sin `+`, sin espacios y sin guiones** —
`59175640698`, no `+591 75640698`.

## Qué responde

Aceptado:

```json
{
  "messaging_product": "whatsapp",
  "contacts": [{ "input": "59175640698", "wa_id": "59175640698" }],
  "messages": [{ "id": "wamid.HBgM…", "message_status": "accepted" }]
}
```

`accepted` significa que Meta lo tomó, no que el cliente lo recibió. La entrega
llega después por webhook (`sent` → `delivered` → `read`).

## Los errores que más aparecen

| Código | Qué pasó | Qué hacer |
|---|---|---|
| `132000` | Se mandaron `components` a una plantilla sin parámetros | Sacar `components` |
| `132001` | Nombre o idioma que no existe en esta WABA | Es `hello_world` + `en_US`, exactamente |
| `131030` | El número destino no está en la lista de permitidos | Solo pasa con números de prueba: agregarlo en Meta |
| `190` | Token vencido o revocado | Renovar `WHATSAPP_TOKEN` en el `.env` |
| `100` / *Unsupported post request* | `phone_number_id` que no pertenece a esa WABA | Revisar el par de la tabla de arriba |

## Dos cosas antes de mandarlo a un cliente real

1. **Una plantilla se puede enviar fuera de las 24 horas**; un mensaje de texto
   común, no. Por eso `hello_world` sirve para probar si el número entrega.
2. **Si el cliente responde**, se abre la ventana de 24 h: ese mensaje entra al
   inbox del CRM y despierta al agente. Para probar sin consecuencias, mandalo a
   un número propio.

## Ver las plantillas cargadas

```bash
TOKEN=$(grep -E '^WHATSAPP_TOKEN=' /etc/easypanel/projects/heaven/imprimir_laravel/code/.env | cut -d= -f2- | tr -d '"')

curl -s 'https://graph.facebook.com/v21.0/1984681318732899/message_templates?fields=name,status,language,category&limit=50' \
  -H "Authorization: Bearer $TOKEN" | jq '.data[] | {name, language, status, category}'
```


javier@MacBook-Air-de-Javier ~ % curl --location 'https://graph.facebook.com/v25.0/1243549565512876/messages' \
  --header "Authorization: Bearer EAAgnG0ZBsEl8BSenolnDBnAhLPQyQol" \
  --header 'Content-Type: application/json' \
  --data '{
    "messaging_product": "whatsapp",
    "recipient_type": "individual",
    "to": "59176616013”,
    "type": "template",
    "template": {
        "name": "hello_world",
        "language": { "code": "en_US" }
    }
}'
{"error":{"message":"(#100) The parameter messaging_product is required.","type":"OAuthException","code":100,"fbtrace_id":"AoJ22BUXWKwhqTCLwHLwpE9"}}%