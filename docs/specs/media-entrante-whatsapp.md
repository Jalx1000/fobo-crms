# Spec: Recepción de media entrante en WhatsApp

- **Estado:** 🟡 Fase 1 (Specify). Pendiente de revisión humana. **No implementar hasta aprobación.**
- **Fase:** 1 de 4 (Specify → Plan → Tasks → Implement)
- **Instancia:** `c21.sofopolis.com` · Krayin 2.1.5 · gateway activo `cloud_api`
- **Branch:** `inmobiliaria-agente-c21`

---

## Supuestos

Corregime ahora o procedo con estos:

1. **Cambios en vivo, sin despliegue.** `docker/entrypoint.sh` no vuelve a correr, así que cada
   cambio de código exige reiniciar el worker a mano (`supervisorctl restart whatsapp`).
2. **Solo Cloud API.** Kommo no recibe media y no se va a implementar para él.
3. **Verificación manual** contra la instancia. La suite de tests es destructiva y sigue sin
   arreglarse.
4. **El agente no interpreta imágenes.** Sabe que llegó una foto y su epígrafe; no la "ve". Un
   modelo con visión queda fuera de alcance.
5. **Sin migración obligatoria de lo histórico.** Hoy no hay ni un mensaje entrante con media en la
   base, así que el relleno retroactivo es opcional.

---

## Objetivo

Que un archivo que el cliente manda por WhatsApp **llegue completo al CRM**: se descargue, se guarde,
se vea en el inbox y el agente se entere de que existe.

**Usuarios y qué gana cada uno:**

- **Asesor** — ve la foto en el hilo, no un mensaje vacío. Hoy un cliente puede mandar la foto de una
  fisura en una pared y del otro lado no aparece nada.
- **Propietario en captación** — puede mandar fotos de su casa cuando ofrece listarla. Es el caso que
  el flujo de captación necesita y hoy no existe.
- **Agente Sofía** — recibe `type: "image"` con su epígrafe en vez de `content: null`, y puede
  reaccionar ("recibí tu foto, se la paso al asesor") en lugar de ignorar el mensaje.

**Fuera de alcance:** interpretar el contenido de la imagen, adjuntar automáticamente las fotos
recibidas a un inmueble del catálogo, y recibir media en Kommo.

---

## Hallazgos del reconocimiento

| # | Hallazgo | Impacto |
|---|---|---|
| H1 | **El id del archivo ya se guarda.** `IngestInboundPayload` persiste `payload => $inbound->raw`, que para una imagen incluye `image.id`, `mime_type`, `sha256` y `caption`. | ✅ No se perdió información: nunca se descargó el archivo, pero el puntero está. Un relleno retroactivo es posible. |
| H2 | Las columnas `media_path`, `media_mime` y `media_name` **ya existen** en `whatsapp_messages` y están vacías en todo lo entrante. | ✅ Sin migración de esquema para el caso principal. |
| H3 | El hilo del inbox **ya sabe pintar** `image`, `sticker`, `audio`, `video`, `document`, `location` y `contact`. | ✅ La UI está lista; le falta una URL que sirva. |
| H4 | 🔴 **`InboxController::transform()` devuelve `media.url = $message->media_path`** — una ruta de disco (`whatsapp/16/x.jpg`), no una URL servible. | **Bug activo, introducido hoy.** El adjunto que el asesor manda desde el inbox **sale bien a WhatsApp pero se ve roto en su propio hilo**. Se arregla dentro de este trabajo. |
| H5 | `CloudApiGateway::capabilities()` declara `receive: [image, audio, document, video, sticker, location, contact]` y no guarda ninguno. | El docblock de `Capabilities` dice que una capacidad listada tiene que funcionar siempre. Hoy miente. |
| H6 | La URL de descarga de Meta se obtiene en dos pasos (`GET /{media-id}` → `url`, luego `GET url` con el token) y **vive pocos minutos**. | La descarga no puede diferirse mucho ni reintentarse tarde: hay que re-resolver el id, no reusar la URL. |
| H7 | El webhook debe responder rápido a Meta o reintenta y duplica. | La descarga va en un job, nunca dentro del ingest. |

---

## Decisiones propuestas

### D1 — La descarga va en un job, no en el ingest

El ingest sigue creando el mensaje y avisando al agente igual de rápido que hoy; un job aparte
descarga el archivo y completa la fila. **El mensaje aparece en el inbox al instante, con un
marcador de "descargando"**, y la imagen se materializa cuando llega.

Alternativa descartada: descargar dentro del ingest. Ata el acuse a Meta y el aviso al agente a la
velocidad de una descarga, y una foto de 15 MB retrasaría ambos.

### D2 — Disco privado, servido por ruta autenticada

Los archivos van a `storage/app/whatsapp/{conversation_id}/inbound/`, en el disco privado, igual que
la galería de inmuebles. Nada bajo `storage/app/public`: son conversaciones de clientes.

### D3 — Una sola ruta de servido, que arregla H4 de paso

`GET admin/whatsapp/media/{message}` — autenticada, valida que el mensaje exista y tenga archivo, y
lo transmite. `transform()` pasa a devolver esa ruta en vez del path crudo.

**Esto arregla el bug de H4 sin trabajo extra**: entrante y saliente usan la misma ruta.

### D4 — Qué se le manda al agente

El webhook y el historial ya incluyen `message.type`. Se agrega:

```jsonc
"message": {
  "type": "image",
  "text": "Mirá esta fisura",     // el epígrafe, si lo hay
  "media": { "kind": "image", "disponible": true }
}
```

**No se le manda URL ni binario.** El agente no ve imágenes; sabe que llegó una y con qué epígrafe.
`disponible: false` mientras la descarga esté pendiente o haya fallado, para que no afirme tener algo
que el asesor no puede abrir.

### D5 — Qué se descarga y qué no

| Tipo | ¿Se descarga? | Motivo |
|---|---|---|
| `image`, `document`, `video`, `audio` | Sí | Es lo que un cliente manda con intención |
| `sticker` | Sí | Barato y el inbox ya lo pinta |
| `location`, `contact` | No aplica | No son archivos; ya viajan en el payload |
| Cualquiera > límite configurable | No | Se registra el mensaje con el motivo visible en el hilo |

### D6 — Reintentos acotados y honestos

3 intentos con backoff corto (10 s / 30 s / 60 s), **re-resolviendo el id de Meta en cada intento**
porque la URL caduca. Agotados los intentos, el mensaje queda con un motivo legible en el hilo
("no se pudo descargar"), no en blanco: el asesor tiene que poder pedirle al cliente que lo reenvíe.

### D7 — Las capabilities dicen la verdad

`receive` pasa a listar exactamente lo que se implemente. Si algo queda afuera, sale de la lista.

### D8 — Relleno retroactivo, opcional

Un comando `crm:descargar-media-historica` que recorre los mensajes entrantes con `payload` y sin
`media_path`. Hoy no hay ninguno, así que es una red por si aparecen antes de terminar el trabajo.

---

## Tech stack

Laravel 10 · PHP 8.2 · Redis (cola `whatsapp`) · MySQL 9 · WhatsApp Cloud API v21.0 · Vue 3 en el
inbox. Sin dependencias nuevas.

---

## Comandos

```bash
C=heaven_c21_laravel.1.imolcmadtascuk4e2xsutwci8

# Sintaxis y estilo
docker exec -w /code $C ./vendor/bin/pint packages/Webkul/Whatsapp

# Migraciones (como www-data: la CLI corre como root y ensucia permisos)
docker exec -w /code -u www-data -e HOME=/tmp $C php artisan migrate --force

# IMPRESCINDIBLE tras cada cambio de código: el worker cachea las clases
docker exec $C supervisorctl restart whatsapp

# Cola
docker exec -w /code -u www-data -e HOME=/tmp $C php artisan queue:failed
docker exec -w /code -u www-data -e HOME=/tmp $C php artisan queue:retry all
```

---

## Estructura

```
packages/Webkul/Whatsapp/src/
  Gateways/Contracts/Gateway.php       → + downloadMedia()
  Gateways/CloudApi/CloudApiGateway.php→ implementación (2 pasos) + capabilities honestas
  Gateways/Dto/InboundMessage.php      → + mediaId, mediaMime, mediaFilename
  Jobs/DescargarMediaEntrante.php      → NUEVO
  Jobs/IngestInboundPayload.php        → despacha el job cuando hay archivo
  Http/Controllers/InboxController.php → media() + transform() con URL real
  Routes/admin.php                     → GET whatsapp/media/{message}
  Services/AgentPayload.php            → bloque `media` en el evento y el historial
  Console/Commands/DescargarMediaHistorica.php → NUEVO (D8)
```

---

## Estilo

Como el resto del módulo: comentarios que explican **por qué**, no qué; DTOs inmutables; nada
provider-specific fuera del gateway.

```php
/**
 * Descarga el archivo de un mensaje entrante y completa su fila.
 *
 * Corre fuera del ingest a propósito: el webhook tiene que acusar recibo a Meta
 * rápido o reintenta y duplica el mensaje, y una foto de 15 MB no puede
 * retrasar ni ese acuse ni el aviso al agente.
 *
 * La URL de descarga de Meta caduca en minutos, así que cada reintento vuelve a
 * resolver el id en vez de reusar la URL anterior.
 */
class DescargarMediaEntrante implements ShouldQueue
{
    public int $tries = 3;

    public array $backoff = [10, 30, 60];
}
```

---

## Estrategia de verificación

Sin suite automatizada (la del repo es destructiva). Verificación en tres niveles:

1. **Unitaria a mano, en transacción revertida** — el parser extrae el id de un payload real de Meta;
   el job completa la fila; `transform()` devuelve una URL servible.
2. **Por el kernel HTTP** — la ruta de media responde 200 con el archivo y 404 sin él; el hilo del
   inbox trae `media.url` usable.
3. **Punta a punta, con una foto real** enviada desde un WhatsApp a la instancia. Es la única que
   prueba el camino completo, y **requiere autorización explícita** porque involucra un número real.

Cada nivel se corre y se reporta con su salida, no se declara "hecho".

---

## Límites

**Siempre**
- Reiniciar el worker después de cada cambio de código.
- Correr artisan como `www-data`, nunca como root: root deja archivos que la web no puede leer.
- Backup antes de tocar esquema; el dump completo hoy funciona (600 MB de límite, 81% de uso).
- Verificar con salida real antes de decir que algo funciona.

**Preguntar primero**
- Enviar cualquier mensaje a un número real.
- Cambiar `Capabilities`: enciende y apaga UI en el inbox (fue la causa de H4).
- Agregar dependencias o tocar `config/filesystems.php`.
- Cualquier cambio de esquema más allá de columnas nullable aditivas.

**Nunca**
- Guardar media de conversaciones en `storage/app/public`.
- Descargar dentro del ingest o del ciclo del webhook.
- Declarar en `capabilities` algo que no funcione.
- Editar archivos de `packages/Webkul/{Admin,Core,Lead,Attribute,DataGrid}`.
- Mandar al agente URLs o binarios de archivos.

---

## Criterios de éxito

Específicos y comprobables:

1. Un cliente manda una foto con epígrafe → aparece en el inbox **renderizada**, con su texto.
2. El archivo queda en el disco **privado**; pedirlo sin sesión da 401/404, nunca el binario.
3. **H4 arreglado:** un adjunto que el asesor envía desde el inbox se ve en su propio hilo.
4. El webhook al agente trae `type: "image"` y el epígrafe en `text`, con `media.disponible`
   reflejando el estado real de la descarga.
5. Un archivo que supera el límite deja un mensaje con motivo legible, no una fila en blanco.
6. Tres descargas fallidas dejan el motivo visible en el hilo y **cero** jobs en `failed_jobs` sin
   explicación.
7. `capabilities().receive` lista exactamente lo que funciona; se verifica tipo por tipo.
8. `git status --porcelain packages/Webkul/{Admin,Core,Lead}` vacío.

---

## Preguntas abiertas

1. **¿Límite de tamaño?** Propongo 25 MB para imágenes y documentos, 100 MB para video. Meta admite
   hasta 100 MB. Más alto llena el disco de la instancia; más bajo rechaza videos legítimos.
2. **¿Se guarda el audio?** Un audio de voz es común en WhatsApp y hoy queda en blanco. Descargarlo
   es igual de barato, pero sin transcripción el asesor tiene que escucharlo igual.
3. **¿Retención?** Nada borra estos archivos hoy. ¿Se conservan indefinidamente, o hay una purga?
4. **¿Las fotos que manda un propietario deberían poder promoverse al catálogo** con un botón, o
   alcanza con que queden en la conversación? (Estaba en el alcance descartado; confirmalo.)
