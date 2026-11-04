# Llevar la mejora móvil de Imprimir al CRM de Sensia

Fecha: 21/10/2026. Origen: rama `ui-mobile` del CRM de Imprimir (15 commits).
Destino: `/etc/easypanel/projects/heaven/sensia-laravel/code`, rama `sensia`.

---

## Planeación

### Punto de partida

- Los dos CRM son el mismo repositorio (`Jalx1000/fobo-odontoking-crm-V2`). La
  rama `sensia` está en `4402302`, que es un **ancestro** de Imprimir: Imprimir
  salió de ahí y lleva 121 commits propios antes de la mejora móvil.
- Por eso varias piezas que la mejora usa **no existen en Sensia**:
  `FiltroEjecutivo`, `Alcance`, `ContactChannel` (plataforma),
  `ListaPrecios` y la lista de precios por variante, `platform-filter`,
  `ejecutivo-filter` y las columnas medida/COD TR4 de la cotización.
- **No se hace merge ni cherry-pick ciego.** Cada commit se lee y se porta a
  mano, sin las partes que dependen de lo que Sensia no tiene.

### Qué se lleva y qué no

| # | Commit | Qué hace | Para Sensia |
|---|--------|----------|-------------|
| 1 | `fad31bb` | Barra inferior (4 módulos + «Más»), viewport, Bandeja de WhatsApp a pantalla completa con filtros plegables | **Portar.** Ajustar `config('admin.mobile_nav')` a sus módulos. |
| 2 | `cfe6989` | Listados como tarjetas (`mobile-card`) y encabezados que no se salen (54 vistas) | **Portar.** |
| 3 | `bfd35ee` | Kanban: una etapa por pantalla, pestañas de etapas | **Portar.** |
| 4 | `216c9d8` | Tablero en una columna | **Portar.** |
| 5 | `eb4ceb5` | «Guardar» siempre a la vista en formularios | **Portar** (sin la parte de columnas de cotización si no aplica). |
| 6 | `86c65cf` | Pedidos: sin márgenes grises, panel «Filtros», botón «Mover» de etapa | **Adaptar:** el panel sin Ejecutivo ni Plataforma. |
| 7 | `f6c880f` | Crear/editar/ver/lista de Pedidos con «←» (`layouts/volver`) | **Adaptar:** sin el cambio de `LeadDataGrid` (ContactChannel) ni su test. |
| 8 | `e83c0a6` | Bug: buscar en las listas borraba el campo y cerraba el teclado. Panel derecho de «ver pedido» | **Portar.** Es un bug de Krayin y Sensia lo tiene. |
| 9 | `60d6173` | Barra de la lista igual a la del kanban (clase `datagrid-toolbar`) | **Portar.** |
| 10 | `6b93ccc` | Cotizaciones: buscador + «Filtros», de borde a borde, «←» | **Adaptar:** sin Ejecutivo ni Plataforma; el panel queda con «Más filtros (por columna)». Sin cambio de `QuoteDataGrid` ni test. |
| 11 | `4c75f7d` | Tablero: «Filtros» en panel lateral, tarjetas de borde a borde, donas de 240 px | **Adaptar** al `v-dashboard-filters` de Sensia (no tiene selector de ejecutivo). |
| 12 | `6cc5746` | Tabla de productos de la cotización: encabezados legibles, campos visibles, tarjetas en el celular | **Adaptar** a las columnas que tenga Sensia (no tiene medida/COD TR4/cód. barra/unidad). |
| 13 | `9c54bbc` | Precio desde la lista de precios del CRM | **No**, salvo la corrección de la regla `decimal:N` en `vee-validate.js` (solo aceptaba 1 decimal). Esa sí se porta. |
| 14 | `3b28571` | Personas: «Filtros» en panel, de borde a borde, «←», datos de la ficha apilados | **Adaptar:** el panel con Ciudad, Período y Rango (Sensia tiene `syncGlobalFilters`), sin Ejecutivo ni Plataforma. |
| 15 | `910e211` | Productos: formularios que entran en la pantalla, «←», historial que no ensancha | **Adaptar:** Sensia no tiene `_precios`/`_variantes`; aplicar el patrón (min-w-0, de borde a borde, «←») y la corrección del historial. |

### Fases

1. **Preparación:** traer la rama de referencia, crear `sensia-ui-mobile`,
   compilar y sacar capturas «antes» a 390 px y 1440 px.
2. **Base compartida (1, 2, 8, 9, regla decimal):** layout, barra inferior,
   tarjetas, buscador sin perder el foco, clases marcadoras. Todo lo demás
   se apoya en esto.
3. **Pedidos (3, 6, 7):** kanban, lista, crear, editar, ver.
4. **Cotizaciones (5, 10, 12).**
5. **Personas (14)** y **Productos (15).**
6. **Tablero (4, 11).**
7. **Cierre:** tests, capturas «después» en las dos medidas, revisión con el
   usuario y despliegue solo con su aprobación.

Cada fase termina con: compilación, capturas a 390 y 1440, medición de
desbordes, tests de los módulos tocados y un commit propio.

---

## Prompt para el equipo de Sensia

> Copiar desde aquí.

Vas a llevar al CRM de **Sensia** la mejora de la interfaz móvil que se hizo
en el CRM de Imprimir. Los dos son Krayin (Laravel 10, Vue 3 en x-templates de
Blade, Tailwind 3 con el corte `max-lg:` en 1024 px) y comparten repositorio.
Respondé y escribí siempre en español.

### Dónde

- Trabajás en `/etc/easypanel/projects/heaven/sensia-laravel/code`, rama
  `sensia` (árbol limpio, `4402302`).
- La referencia es la rama `ui-mobile` del CRM de Imprimir. Traela sin tocar
  GitHub:

  ```bash
  cd /etc/easypanel/projects/heaven/sensia-laravel/code
  git fetch /etc/easypanel/projects/heaven/imprimir_laravel/code ui-mobile:refs/remotes/imprimir/ui-mobile
  git log --oneline fad31bb^..imprimir/ui-mobile   # los 15 commits
  git checkout -b sensia-ui-mobile sensia
  ```

- Leé cada commit con `git show <sha>` antes de portarlo. Los mensajes y los
  comentarios del código explican el porqué de cada decisión.

### Regla principal: portar, no fusionar

`sensia` es un ancestro de Imprimir: Imprimir tiene 121 commits propios que
Sensia no tiene. **No hagas merge ni cherry-pick ciego** de `ui-mobile`. Lo
que dependa de piezas que no existen en Sensia se deja afuera, sin inventar
equivalentes. Esas piezas son:

- `Webkul\Admin\Helpers\FiltroEjecutivo` (filtro «Ejecutivo comercial»).
- `Webkul\Admin\Helpers\Alcance`.
- `Webkul\Whatsapp\Support\ContactChannel` (filtro «Plataforma»).
- `Webkul\Product\Support\ListaPrecios` y la lista de precios por variante.
- Las vistas `leads/index/platform-filter` y `leads/index/ejecutivo-filter`.
- Las columnas medida, COD TR4, código de barra y unidad de la cotización.

Antes de portar un commit, verificá con `ls` o `grep` que existe lo que toca.
Si un commit trae algo de esa lista, portá solo la parte de interfaz.

### Qué portar, en este orden

Un commit tuyo por fase, con mensaje en español que diga qué ve el usuario.

1. **Base:**
   - `fad31bb`: barra inferior y Bandeja.
     - La barra lee `config('admin.mobile_nav')`. Si una clave no existe en el
       menú de Sensia, completa con los primeros módulos.
     - Revisá qué claves tiene su menú (`whatsapp`, `leads`, `contacts`…; en
       Sensia `quotes` no es un ítem de primer nivel) y elegí las 4 que más
       usan.
   - `cfe6989`: tarjetas `components/datagrid/mobile-card` y encabezados con
     `max-lg:flex-wrap`.
   - `e83c0a6`: el buscador no se destruye mientras carga, más las pestañas de
     actividades.
   - `60d6173`: clase `datagrid-toolbar`.
   - La regla `decimal` de `assets/js/plugins/vee-validate.js` del commit
     `9c54bbc`: que respete `decimal:N`.
2. **Pedidos:** `bfd35ee`, `86c65cf` y `f6c880f`.
   - El panel `leads/index/filtros-movil` va sin las secciones Ejecutivo y
     Plataforma.
   - No toques `LeadDataGrid`.
3. **Cotizaciones:** `eb4ceb5`, `6b93ccc` y `6cc5746`.
   - El panel de la lista solo abre los filtros por columna. Si queda con un
     único botón, podés hacer que «Filtros» abra directo ese panel.
   - En la tabla de productos aplicá el patrón (encabezados cortos y en una
     línea, moneda una vez arriba, inputs reales en vez de `type="inline"`,
     tarjetas con `data-etiqueta` en el celular) sobre las columnas que
     **Sensia** tiene.
4. **Personas:** `3b28571`.
   - El panel lleva Ciudad, Período y Rango (navegan por URL; ya existe
     `PersonController::syncGlobalFilters`).
   - Antes revisá si `contacts/persons/filters.blade.php` está incluida en el
     index de Sensia (en Imprimir sí, en Sensia parece que no).
   - El cambio de `components/attributes/view.blade.php` (datos apilados en el
     celular) también se porta.
5. **Productos:** `910e211`. Sensia no tiene `_precios` ni `_variantes`:
   aplicá `min-w-0` a las columnas, de borde a borde y «←». También la
   corrección del historial en `components/activities/index.blade.php`.
6. **Tablero:** `216c9d8` y `4c75f7d`, adaptados al `v-dashboard-filters` y a
   las tarjetas que tenga el tablero de Sensia.

### Patrones (lo que hay que reproducir)

- **Solo celular.** Todo cambio va con `max-lg:` (o `lg:hidden`). En la
  computadora nada cambia, y hay que comprobarlo con capturas a 1440 px.
- **De borde a borde:**
  - en el encabezado: `max-lg:-mx-3 max-lg:-mt-3 max-lg:rounded-none max-lg:border-x-0 max-lg:border-t-0 max-lg:px-3`;
  - en las tarjetas: `max-lg:-mx-3 max-lg:rounded-none max-lg:border-x-0`;
  - entre bloques: `max-lg:gap-0`.
- **«←» al listado:** `<x-admin::layouts.volver listado="ruta.index" />` al
  lado del título. Vuelve a la lista con sus filtros si se vino de ahí.
- **Lista:**
  - arriba solo el buscador y un botón «Filtros»
    (`x-admin::drawer position="right"`), con contador de filtros activos;
  - la barra se estiliza desde un contenedor con
    `max-lg:[&_.datagrid-toolbar]:…` y `max-lg:[&_.toolbarLeft]:…`;
  - los controles de escritorio se esconden con
    `<div class="max-lg:hidden lg:contents">`.
- **Filtros por URL** (enlaces y `<select onchange="window.location=…">`), como
  los de escritorio. La tabla reenvía los parámetros de la URL en su petición
  AJAX.
- **Columnas flex con tablas anchas:** `min-w-0`. Sin eso la página entera se
  estira al ancho de la tabla.
- **Textos largos sin espacios:** `[overflow-wrap:anywhere]`. Un renglón
  «anterior → nuevo» lleva `flex-wrap` para no partir «Vacío».
- **Dentro de x-templates:**
  - `@lang` no funciona dentro de atributos de un componente Blade: usá
    `{{ trans('…') }}`;
  - `document` no existe en las expresiones de Vue: usá
    `$event.target.ownerDocument`;
  - el contenido de un slot de `x-admin::datagrid` se compila en el scope de
    la tabla, no en el del componente de la página.
- **Traducciones:** claves nuevas en los 7 idiomas de
  `packages/Webkul/Admin/src/Resources/lang/*/app.php` (por lo menos `es` y
  `en`; el resto cae a `en`).

### Cómo verificar cada fase

1. Compilar: `cd packages/Webkul/Admin && npx vite build`, y después
   `php artisan view:clear`.
2. Capturas con Playwright (está en
   `packages/Webkul/Admin/node_modules/playwright`):
   - a 390×844 (`isMobile`, `hasTouch`) y a 1440×900;
   - de lista, crear, editar y ver de cada módulo tocado;
   - con una sesión de administrador temporal que borrás al terminar.
3. Sin desborde: en el celular,
   `document.documentElement.scrollWidth === innerWidth`. Listá los elementos
   cuyo borde derecho pase la pantalla. Solo pueden pasarse las filas que se
   deslizan a propósito (pestañas, tablas dentro de su sección).
4. En la computadora: misma captura que antes del cambio.
5. Probar de verdad lo que se toca:
   - en un panel de filtros, elegir un filtro y comparar el total con la base;
   - en un formulario, interceptar el envío con Playwright (`page.route`,
     abortando todo lo que no sea GET) y comprobar que se mandan los mismos
     campos que antes;
   - **no guardar datos reales.**
6. Tests de los módulos tocados (`php artisan test tests/Feature/<Módulo>`).

### Reglas

- Seguí el `CLAUDE.md` de Sensia y sus reglas de equipo.
- No toques `.env` ni `.env.railway`.
- Pint solo sobre los archivos PHP que tocaste.
- Commits chicos, uno por fase, terminados en
  `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- **No hagas push ni despliegue sin aprobación del usuario.** Sensia se
  despliega en Railway (ver `docker/entrypoint.sh`); confirmá el destino antes.
- Esta mejora no trae migraciones. Si una fase pareciera necesitar una,
  pará y preguntá.
- Al terminar cada fase, reportá:
  - qué cambió para el usuario;
  - qué quedó afuera por depender de piezas de Imprimir;
  - el resultado de los tests;
  - las rutas de las capturas.
