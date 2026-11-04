# TODO - características y adjuntos para Productos

**Spec:** `docs/specs/productos-caracteristicas-y-adjuntos.md`
**Estado: implementado el 2026-09-08.** 71 tests nuevos en verde · 0 fallos nuevos en la suite.

- [x] **01 · Migraciones**
      `caracteristicas` (JSON, nullable) en `products` · tabla `product_medias`
      (`imagen|documento|enlace`, `path`/`url`, `caption`, `orden`, `es_portada`, FK CASCADE).
      Ambas con `down()`. Aplicadas en la base real.

- [x] **02 · Modelo y repositorio**
      `ProductMedia` + contrato + proxy, registrado en Concord · `ProductMediaRepository`
      (`ultimoOrden`, `marcarPortada`) · `Product` gana cast, fillable y `medias()` ordenada con
      la portada primero.

- [x] **03 · Características por config**
      `Product/src/Config/caracteristicas.php` (comunes + por categoría) ·
      `Product/src/Support/Caracteristicas` (normalizar, paraCategorias, etiqueta) ·
      normalización enchufada en `ProductController::store/update`.

- [x] **04 · Adjuntos: CRUD**
      `ProductMediaController` - subida por tandas (máx. 20), orden que continúa por tipo,
      portada única, borrado que también borra el archivo del disco, y `archivo()` que sirve
      desde el disco privado detrás del middleware `user`.

- [x] **05 · Envío desde el inbox**
      `ProductoInboxController` en **Admin** - `buscar()` (solo productos con adjuntos) y
      `enviar()` (revalida contra `whatsapp.media`, máx. 10 por envío, encadena los jobs para
      preservar el orden, arma la ficha en texto).

- [x] **06b · Drag & drop de subida** (agregado a pedido)
      `_dropzone.blade.php` portado de c21 - soltar archivos sobre la tarjeta los sube directo
      (`data-subir-a`), con miniaturas de vista previa y liberación de las blob URLs.
      `_fotos-al-crear.blade.php` para el alta, donde las imágenes viajan con el formulario
      porque todavía no hay producto al que subirlas · `ProductController::guardarImagenesDelAlta`.

- [x] **06 · Rutas y vistas**
      5 rutas de medias + 2 de inbox · `_caracteristicas.blade.php` (filas clave/valor con
      sugeridos de la config) · `_galeria.blade.php` (subida, portada, arrastre para reordenar,
      borrado) · enchufadas en create (solo características) y edit (ambas) · 27 cadenas nuevas
      en `lang/es`.

- [x] **06c · Sin descripción por archivo** (a pedido)
      Se quitó `caption` del formulario, de la galería, del modelo, de los dos controladores y de
      la tabla (migración `...000003_drop_caption_from_product_medias_table`, reversible).
      Venía de c21, donde una foto de propiedad gana con un pie («Frente», «Living»); acá nadie
      iba a escribir una descripción por imagen de producto. Los mensajes de WhatsApp salen sin
      caption y los errores de validación nombran el tipo en vez del pie.

- [x] **06d · Borde punteado visible** (a pedido)
      El markup ya tenía `border-2 border-dashed`, pero **el CSS del admin estaba compilado de
      antes**: `border-dashed` sobrevivía porque otra vista la usaba, `border-2` no la usaba nadie
      más y quedó fuera del bundle → `border-style: dashed` con ancho 0 px, borde invisible.
      Se recompiló (`npm run build` en `packages/Webkul/Admin`) y se agregó `DropzoneEstilosTest`
      para que un build viejo falle en vez de pasar desapercibido.

- [x] **06e · Chip vacío del multiselect** (a pedido)
      Un producto sin categoría mostraba dos chips: la categoría y otro sin texto.
      `explode(',', '')` devuelve `['']` -array con una cadena vacía, no vacío- y ese `''`
      llegaba como opción seleccionada: se dibujaba como chip y **viajaba en el submit**.
      No era cosmético: taparlo con CSS habría dejado el `''` guardándose.
      Arreglado en las dos capas - `multiselect.blade.php` filtra en origen; el computed `model`
      de `v-multiselect` (en `layouts/index.blade.php`) descarta vacíos también en el caso array,
      que antes solo filtraba el caso string. `checkbox.blade.php` gana el `(string)` que evita
      la deprecation de `explode(null)`. Afecta a **todos** los multiselect del admin, no solo a
      productos.

- [x] **09 · API del agente de IA** (a pedido)
      El agente solo podía mandar texto. Ahora tiene catálogo y envío de material, con la misma
      forma que `EnvioController` de c21: **pide «3 imágenes del SKU X» y el CRM decide cuáles, en
      qué orden y por qué vía; las rutas nunca pasan por el modelo de lenguaje**.
      - `Product/src/Http/Controllers/Api/AgenteController.php` - `catalogo()`, `ficha()`, `media()`
      - `Product/src/Routes/api.php` bajo `api/v1/productos`, `auth:sanctum`, cargado por el
        provider del módulo (igual que Whatsapp carga la suya; Admin solo carga rutas web)
      - `Product/src/Config/adjuntos.php` - 3 por defecto, techo duro de 10
      - `ProductMediaRepository::paraEnviar()` - portada primero, después el orden del asesor
      - Guardas espejo de `AgentController::send`: 409 si hay derivación o la IA está apagada,
        422 fuera de la ventana de 24 h. **Corren antes de resolver el SKU.**
      - `404` y no `200 enviados:0` cuando no hay material: el agente ya dijo «te mando las fotos»
        y necesita corregirse en el mismo turno
      - Sin caption, a propósito: el agente manda su descripción como texto aparte

- [x] **10 · Documentación para el agente**
      ⚠️ **Corregido:** el primer intento documentó la integración para **n8n**. El agente está
      construido en **Python con LangChain 1.0.5 / LangGraph 1.0.2** sobre FastAPI. El contrato
      de la API era correcto; toda la parte de integración estaba equivocada y se reescribió.
      El archivo de n8n se **borró** en vez de dejarlo: una guía errónea es peor que ninguna.

      - `docs/agent/prompt-claude-agente-adjuntos.md` - briefing corto, agnóstico del framework.
      - `docs/agent/langchain-herramientas-material-producto.md` - el completo: los tres
        endpoints con respuestas reales capturadas de la API, tabla de errores con el cuerpo
        exacto, wiring de LangGraph, system prompt listo para pegar y batería de curl.
      - `docs/agent/langchain/crm_productos.py` - implementación de referencia copiable:
        tres `@tool` async con `httpx`, `structlog` y `pydantic-settings`.

      Las dos decisiones que definen esa integración:
      1. **`conversation_id` viaja por `RunnableConfig`, nunca como argumento de la tool.** Si
         se expone en el schema, el modelo inventa un número y el material le llega a otro
         cliente. Queda anotado cómo verificarlo (`args_schema.model_json_schema()`).
      2. **Los errores se devuelven como texto, no como excepción.** Una excepción corta el
         turno y el cliente se queda sin respuesta; lo que hay que hacer es contarle qué pasó.
         Y ninguno se reintenta: un retry sobre el POST duplica el envío.

      Detalle del contrato corregido al documentar: `caracteristicas` devolvía `[]` cuando estaba
      vacío y `{}` cuando tenía datos - dos tipos para el mismo campo. Ahora es siempre objeto,
      fijado por un test que afirma sobre el cuerpo crudo (`->json()` decodifica `{}` a `[]` en
      PHP y taparía el problema).

- [x] **07 · Tests** - 50 nuevos, todos en verde
      `CaracteristicasTest` (9, sin base) · `ProductMediaTest` (10) · `ProductoInboxTest` (7) ·
      `ProductViewsTest` (7, renderiza las páginas) · `DropzoneEstilosTest` (3, CSS compilado) ·
      `MultiselectVacioTest` (3, chip fantasma) · `InteractivoTest` (20, botones y listas) ·
      `AgenteCatalogoTest` (12, API del agente:
      incluye un envío real que lee los bytes del disco y verifica orden, sender=ia y sin caption).
      Baseline de la suite: 16 fallos / 141 pasan → después: 15 / 213. **Cero fallos nuevos.**

- [x] **08 · Pint** - 32 archivos, 1 issue corregido.

---

## Incidentes durante la ejecución

**Tests dejaron 8 productos huérfanos.** La primera corrida falló porque
`ProductRepository::create` exige `entity_type` (el controlador lo inyecta en su constructor,
los tests no). El repositorio inserta el producto y recién después guarda los atributos, así que
la fila quedaba creada y el `afterEach` nunca corría. Se limpiaron los 8 y el `afterEach` de
ambos tests ahora es defensivo (`if (! isset($this->producto)) return;`).

**Dos bugs propios encontrados al portar el dropzone de c21.** Sus comentarios advertían de
ambos y mi primera versión caía en los dos:

1. **CSRF roto.** Yo leía el token de `<meta name="csrf-token">`, que **este admin no tiene**:
   devolvía `null` y portada, borrado y reordenamiento habrían muerto con 419. Ahora va embebido
   con `@json(csrf_token())`, y hay un test que falla si alguien vuelve a leerlo del meta.
2. **Listeners que Vue se llevaba puestos.** Vue monta sobre `#app` *después* de
   `@stack('scripts')` y reemplaza los nodos, matando todo `addEventListener` enganchado
   directamente a un elemento. Todo pasó a delegarse en `document`.

Ninguno de los dos lo habrían detectado los tests de datos: por eso se agregó `ProductViewsTest`,
que pide las páginas y afirma sobre el HTML.

**3. El drag & drop no funcionaba: una directiva Blade dentro de un comentario JS.**
Un comentario decía «...corre después de @ stack('scripts')» (con la directiva real). Blade no
distingue comentarios de código: ejecutó la directiva, volcó el stack en medio del comentario y
**cerró ahí el bloque `<script>`**. Todo el JS del dropzone quedaba fuera de cualquier `<script>`
y nunca se ejecutaba - sin un solo error en consola, porque el navegador veía texto suelto, no
JavaScript roto. Estaba en los tres blades (`_dropzone`, `_galeria`, `_caracteristicas`).

El primer test de regresión que escribí para esto **no servía**: afirmaba que no hubiera
`</script>` *entre* dos marcas del cuerpo, pero el corte ocurre ANTES del código. Se verificó
reintroduciendo el bug a propósito: el test pasaba igual. La afirmación correcta es estructural -
el último `<script` de apertura tiene que venir después del último `</script>` de cierre. Con eso,
el test falla con el bug puesto y pasa con el arreglo (verificado en ambos sentidos).

**4. El borde punteado no se veía por un build de assets viejo.** Ver 06d. Es la tercera vez en
esta tarea que un bug vive fuera del PHP -directiva en un comentario, token en un meta
inexistente, clase sin compilar- y ninguno lo habrían visto los tests de repositorio.

**El acoplamiento Whatsapp→Product ya existía.** Ver la corrección en el spec (§D4): no lo
introdujo este trabajo y se decidió no tocarlo, pero quedó fijado con dos tests.

---

- [x] **11 · Botones y listas interactivas** (a pedido)
      **No se portó de c21: c21 no los tiene.** Sus capabilities son
      `text, reply, emoji, image, video, audio, document, location` - sin `interactive`, y no
      hay un solo `'interactive'` en su PHP. Lo único que toca botones ahí es el parseo del
      `button.text` **entrante** de las quick-reply de plantillas, que es otro mecanismo.
      Se construyó desde cero siguiendo el patrón que el módulo ya tenía para `location`:
      - `Dto/OutboundInteractive.php` - los dos formatos, con **los límites de Meta validados
        acá y no en el gateway**: Meta rechaza un interactivo pasado de largo con un 400
        genérico que no dice qué campo fue.
      - `Contracts/Gateway.php` + `BaseGateway.php` (default que rechaza) → Kommo y Messenger
        no se tocaron y siguen refusando igual que con media.
      - `CloudApiGateway::sendInteractive()` + `'interactive'` en `send` **y** en `receive`.
        La Capabilities se tocó al final, como advierte el comentario del autor: declararla
        antes de que el driver funcione deja un control del inbox conectado a nada.
      - **El camino de vuelta**, que era el hueco que hacía inútil todo lo demás:
        `extractBody` devolvía `null` para `interactive`. Hoy devuelve el título, y
        `AgentPayload` expone `message.selection.{id,title}` para que el agente rutee por id.
      - `POST /api/v1/whatsapp/conversations/{id}/interactive` en `AgentController` - **no** en
        el módulo Product: los botones son una capacidad del canal, no del catálogo.
      - Tool `mostrar_opciones` en LangChain que **elige el formato sola**: ≤3 botones, 4–10
        lista. Dejar esa decisión en el prompt garantiza que el modelo la olvide justo cuando
        tiene 4 productos que ofrecer.

## Nota de despliegue

Los assets del admin se compilan aparte del bundle raíz:

```bash
cd packages/Webkul/Admin && npm run build
```

Cualquier clase de Tailwind **nueva** en un blade del admin no existe hasta correr eso.
`DropzoneEstilosTest` falla si alguien lo olvida.

## Precios por variante (2026-09-09)

Ver `docs/specs/precios-por-variante.md`. Resumen:

- `products.ejes_precio` + tabla `product_prices`: una fila por combinación, con
  `precio` NULLABLE porque hay combinaciones que existen y a propósito no se
  cotizan por chat.
- `POST /api/v1/productos/catalogo/{sku}/precio` responde `resuelto` /
  `ambiguo` / `sin_datos`. **El CRM resuelve el precio, no el modelo.**
- Herramienta `precio_producto` en `crm_productos.py` (5.ª del set).
- Las cuatro líneas de Imprimir cargadas por seeder idempotente.
- **Bug de pérdida de datos encontrado y corregido:** el editor inline de
  atributos postea a `products.update` sin `precios` ni `caracteristicas`.
  Sin marcador de formulario eso borraba ambas cosas en silencio (las
  características ya se borraban desde antes de este cambio).

## Pendiente

- [ ] **Q1/Q2 - las características reales.** `config/caracteristicas.php` tiene el mapa de
      `division-de-embalajes` con cinco campos **inventados**, marcados como PROVISIONAL. Falta
      que el negocio diga qué se carga de verdad y cuáles son las otras categorías.
- [ ] **Botón en el inbox.** El backend del envío está y testeado, pero el composer del inbox
      todavía no tiene el botón que llama a `admin.products.inbox.buscar` / `.enviar`. Es trabajo
      de front sobre `inbox-component.blade.php`. (La vía del **agente** sí está completa: es la
      tarea 09.)
- [ ] **Que n8n implemente las dos herramientas.** El CRM ya expone todo; falta el otro lado.
      El briefing es `docs/agent/prompt-claude-agente-adjuntos.md`.
- [ ] **Verificación manual en el navegador:** crear un producto soltando imágenes en el alta;
      en la edición soltar un PDF con «Documentos» elegido, agregar un enlace, reordenar
      arrastrando y marcar portada. Los tests afirman que el JS llega entero al HTML, pero no
      que el navegador lo ejecute - eso solo se comprueba a mano.
- [ ] Decidir si `product_image` (atributo nativo) se retira ahora que hay galería propia.
- [ ] **Los 15 precios de Bolsas Magia Verde** (5 tamaños × TRADICIONAL/HORECA/EMPRESARIAL)
      y los 2 de Stretch Film. La grilla está armada; falta llenar la columna Precio.
      Mientras tanto el agente deriva, que es el comportamiento correcto.
- [ ] **Escala por volumen.** El documento dice «manejamos escala por volumen», pero el
      modelo de precios no contempla cantidad: `Mínimo` es texto informativo. Si el negocio
      cotiza distinto por volumen hay que decidir entre un tercer eje (Rango) o escalones
      por fila. Hoy el prompt del agente deriva esas consultas.
