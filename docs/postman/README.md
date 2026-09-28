# Postman · WhatsApp Cloud API (Imprimir / Casa Rica)

Dos archivos para importar en Postman (**Import → File**, o arrastrarlos):

| Archivo | Qué es |
|---|---|
| `whatsapp-plantillas.postman_collection.json` | La colección: 5 requests contra Graph API |
| `whatsapp-imprimir.postman_environment.json` | El environment con los ids de Imprimir y los valores de prueba |

## Después de importar

1. Elegí el environment **WhatsApp · Imprimir (producción)** arriba a la derecha.
2. Abrí el environment y completá **`WHATSAPP_TOKEN`** (está vacío a propósito:
   el token no viaja en el archivo). Es el mismo `WHATSAPP_TOKEN` del `.env` de
   producción. Marcado como *secret*, así Postman lo oculta.
3. Listo. La colección usa auth Bearer con esa variable en todos los requests.

## Los requests

| # | Request | Para qué |
|---|---|---|
| 1 | Enviar `hello_world` | Probar que el número entrega (sin parámetros) |
| 2 | Enviar `carrito_abandonado_casa_rica` | La plantilla aprobada, con NOMBRE, CANTIDAD y CARRITO_SUFIJO |
| 3 | Listar plantillas | Estado (`PENDING` / `APPROVED` / `REJECTED`) y motivo de rechazo |
| 4 | Crear plantilla | **Referencia**: ya está creada. Solo si se borra o cambia el texto |
| 5 | Borrar plantilla | Irreversible. Obliga a recrear y esperar aprobación |

## Variables del environment

| Variable | Valor | De dónde sale en producción |
|---|---|---|
| `TO` | `59176616013` | `"591" + customer.phone` del webhook de WCAL |
| `NOMBRE` | `Javier` | `customer.first_name` |
| `CANTIDAD` | `1 producto` | `items_count` + la palabra (`1 producto` / `3 productos`) |
| `CARRITO_SUFIJO` | `?wcal_action=…` | `cart.recovery_url` **sin el dominio**, url-encodeado |

Sobre `CARRITO_SUFIJO`: el botón está registrado en Meta como
`https://casarica.com.bo/{{1}}`, así que solo viaja lo que sigue al dominio.
Tiene que ir url-encodeado — `@` como `%40` y **cada `+` del token como `%2B`**;
si no, WordPress lee los `+` como espacios y el token no valida.

## Una cosa rara que vas a ver

En la colección hay dos variables llamadas `1` y `2` con valor `{{1}}` y
`{{2}}`. **No las borres.** El request de crear plantilla lleva `{{1}}` y
`{{2}}` en el texto —son los placeholders de Meta—, y Postman los tomaría como
variables propias y los mandaría vacíos. Esas dos variables hacen que lleguen
tal cual.
