# Spec: selector de plantillas aprobadas en el inbox

> Estado: **IMPLEMENTADA** (2026-08-21). Incluye el envío con archivo en el
> encabezado, que se agregó al alcance tras un 132012 en un envío real.
> Origen: portar a Imprimir lo que Odontoking ya tiene funcionando.
>
> Rama: `imprimir-modulo-whatsapp` · Autor: Javier Mogro / Claude

---

## Contexto: qué tiene cada CRM hoy

Relevado en el código de los dos proyectos, no asumido.

| Pieza | Imprimir | Odontoking |
|---|---|---|
| `sendTemplate()` en el gateway | ✅ recibe `OutboundTemplate` (soporta **PDF en el header**) | ✅ firma vieja: `(templateKey, variables, language)`, solo texto |
| `OutboundTemplate` DTO | ✅ | ❌ |
| `TemplateCatalog` (lee las aprobadas de Meta) | ❌ | ✅ |
| Rutas `GET templates` / `POST send-template` | ❌ | ✅ |
| Selector de plantillas en el inbox | ❌ | ✅ |
| `'template'` en las capabilities de Cloud API | ❌ | ✅ |
| `waba_id` configurado | ❌ **no existe** | ✅ |

Imprimir ya sabe **enviar** una plantilla — lo hace hoy para mandar cotizaciones
fuera de la ventana de 24h — pero solo la que está fijada en
`config('whatsapp.templates.quote')`. No hay forma de que un asesor vea qué
plantillas existen ni de elegir otra.

El caso real que esto desbloquea: la ventana de 24h se cerró, el asesor abre la
conversación, ve el aviso "no podés escribir" y hoy **no tiene salida**. Con el
selector, elige una plantilla aprobada, completa las variables y reabre la
conversación.

---

## Supuestos que estoy haciendo

Corregime ahora o procedo con estos:

1. Se porta **solo el selector** (catálogo + UI + envío desde el inbox). El
   envío de cotización con PDF que ya existe en Imprimir **no se toca**.
2. Solo **Cloud API**. Messenger no tiene plantillas y Kommo las alcanza por
   salesbot: ambos heredan el `throw` de `BaseGateway`, y el botón no se les
   muestra.
3. Las plantillas se crean y aprueban **a mano en WhatsApp Manager**. El CRM
   solo las lee; nunca las crea ni las edita.
4. Meta es la única fuente de verdad: no se copia el catálogo a la base, que
   sería una segunda versión que mantener sincronizada.
5. Solo se muestran las que están en estado **APPROVED**: cualquier otra sería
   rechazada al enviarse.
6. Plantillas con **header de imagen, documento o video sí están soportadas**:
   el panel pide el archivo y lo manda como parámetro del encabezado. (Alcance
   ampliado el 2026-08-21, después de que un envío real devolviera 132012.)
   Las que llevan **variables en un header de texto** siguen fuera.
7. El agente IA no manda plantillas: si está activo en la conversación, el envío
   manual se rechaza igual que hoy.

---

## Objetivo

Que un asesor pueda, desde el inbox, ver las plantillas aprobadas de la cuenta,
completar sus variables con un preview de cómo va a quedar, y enviarlas —
especialmente cuando la ventana de 24h está cerrada y es lo único que WhatsApp
acepta.

---

## Bloqueante: falta el `waba_id`

`Imprimir no tiene el WABA id en ninguna parte` — ni en `config/whatsapp.php` ni
en `.env`. El catálogo se lee de `GET /{waba_id}/message_templates`, así que sin
ese dato **no hay catálogo posible**.

Hay que sacarlo de WhatsApp Manager (Configuración de la cuenta → identificador
de la cuenta de WhatsApp Business) y ponerlo en `WHATSAPP_WABA_ID`. Tiene que
ser el WABA **del mismo número** que ya usa `WHATSAPP_PHONE_NUMBER_ID`: un par
desalineado devuelve `132001` o "Unsupported post request", que es exactamente
el síntoma que ya nos costó tiempo en el otro proyecto.

Es trabajo humano, fuera del repo, y se puede hacer en paralelo: solo bloquea la
prueba end-to-end, no la implementación.

---

## Alcance

### Dentro

1. **`TemplateCatalog`** — consulta Meta, se queda con las APPROVED, aplana los
   componentes de Meta (header / body / footer / buttons) a lo que necesita el
   composer, y cachea (30 min por defecto) con clave por `waba_id`.
2. **Conteo de variables por el placeholder MÁS ALTO**, no por cuántos
   aparecen: un body que usa `{{1}}` y `{{3}}` necesita igual tres valores o
   Meta rechaza el envío.
3. **Rutas** `GET whatsapp/templates` y `POST whatsapp/send-template`.
4. **`'template'` en las capabilities** de `CloudApiGateway`, que es lo que
   decide si el botón aparece.
5. **Selector en `inbox-component.blade.php`** — lo comparten la página de chat
   y el inbox embebido, así que una sola vez cubre las dos vistas.
6. **Validación en el servidor**: cantidad de variables exacta, ninguna vacía,
   plantilla todavía existente, agente IA apagado.
7. **Preview** en el mensaje guardado: el cuerpo con los `{{n}}` ya
   reemplazados, para que el hilo se lea como lo que recibió el cliente.

### Fuera

- Plantillas con variables dentro de un header de TEXTO (ver Supuesto 6).
- Crear, editar o dar de alta plantillas desde el CRM.
- Botones con parámetros (URL dinámica, copy-code).
- Kommo y Messenger.
- Tocar el envío de cotizaciones que ya funciona.

---

## Diferencias al portar (no es copiar y pegar)

El código de Odontoking no corre tal cual en Imprimir. Cuatro adaptaciones:

1. **La firma de `sendTemplate` es distinta.** Odontoking pasa
   `(templateKey, variables, language)`; Imprimir recibe un `OutboundTemplate`.
   El controlador tiene que construir el DTO.
2. **La forma del `payload` del mensaje cambia.** Odontoking guarda
   `['template' => nombre, 'language' => ..., 'variables' => [...]]`;
   `OutboundTemplate::fromArray()` de Imprimir lee `name`, `language` y
   **`body_params`**. Copiar la forma vieja hace que el job envíe una plantilla
   sin nombre — falla en el worker, no en la request.
3. **`GatewayManager` no tiene `outbound()`** en Imprimir; tiene `active()` y
   `for($conversation)`. Se usa `for()`: la capacidad hay que evaluarla contra
   **el canal de esa conversación**, no contra el activo de la instalación, o el
   botón aparecería en un hilo de Messenger.
4. **`api_version`** vive en la raíz de la config en Imprimir, no dentro de
   `gateways.cloud_api`.

---

## Comandos

```bash
php artisan test tests/Feature/WhatsappTemplateCatalogTest.php
./vendor/bin/pest --filter="Template"
./vendor/bin/pint
npm run build
php artisan cache:clear   # el catálogo queda cacheado 30 min
```

---

## Estructura

```
packages/Webkul/Whatsapp/src/
  Services/TemplateCatalog.php                 → nuevo
  Http/Controllers/InboxController.php         → templates() + sendTemplate()
  Routes/admin.php                             → dos rutas
  Gateways/CloudApi/CloudApiGateway.php        → 'template' en capabilities
  Config/whatsapp.php                          → waba_id + templates.cache_minutes
  Resources/views/inbox-component.blade.php    → panel selector
tests/Feature/WhatsappTemplateCatalogTest.php  → nuevo
```

---

## Estilo de código

El del módulo: servicio inyectado, HTTP con `Http::` fake-able en tests, y
comentarios que explican **por qué**.

```php
/**
 * Cuántos parámetros espera el cuerpo.
 *
 * Se cuenta el placeholder MÁS ALTO y no cuántos aparecen: Meta los completa
 * por posición, así que un cuerpo que usa {{1}} y {{3}} necesita igual tres
 * valores o el envío se rechaza entero.
 */
protected function countVariables(string $body): int
```

---

## Estrategia de pruebas

Pest, en `tests/Feature/`, con `Http::fake()` — no se llama a Meta de verdad.

- Una plantilla no APPROVED nunca aparece en el catálogo.
- `{{1}}` + `{{3}}` reporta 3 variables, no 2.
- Falta el `waba_id` → excepción con mensaje accionable, y el inbox responde
  lista vacía + motivo en vez de romperse.
- El payload guardado usa `body_params` (la clave que lee `OutboundTemplate`).
- Cantidad de variables distinta → 422 y **nada** encolado.
- Una variable vacía → 422.
- Conversación con agente IA activo → 422.
- El catálogo se cachea por `waba_id`: cambiar de número no sirve el anterior.

---

## Límites

- **Siempre:** leer el catálogo de Meta, nunca de la base; validar en el
  servidor; degradar a "sin plantillas" en vez de romper el inbox.
- **Preguntar antes:** tocar el envío de cotizaciones existente; agregar soporte
  de header multimedia; cambiar el TTL del cache.
- **Nunca:** crear o editar plantillas desde el CRM; mostrar una no aprobada;
  mandar una plantilla con una variable vacía.

---

## Criterios de éxito

1. Con `WHATSAPP_WABA_ID` puesto, el inbox lista las plantillas aprobadas.
2. El asesor completa las variables, ve el preview y envía.
3. El mensaje aparece en el hilo con el texto ya resuelto y llega al cliente.
4. Funciona con la ventana de 24h **cerrada** — que es el caso que lo justifica.
5. El botón no aparece en conversaciones de Messenger ni de Kommo.
7. Una plantilla con encabezado de archivo pide el adjunto y se envía con él;
   sin adjunto, el botón de enviar queda deshabilitado y nunca se produce un
   132012.
6. Sin `waba_id` o con Meta caída, el inbox sigue andando y explica por qué no
   hay plantillas.

---

## Preguntas abiertas

1. ¿Alguien tiene a mano el WABA id de Imprimir, o hay que pedirlo a quien
   administra la cuenta de Meta?
2. ¿El selector va también en el envío de cotizaciones (elegir plantilla en vez
   de la fija de config), o eso queda como está?
