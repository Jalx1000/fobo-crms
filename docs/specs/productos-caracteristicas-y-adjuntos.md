# Spec: características personalizadas y adjuntos para Productos

- **Estado:** ✅ **implementado** el 2026-09-08 · 33 tests en verde, 0 fallos nuevos
- **Fecha:** 2026-09-08
- **Instancia:** `https://imprimir.sofopolis.com` · rama `imprimir-modulo-whatsapp`
- **Origen:** portar dos patrones de `Webkul/Inmobiliaria` (proyecto `c21_laravel`) a `Webkul/Product`

---

## Objective

Que un producto del CRM de imprimir pueda registrar **características propias de su categoría**
y **archivos adjuntos** (imágenes, documentos y enlaces), y que el asesor pueda **enviarle esa
ficha al cliente desde el inbox de WhatsApp**.

Hoy un producto tiene 5 campos nativos (`sku`, `name`, `description`, `quantity`, `price`) más
atributos de Krayin que son iguales para todos los productos. No hay galería: la pestaña
"Archivos" que existe es de *actividades*, no del producto.

**Éxito:** el asesor carga una ficha de producto con sus fotos, su PDF y su enlace, y desde una
conversación de WhatsApp se la manda al cliente en dos clics.

---

## Assumptions

> Corregime si alguna está mal — sobre estas se construye todo lo demás.

1. **La "categoría" de un producto es el atributo `product_type`** (`multiselect`, "Categoría").
   Hoy tiene **una sola opción cargada: "División de Embalajes"**. El mapa de características
   por categoría arranca prácticamente vacío y se llena a medida que se creen categorías.
2. **Es un `multiselect`**: un producto puede tener más de una categoría. Cuando eso pase, el
   formulario ofrece la **unión** de las características de todas sus categorías.
3. Los adjuntos son del **producto**, no del lead. Vincular producto↔lead ya lo resuelve Krayin.
4. Los archivos van al **disco privado** (`local`), servidos por una ruta autenticada — igual que
   c21. Ver Decisión 3.
5. Esto **sí toca código** (a diferencia de `crm-inmobiliario-c21.md`, que fue solo datos).
6. No se toca `Webkul/Whatsapp` salvo para *consumir* lo que ya expone. Ver Decisión 4.

---

## Contexto: qué se porta de c21 y qué no

`c21_laravel` tiene `Webkul/Inmobiliaria` (48 archivos) con las dos piezas que queremos:

| Pieza de c21 | Qué hace | Cómo se porta |
|---|---|---|
| Columna JSON `caracteristicas` en `inmuebles` | Campos que varían por tipo de inmueble | → columna `caracteristicas` en `products` |
| `config/inmuebles.php` → `caracteristicas_por_tipo` | Declara qué campos ofrece cada tipo | → `Product/src/Config/caracteristicas.php`, por categoría |
| Tabla `inmueble_medias` | `foto\|video\|plano\|tour`, con `path` **o** `url` | → tabla `product_medias`, con `imagen\|documento\|enlace` |
| `InmuebleMediaController` | Subida por tandas, portada, orden, borrado | → `ProductMediaController`, misma forma |
| `_dropzone` / `_galeria` (429 líneas Blade) | UI de carga y galería | → adaptado a los componentes `admin::` de imprimir |
| `GaleriaInboxController` | Enviar la galería desde el inbox | → `ProductoInboxController` (en Admin, no en Whatsapp) |

**No se porta:** la tabla `inmuebles` (usamos `products`), `inmueble_lead` (Krayin ya vincula
producto↔lead), la API del agente de IA, ni nada de handoff.

---

## Decisiones de diseño

### D1 — Características: config, no UI de admin

Un archivo PHP declara qué campos ofrece cada categoría; agregar uno es **una línea, sin
migración**. Es lo que ya funciona en c21, y el autor dejó escrito el motivo: sin una lista
declarada, dos personas cargando el mismo dato escriben `altura`, `altura_m` y `alto`, y después
no hay forma de compararlos.

```php
// packages/Webkul/Product/src/Config/caracteristicas.php
return [
    'por_categoria' => [
        'division-de-embalajes' => [
            'material'       => ['label' => 'Material'],
            'gramaje'        => ['label' => 'Gramaje', 'sufijo' => 'g/m²'],
            'medidas'        => ['label' => 'Medidas (largo × ancho × alto)'],
            'troquelado'     => ['label' => '¿Troquelado?', 'tipo' => 'boolean'],
            'acabados'       => ['label' => 'Acabados', 'tipo' => 'lista'],
        ],
    ],

    // Se ofrecen en toda categoría, incluso en las que no tienen mapa propio.
    'comunes' => [
        'tiempo_produccion' => ['label' => 'Tiempo de producción'],
        'minimo_pedido'     => ['label' => 'Pedido mínimo'],
    ],
];
```

Tipos soportados, igual que c21: texto (default), `boolean`, `lista` (valores separados por coma).
El formulario también acepta **claves libres**, para no bloquear al asesor cuando aparece un dato
que nadie previó — la config guía, no encierra.

Una categoría sin entrada en `por_categoria` ofrece solo las `comunes` más las claves libres.

### D2 — Tres tipos de adjunto, no cuatro

c21 usa `foto|video|plano|tour`. Para imprenta se colapsa a:

| tipo | Guarda | Se envía por WhatsApp como | Límite |
|---|---|---|---|
| `imagen` | `path` | `image` | 5 MB · jpg, png, webp |
| `documento` | `path` | `document` | 25 MB · pdf, jpg, png, webp |
| `enlace` | `url` | texto con la URL | — |

Los límites de carga son **más altos** que los de envío a propósito: `whatsapp.media` admite
5 MB en imagen y 100 MB en documento, pero una imagen que entra al CRM no siempre se manda por
WhatsApp. El envío valida por segunda vez contra `whatsapp.media` y avisa si un archivo no pasa.

**Sin `video`** en esta fase: nadie lo pidió y arrastra los 200 MB de c21. Agregarlo después es
sumar un valor al enum.

### D3 — Disco privado, ruta autenticada

Los archivos van a `Storage::disk('local')`, en `productos/{id}/`, y se sirven por
`admin.products.medias.archivo` detrás del middleware `user`. Es lo que hace c21, y el motivo
está escrito ahí: *"la galería no debe ser visible para cualquiera que adivine la URL"*.

> ⚠️ **Roce con lo que ya existe.** `OutboundAttachment::stageContents()` escribe en
> `Storage::disk('public')` porque el hilo de WhatsApp necesita una URL que Meta pueda leer. Al
> enviar un adjunto de producto se **copia** del disco privado al staging público del módulo de
> WhatsApp, que ya tiene su propio ciclo de vida. No se cambia ese comportamiento.

### D4 — El inbox: el módulo de dominio conoce a WhatsApp, no al revés

`GaleriaInboxController` **vive en `Inmobiliaria`, no en `Whatsapp`**. El autor lo dejó
explicado:

> *"El inbox lo pide aparte en vez de que el módulo de WhatsApp lo incluya en su payload: ese
> módulo lo comparte otro tenant que no tiene catálogo inmobiliario, y no debe saber que los
> inmuebles existen."*

Ese "otro tenant" es **este proyecto**. Los dos módulos WhatsApp ya divergieron (c21 tiene
`Handoff` como tabla y modelo; imprimir lo resuelve con columnas en `conversations` más un
servicio; c21 tiene `OutboundDelivery`, imprimir tiene `OutboundAttachment` + `TemplateCatalog` +
`DocumentDispatcher`). Meter productos dentro de `Webkul/Whatsapp` rompería la portabilidad que
c21 cuidó a propósito y complicaría cualquier merge futuro entre ambos.

→ El endpoint de envío vive en **Admin** (`Http/Controllers/Products/ProductoInboxController`) y
consume `OutboundAttachment`. `Webkul/Whatsapp` no gana una sola referencia nueva a productos.

> 🔎 **Corrección hallada al implementar.** El acoplamiento **ya existía antes de este trabajo**:
> `Whatsapp/src/Http/Controllers/ChatController.php` importa `Webkul\Product\Models\ProductProxy`
> para la pestaña «Productos» del panel lateral y para las variables `{{producto}}` / `{{precio}}`
> de las respuestas rápidas (viene del commit `53bbee2`). La separación que describe c21 no está
> intacta en este proyecto.
>
> Se decidió **no tocarlo**: limpiarlo es un refactor con su propio riesgo y no era el pedido. Lo
> que sí se hizo es fijarlo con dos tests — uno afirma que las clases nuevas no aparecen en
> `Whatsapp`, y otro deja constancia del acoplamiento previo para que, si alguien lo limpia, el
> test falle y obligue a actualizar esta nota en vez de dejarla mintiendo.

---

## Tech Stack

Krayin 2.x sobre Laravel 10 · MySQL 9 (`kohlberg`, prefijo `kl_`) · Blade + Tailwind con
componentes `admin::` · Vite. Sin dependencias nuevas de composer ni de npm.

---

## Commands

```bash
composer install && npm install
npm run dev                  # desarrollo
npm run build                # assets de producción
php artisan migrate          # las 2 migraciones nuevas
php artisan test             # Pest
./vendor/bin/pest --filter="ProductMedia"
./vendor/bin/pint            # estilo antes de commitear

# La app corre en contenedor: artisan vive en /code
docker exec -w /code $(docker ps --format '{{.Names}}' | grep '^heaven_imprimir_laravel\.') php artisan migrate
```

---

## Project Structure

```
packages/Webkul/Product/src/
  Config/caracteristicas.php                      ← NUEVO · mapa por categoría
  Database/Migrations/
    ..._add_caracteristicas_to_products_table.php ← NUEVO
    ..._create_product_medias_table.php           ← NUEVO
  Models/ProductMedia.php  ProductMediaProxy.php  ← NUEVO
  Contracts/ProductMedia.php                      ← NUEVO
  Repositories/ProductMediaRepository.php         ← NUEVO
  Models/Product.php                              ← + relación medias(), cast JSON
  Providers/ModuleServiceProvider.php             ← + registro del modelo

packages/Webkul/Admin/src/
  Http/Controllers/Products/
    ProductMediaController.php                    ← NUEVO · CRUD de adjuntos
    ProductoInboxController.php                   ← NUEVO · envío desde el inbox
  Http/Requests/ProductMediaRequest.php           ← NUEVO
  Routes/Admin/products-routes.php                ← + rutas de medias
  Resources/views/products/
    _caracteristicas.blade.php                    ← NUEVO
    _dropzone.blade.php  _galeria.blade.php       ← NUEVO
    create.blade.php  edit.blade.php  view.blade.php ← + los includes
  Resources/lang/es/app.php                       ← + cadenas

tests/Feature/Product/
  CaracteristicasTest.php  ProductMediaTest.php  ProductoInboxTest.php ← NUEVO
```

## Code Style

Se sigue lo que ya hay en el repo: repositorios de `Webkul\Core\Eloquent\Repository`, contratos +
proxies de Concord, strings por `@lang('admin::…')`, Tailwind con `dark:`. Los comentarios
explican **por qué**, no qué — como los de c21.

```php
/**
 * Los límites van por tipo: una imagen de 25 MB es un error de carga, pero un
 * PDF de 25 MB es un pliego normal. Un solo tope obligaría a elegir entre
 * rechazar pliegos legítimos o aceptar imágenes sin comprimir.
 */
protected const REGLAS = [
    'imagen'    => 'mimes:jpg,jpeg,png,webp|max:5120',
    'documento' => 'mimes:pdf,jpg,jpeg,png,webp|max:25600',
    'enlace'    => '',
];
```

## Testing Strategy

Pest, en `tests/Feature/Product/`. Cobertura mínima:

**Características**
- Un producto guarda y recupera `caracteristicas`
- Una categoría sin mapa propio ofrece solo las comunes
- Un producto con dos categorías ofrece la unión de ambas (assumption 2)
- Una clave libre se guarda igual que una declarada
- Claves vacías se descartan; no se guarda `{"": ""}`

**Adjuntos**
- Sube 3 imágenes en una tanda y quedan con orden 1, 2, 3
- Una segunda tanda continúa el orden, no lo reinicia
- Un `enlace` exige `url` y rechaza `archivos`
- Un `documento` de 30 MB se rechaza; uno de 20 MB pasa
- Marcar portada desmarca la anterior
- Borrar un adjunto borra el archivo del disco
- La ruta del archivo **404 sin sesión** (D3)

**Inbox**
- Enviar una imagen de producto crea el mensaje en la conversación
- Un adjunto que supera `whatsapp.media` se rechaza con un error claro
- Un `enlace` se envía como texto
- La ventana de 24 h cerrada bloquea el envío (`MessagingWindowClosedException`)

Verificación manual: cargar un producto con 3 imágenes + 1 PDF + 1 enlace, reordenar, marcar
portada, abrir una conversación y enviarlo.

## Boundaries

**Siempre:** migraciones reversibles con `down()` · `./vendor/bin/pint` antes de commitear ·
strings por `@lang` · adjuntos en disco privado · validar el tipo contra `whatsapp.media` antes
de enviar.

**Preguntar primero:** tocar cualquier archivo de `packages/Webkul/Whatsapp` · cambiar el schema
de `products` más allá de la columna nueva · agregar dependencias · modificar `OutboundAttachment`.

**Nunca:** meter referencias a productos dentro de `Webkul/Whatsapp` (D4) · guardar adjuntos en
el disco público · romper la pestaña "Archivos" de actividades que ya existe · tocar los 15
productos actuales.

---

## Success Criteria

1. `products` tiene la columna `caracteristicas` (JSON, nullable) y la migración revierte limpio.
2. Existe `product_medias` con `tipo` enum `imagen|documento|enlace`, `path`, `url`, `orden`,
   `es_portada`, FK a `products` con `ON DELETE CASCADE`. **Sin `caption`**: se quitó a pedido
   (una descripción por archivo tiene sentido en fotos de inmuebles, no en productos de imprenta).
3. El formulario de producto ofrece los campos declarados para su categoría, más los comunes, más
   filas libres; un producto con dos categorías ve la unión.
4. La galería permite subir por tandas, reordenar, marcar portada y borrar; el archivo se borra
   del disco al borrar el registro.
5. La ruta del archivo devuelve 404 sin sesión autenticada.
6. Desde una conversación del inbox, el asesor manda una imagen, un documento y un enlace de un
   producto, y los tres aparecen en el hilo.
7. `Webkul/Whatsapp` no gana acoplamiento **nuevo**: ni `ProductMedia`, ni `ProductoInbox`, ni
   `Support\Caracteristicas` aparecen ahí. El `ProductProxy` previo de `ChatController` queda
   como estaba, cubierto por un test que lo documenta.
8. `php artisan test` en verde y `./vendor/bin/pint --test` limpio.
9. **Regresión:** los 15 productos actuales se abren, editan y guardan sin cambios; la pestaña
   "Archivos" de actividades sigue funcionando.

---

## Open Questions

**Q1 y Q2 — siguen abiertas y son las únicas que bloquean algo. ⚠️**
`config/caracteristicas.php` tiene el mapa de `division-de-embalajes` cargado con cinco campos
**inventados como ejemplo** (material, gramaje, medidas, troquelado, acabados), marcados como
PROVISIONAL en el propio archivo. Todo lo demás funciona; falta que el negocio diga qué se carga
de verdad y cuáles son las otras categorías. Cambiarlo es editar esas líneas: no hay migración ni
datos que migrar mientras nadie haya cargado características todavía.

**Q3 — resuelto:** el enlace se envía como mensaje de texto (`caption` + URL en líneas separadas),
que es lo único que hace que WhatsApp arme el preview.

**Q4 — resuelto por ahora:** `product_image` queda intacto. Con galería propia está duplicado,
pero migrar de uno al otro es un trabajo aparte y no se decidió en esta fase.
