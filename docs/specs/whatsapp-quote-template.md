# Spec: Envío de cotizaciones fuera de la ventana de 24h (plantillas HSM)

> Estado: **APROBADA** (2026-08-21). Preguntas abiertas resueltas - ver
> "Decisiones tomadas". Lista para planificar.
>
> Rama: `imprimir-modulo-whatsapp` · Autor: Javier Mogro / Claude

---

## Contexto

La acción "Enviar por WhatsApp" en Cotizaciones ya está implementada
(`QuoteController::sendWhatsapp`, `DocumentDispatcher`) y manda el PDF como
mensaje `document`. Hoy **bloquea con un 422** cuando la ventana de servicio de
24h está cerrada, porque el contrato `Gateway` solo expone
`sendText` / `sendMedia` / `sendLocation`.

El caso "ventana cerrada" no es el raro: una cotización se arma y se manda horas
o días después de la conversación. Con la implementación actual, el vendedor ve
el aviso y no puede hacer nada más que pedirle al cliente que escriba primero.

---

## Supuestos que estoy haciendo

Corregime ahora o procedo con estos:

1. El canal en producción es **Cloud API** (`WHATSAPP_GATEWAY=cloud_api`).
   Kommo y Messenger quedan fuera de alcance: heredan el `throw` de
   `BaseGateway` como ya hacen con `sendMedia`/`sendLocation`.
2. La plantilla se clasifica como **Utility**, no Marketing - la cotización
   responde a una solicitud del cliente. (Ver `docs/specs` conversación previa.)
3. La plantilla lleva el **PDF como header de tipo `document`**, no un link. Es
   coherente con `sendMedia`, que ya sube los bytes a Meta en vez de exponer una
   URL adivinable.
4. Hay **una sola plantilla** para cotizaciones, no una por marca/sucursal.
5. El WABA ya tiene permisos para crear plantillas y alguien del equipo las
   aprueba manualmente en WhatsApp Manager. **No** vamos a crear plantillas por
   API desde el CRM.
6. El envío por plantilla sigue siendo **encolado** (`SendMessage` en la cola
   `whatsapp`), no síncrono.
7. Presupuesto: se acepta el costo por mensaje de una Utility template. No hay
   límite de volumen que respetar desde el código.

---

## Objetivo

Que un vendedor pueda enviar una cotización por WhatsApp **en cualquier
momento**, no solo dentro de las 24h posteriores al último mensaje del cliente.

**Usuario:** vendedor / asesor del CRM, desde el DataGrid de Cotizaciones o
desde la pestaña Cotizaciones de una oportunidad.

**Éxito:** el cliente recibe el PDF de la cotización con un texto aprobado por
Meta, y el mensaje queda registrado en el hilo del inbox igual que cualquier
otro - con estado de entrega, y visible en la pestaña "Docs" del drawer.

### Historias

- Como vendedor, cuando termino una cotización de un cliente que me escribió
  hace tres días, quiero mandársela por WhatsApp sin pedirle que me escriba
  primero.
- Como vendedor, quiero **ver antes de apretar** si el envío va a salir como
  mensaje normal (gratis, texto libre) o como plantilla (con costo y texto
  fijo), para no gastar plantillas al pedo.
- Como supervisor, quiero que el mensaje enviado por plantilla se vea en el
  hilo, no que desaparezca en un log.

---

## Alcance

### Dentro

- `sendTemplate()` en el contrato `Gateway` + default que lanza en `BaseGateway`.
- Implementación en `CloudApiGateway`.
- DTO `OutboundTemplate` (nombre, idioma, componentes, header document).
- Tipo de mensaje `template` en `whatsapp_messages` y su render en el hilo.
- `DocumentDispatcher`: cuando la ventana está cerrada, encolar por plantilla en
  vez de rechazar.
- Configuración de la plantilla (nombre, idioma, mapeo de variables).
- Tests con Graph faked.

### Fuera

- Crear / editar / sincronizar plantillas desde el CRM (se hacen en WhatsApp
  Manager).
- Plantillas para otros usos (recordatorios, marketing, seguimiento).
- Soporte de plantillas en Kommo o Messenger.
- Botones interactivos (quick reply / CTA) en la plantilla.

---

## Stack

Sin dependencias nuevas. Laravel 10, Krayin (Concord), Guzzle vía
`Illuminate\Support\Facades\Http`, Pest para tests.

Meta Graph API, versión tomada de `config('whatsapp.gateways.cloud_api.api_version')`
(hoy `v21.0`).

---

## Comandos

```bash
# Tests de la feature
php artisan test --filter=WhatsappTemplate

# Suite completa
php artisan test

# Estilo (obligatorio antes de commitear)
./vendor/bin/pint

# Verificar que la ruta y el config quedaron bien registrados
php artisan route:list --name=quotes
php artisan tinker --execute='echo json_encode(config("whatsapp.templates"));'
```

> El worker de la cola `whatsapp` vive en supervisord dentro del contenedor.
> **Reiniciarlo en el deploy** o los jobs corren con el código viejo.

---

## Estructura

Archivos nuevos y tocados, todos dentro de módulos existentes:

```
packages/Webkul/Whatsapp/src/
  Gateways/
    Contracts/Gateway.php              → + sendTemplate()
    BaseGateway.php                    → + default que lanza UnsupportedMessageException
    CloudApi/CloudApiGateway.php       → implementación real
    Dto/OutboundTemplate.php           → NUEVO
  Jobs/SendMessage.php                 → + rama payload['template'] (NO por type)
  Services/DocumentDispatcher.php      → decide media vs template según ventana
  Config/whatsapp.php                  → + bloque 'templates'

  (sin migración y sin tocar Capabilities: la fila sigue siendo type=document)

packages/Webkul/Admin/src/
  Http/Controllers/Quote/QuoteController.php  → deja de rechazar por ventana
  Resources/lang/{es,en}/app.php              → textos

tests/Feature/WhatsappQuoteTemplateTest.php   → NUEVO
```

---

## Estilo

Se sigue el estilo del módulo tal como está. Un DTO nuevo se ve así - inmutable,
`final`, constructor promotion, y **comentarios que explican el porqué, no el
qué**:

```php
<?php

namespace Webkul\Whatsapp\Gateways\Dto;

/**
 * Una plantilla HSM aprobada, lista para enviar.
 *
 * El header de tipo `document` es la única forma de adjuntar un archivo a una
 * plantilla: el PDF no puede ir en el body. Meta exige que el media ya esté
 * subido, así que `headerMediaId` se resuelve antes de construir el DTO.
 */
final class OutboundTemplate
{
    /**
     * @param  string  $name      Nombre exacto aprobado en WhatsApp Manager.
     * @param  string  $language  Código de idioma de la plantilla ('es', 'es_MX'…).
     *                            Tiene que coincidir con el aprobado o Meta
     *                            responde 132001.
     * @param  array<int, string>  $bodyParams  Valores posicionales de {{1}}, {{2}}…
     */
    public function __construct(
        public string $name,
        public string $language,
        public array $bodyParams = [],
        public ?string $headerMediaId = null,
        public ?string $headerFilename = null,
    ) {}
}
```

Reglas que ya rigen en el repo y siguen rigiendo acá:

- Pint (preset Laravel) pasa sin cambios.
- Nada de queries Eloquent directas en controladores - van por repositorio o
  servicio.
- Los mensajes al usuario salen por `trans()`, nunca hardcodeados.
- Los errores que ve el vendedor son **frases accionables en español**, no el
  error crudo de Graph.

---

## Estrategia de tests

Pest. Feature tests en `tests/Feature/`, siguiendo el molde de
`tests/Feature/WhatsappOutboundMediaTest.php`: **Graph siempre faked, ningún
test toca Meta.**

Archivo: `tests/Feature/WhatsappQuoteTemplateTest.php`

| # | Caso | Qué prueba |
|---|---|---|
| 1 | Sube el PDF y referencia el media id en el header | Orden de las dos llamadas a Graph, igual que `sendMedia` |
| 2 | Payload con `type: template`, name e idioma correctos | Forma exacta del JSON contra Graph |
| 3 | Los `bodyParams` salen en orden posicional | Que `{{1}}`/`{{2}}` no se crucen |
| 4 | Ventana **abierta** → se manda `document` texto libre | Que el texto siga siendo editable y sin depender de la aprobación |
| 5 | Ventana **cerrada** → se manda plantilla | El caso que motiva todo esto |
| 6 | Plantilla mal configurada (nombre vacío) → 422 con frase clara | Que no explote con un 500 |
| 6b | Ningún parámetro del body sale vacío | Meta rechaza param vacío con 132000 |
| 7 | `KommoGateway::sendTemplate()` lanza `UnsupportedMessageException` | Que el default de `BaseGateway` cubra los otros canales |
| 8 | Graph responde 132001 → fila queda `failed` con el error visible | Ya lo hace `SendMessage`; confirmar que aplica al tipo nuevo |

Sin objetivo de cobertura numérico: el módulo no lo tiene hoy y no lo voy a
inventar. El criterio es que **cada rama de decisión de `DocumentDispatcher`
tenga un test**.

---

## Límites

**Siempre:**
- Correr `php artisan test` y `./vendor/bin/pint` antes de commitear.
- Fakear Graph en todos los tests.
- Mantener el mensaje en el hilo (`whatsapp_messages`) para que el hilo y el
  drawer no se enteren de que el envío fue distinto.
- Devolver 422 con frase accionable en español ante cualquier rechazo.

**Preguntar primero:**
- Cambios de esquema (`whatsapp_messages`) - ver Pregunta Abierta #4.
- Tocar `Capabilities::MEDIA_TYPES` o el contrato `Gateway` de forma que rompa
  Messenger o Kommo.
- Cualquier cosa que mande un mensaje real a un número real.
- Agregar dependencias.

**Nunca:**
- Crear o editar plantillas en Meta desde el CRM.
- Mandar la plantilla cuando la ventana está **abierta** (es pagar de más por
  algo que sale gratis y con texto libre).
- Poner el token de Meta en el repo, en un test o en un log.
- Enviar a un contacto que no pidió la cotización (convierte la Utility en
  Marketing de hecho y quema la calidad del número).

---

## Criterios de éxito

Concretos y verificables:

1. Con la ventana **cerrada**, `POST admin/quotes/{id}/whatsapp` responde 200 y
   crea una fila `whatsapp_messages` con `type = 'document'`, `status = 'queued'`
   y `payload['template']` poblado.
2. Con la ventana **abierta**, la misma ruta crea la misma fila **sin**
   `payload['template']` (comportamiento actual, sin regresión).
2b. En ambos casos el flash le dice al vendedor cuál de los dos ocurrió.
3. El worker manda a Graph un payload `type: template` con el PDF como header
   `document` y el media id de la subida previa.
4. El mensaje aparece en el hilo del inbox y en la pestaña "Docs" del drawer.
5. Si la plantilla no está configurada, el vendedor lee una frase que le dice
   qué falta - no un 500 ni un "Server Error".
6. Los 8 tests de la tabla pasan; `php artisan test` completo sigue en verde.
7. `./vendor/bin/pint --test` pasa.
8. Kommo y Messenger siguen funcionando igual (sus tests actuales en verde).

---

## Decisiones tomadas

Las seis preguntas abiertas quedaron resueltas el 2026-08-21.

### 1. La plantilla no existe - se crea con este diseño

A dar de alta en WhatsApp Manager antes de poder verificar de punta a punta:

| Campo | Valor |
|---|---|
| Nombre | `cotizacion_documento` |
| Idioma | `es` |
| Categoría | **Utility** |
| Header | `DOCUMENT` (el PDF) |
| Botones | ninguno |

Body **finalmente aprobado** (2 variables, no 4 - el diseño original se redujo
durante la implementación):

```
Te enviamos la cotización {{1}}.
Total: {{2}}

Cualquier consulta, respondé por acá.
```

| Var | Valor | Fallback |
|---|---|---|
| `{{1}}` | `quote->subject` (en esta instalación es la empresa) | `#`.id |
| `{{2}}` | `core()->formatBasePrice($quote->grand_total)` | nunca vacío |

Meta valida la **cantidad** de parámetros: mandar 4 contra una plantilla de 2
devuelve 132000 y descarta el mensaje entero, sin decir cuántos esperaba.

Tono deliberadamente transaccional: sin emojis de promo, sin urgencia, sin
"aprovechá". Cualquiera de esas palabras hace que Meta la reclasifique a
Marketing en la aprobación.

**Restricción dura:** Meta rechaza un parámetro vacío (error 132000). Ningún
`{{n}}` puede resolverse a string vacío - toda variable necesita un fallback.
Para `{{4}}` cuando `expired_at` es null: `"sin fecha de vencimiento"`.

### 2. Fallback automático, con aviso

El vendedor aprieta un solo botón y el sistema decide:

| Ventana | Se manda | Costo |
|---|---|---|
| Abierta | `document` texto libre | sin costo |
| Cerrada | plantilla `cotizacion_documento` | se cobra como Utility |

El flash distingue los dos casos, para que gastar una plantilla nunca sea
silencioso.

**Por qué texto libre con la ventana abierta, si la plantilla Utility dentro de
la ventana tampoco se cobra:** el costo no es la razón.

1. El body de la plantilla es texto fijo; el texto libre deja que el vendedor
   agregue contexto.
2. No depende de la aprobación: si Meta pausa la plantilla por calidad, el
   envío dentro de la ventana sigue funcionando.
3. Si Meta reclasificara la plantilla a Marketing, pasaría a cobrarse **también**
   dentro de la ventana.

> Verificar las tarifas vigentes en WhatsApp Manager antes de presupuestar.

### 3. Configuración en `.env` + `config/whatsapp.php`

```php
'templates' => [
    'quote' => [
        'name'     => env('WHATSAPP_TEMPLATE_QUOTE', 'cotizacion_documento'),
        'language' => env('WHATSAPP_TEMPLATE_QUOTE_LANG', 'es'),
    ],
],
```

Si más adelante el equipo comercial necesita cambiar el texto seguido, se migra
a System Config. Hoy no justifica el trabajo.

### 4. NO se agrega un tipo de mensaje nuevo - decisión clave

La fila se guarda con **`type = 'document'`, igual que hoy**. Los datos de la
plantilla viven en `payload['template']`, y **solo `SendMessage` decide** a qué
método del gateway llamar.

Verificado en el código, no asumido:

- `inbox-component.blade.php:408` - un tipo desconocido cae en
  `<div v-else>Tipo no soportado</div>`. No rompe el render (eso se arregló
  después del bug del sticker), pero el vendedor vería ese texto en vez del PDF.
- `ChatController.php:273` - el drawer filtra `whereIn('type', ['image','document'])`.
  Un tipo nuevo **desaparece de la pestaña Docs en silencio**, sin ningún error.

Consecuencias de la decisión: cero cambios en el front, cero migración, cero
riesgo de conversación rota. El `type` es contrato con el front; cómo se
entregó el mensaje es transporte. `payload` ya se usa exactamente para eso
(`media_disk_path`).

### 5. Variables del body: confirmadas

| Var | Valor | Fallback si falta |
|---|---|---|
| `{{1}}` | Nombre del contacto | `"Hola"` sin nombre no sirve → `"¿qué tal?"` (ver Preguntas de implementación) |
| `{{2}}` | `quote->subject` | `"#".$quote->id` |
| `{{3}}` | `core()->formatBasePrice($quote->grand_total)` | nunca vacío (formatBasePrice castea null a 0) |
| `{{4}}` | `quote->expired_at` formateada | `"sin fecha de vencimiento"` |

### 6. Contacto que nunca escribió: se permite, con log

`last_inbound_at IS NULL` es exactamente el caso que la plantilla existe para
resolver. Se permite el envío, **pero se loguea** con nivel `info` marcando que
fue a un contacto sin historial - es el escenario que Meta penaliza si se hace
en volumen, y queremos poder auditarlo si la calidad del número cae.

No se agrega un bloqueo por código: el criterio de "¿este cliente pidió la
cotización?" lo tiene el vendedor, no el sistema.

## Riesgos

| Riesgo | Impacto | Mitigación |
|---|---|---|
| Meta reclasifica la plantilla a Marketing por el texto | Costo alto + opt-out + límites por usuario | Redactar el body en tono transaccional; revisar la categoría asignada después de la aprobación |
| El idioma configurado no coincide con el aprobado | Error 132001, mensaje perdido | Test #6 + frase clara; documentar el par nombre/idioma igual que se hizo con `phone_number_id`/`waba_id` |
| Un parámetro del body resuelve a string vacío | Meta rechaza con 132000, mensaje perdido | Fallback obligatorio por variable + test 6b |
| Fallback automático dispara plantillas sin que nadie lo note | Costo silencioso | Aviso explícito en el flash + log del envío por plantilla |
| El worker corre con código viejo tras el deploy | "No hace nada" inexplicable | Reiniciar supervisord en el deploy (ya documentado) |

---

## Fuera de esta spec pero relacionado

- El caption actual (`admin::app.quotes.index.whatsapp.caption`) y el body de la
  plantilla van a decir casi lo mismo. Conviene que **el texto viva en un solo
  lugar** una vez que la plantilla exista, o van a divergir.
