# Llevar la mejora móvil de Imprimir al CRM de Odontoking

Fecha: 23/10/2026. Origen: rama `ui-mobile` del CRM de Imprimir (15 commits).
Destino: CRM de **Odontoking**, rama **`feature/dropbox-fixes`** del repo
`Jalx1000/fobo-crms` (la que corre en `odontoking.sofopolis.com`).

La misma mejora ya se portó a **Sensia** (rama `sensia`, del 21 al 22/10). Ese port es
una segunda referencia: tiene las adaptaciones hechas y **corrige errores del original**
que conviene no repetir (ver [Lo que aprendimos en Sensia](#lo-que-aprendimos-en-sensia)).

---

## Planeación

### Punto de partida

- **`feature/dropbox-fixes` no comparte historia con Imprimir ni con Sensia** (arranca de
  otro commit inicial; solo comparte con `main`). No hay ancestro común: **no se puede
  hacer merge**, y un `cherry-pick` va a dar muchos conflictos. Cada commit se lee con
  `git show` y se porta a mano.
- De los **104 archivos** que toca la mejora (sin contar el build), en Odontoking faltan 12:
  - **6 los crea la propia mejora** (se agregan): `components/datagrid/mobile-card`,
    `components/layouts/bottom-nav`, `components/layouts/volver`, y los tres
    `filtros-movil` (pedidos, cotizaciones, personas).
  - **6 son de Imprimir y no existen en Odontoking** (no se portan): `leads/index/platform-filter`,
    `settings/equipo-ventas/index`, `dashboard/index/distribucion-tiempo-respuesta`,
    `dashboard/index/leads-por-ciudad-pie`, `dashboard/index/quantity-products-pie`,
    `dashboard/index/quantity-products-sold-pie`.
- **Piezas de Imprimir que Odontoking no tiene** (todo lo que dependa de ellas queda afuera,
  sin inventar equivalentes):
  - `Webkul\Admin\Helpers\FiltroEjecutivo` (filtro «Ejecutivo comercial»).
  - `Webkul\Admin\Helpers\Alcance`.
  - `Webkul\Whatsapp\Support\ContactChannel` (filtro «Plataforma»).
  - `Webkul\Product\Support\ListaPrecios` y la lista de precios por variante.
  - `Stage::TONOS` (colores por etapa en el kanban).
  - Las columnas medida, COD TR4, código de barra y unidad de la cotización.
  - `PersonController::syncGlobalFilters` y la tarjeta `contacts/persons/filters`.

### Lo propio de Odontoking (cambia cómo se adapta)

| Tema | Imprimir / Sensia | Odontoking |
|---|---|---|
| Filtro de fechas | Enlaces por URL (`date_filter`, `start`/`end`) | Componente **`components/global-date-range`** (cookie `global_date_range`), en Pedidos, Personas y Tablero; el kanban tiene su propio rango (`applied.dateFrom`/`dateTo`, 7/30/90 días, este mes) |
| Filtros del tablero | Ciudad, ejecutivo, período, rango, exportar | **Solo rango de fechas** (`filters.start`/`end`) |
| Bandeja de WhatsApp | Canal, asesor, etapa (Sensia: ciudad y etapa) | **Solo etapa** |
| Kanban | Imprimir: colores por etapa | Sin colores; sin vuelta atrás si el servidor rechaza el cambio de etapa |
| Tabla de productos de la cotización | Con medida, COD TR4, variantes | Columnas estándar: producto, cantidad, precio, monto, descuento, impuesto, total |
| Menú de primer nivel | — | Tablero, Pedidos, Cotizaciones, Correo, Actividades, Contactos, Productos, **Doctores**, **WhatsApp**, Configuración |
| Build del admin | Versionado en `public/admin/build` | **No versionado**: el deploy corre `npm install && npm run build` |

### Qué se lleva y qué no

| # | Commit | Qué hace | Para Odontoking |
|---|--------|----------|-----------------|
| 1 | `fad31bb` | Barra inferior (4 módulos + «Más»), viewport, Bandeja de WhatsApp a pantalla completa con filtros plegables | **Portar.** La Bandeja solo tiene filtro de etapa: se pliega solo esa fila (el contador cuenta solo la etapa). Ajustar los módulos de la barra (ver Fase 1). |
| 2 | `cfe6989` | Listados como tarjetas (`mobile-card`) y encabezados que no se salen | **Portar** + la corrección de «Editar» (ver [Lo que aprendimos](#lo-que-aprendimos-en-sensia)). Sin `equipo-ventas`. |
| 3 | `bfd35ee` | Kanban: una etapa por pantalla, pestañas de etapas | **Portar** sin `stage.tono` ni `tonoEtiqueta`. |
| 4 | `216c9d8` | Tablero en una columna | **Portar** (sin la tarjeta de tiempo de respuesta). |
| 5 | `eb4ceb5` | «Guardar» siempre a la vista en formularios | **Portar** el encabezado fijo; **no** el ancho de la columna de producto (no hay «Medida»). |
| 6 | `86c65cf` | Pedidos: de borde a borde, panel «Filtros», botón «Mover» de etapa | **Adaptar:** el panel lleva el **rango del kanban** (o `global-date-range`) y la vista; sin ciudad, ejecutivo ni plataforma. «Mover» va tal cual. |
| 7 | `f6c880f` | Crear/editar/ver/lista de Pedidos con «←» (`layouts/volver`) | **Adaptar:** sin el cambio de `LeadDataGrid` (plataforma) ni su test. |
| 8 | `e83c0a6` | Bug: buscar en las listas borraba el campo y cerraba el teclado. Panel derecho de «ver pedido» | **Portar.** Es un bug de Krayin y Odontoking lo tiene. |
| 9 | `60d6173` | Barra de la lista igual a la del kanban (`datagrid-toolbar`) | **Portar.** El estilo de la tabla de Pedidos va junto con el #7. |
| 10 | `6b93ccc` | Cotizaciones: buscador + «Filtros», de borde a borde, «←» | **Adaptar:** sin ejecutivo ni plataforma el panel queda vacío → **sin panel**: el botón de filtros de la tabla abre directo los filtros por columna. Sin cambio de `QuoteDataGrid` ni test. |
| 11 | `4c75f7d` | Tablero: «Filtros» en panel lateral, tarjetas de borde a borde, donas de 240 px | **Adaptar:** el panel solo lleva el rango de fechas (y exportar, si el tablero lo tiene). Sin ejecutivo. |
| 12 | `6cc5746` | Tabla de productos de la cotización legible y en tarjetas en el celular | **Adaptar** a las 7 columnas estándar (ver [Lo que aprendimos](#lo-que-aprendimos-en-sensia)). |
| 13 | `9c54bbc` | Precio desde la lista de precios | **No**, salvo la regla `decimal:N` de `vee-validate.js` (solo aceptaba 1 decimal). |
| 14 | `3b28571` | Personas: «Filtros» en panel, de borde a borde, «←», datos de la ficha apilados | **Adaptar:** el panel lleva el `global-date-range` de Personas; sin ejecutivo, plataforma ni ciudad. Sin cambio de `PersonDataGrid` ni test. Portar `components/attributes/view` (datos apilados). |
| 15 | `910e211` | Productos: formularios que entran en la pantalla, «←», historial que no ensancha | **Adaptar:** sin `_precios`/`_variantes`; aplicar el patrón (`min-w-0`, borde a borde, «←») y la corrección del historial. |

**Doctores (módulo propio de Odontoking):** la mejora de Imprimir no lo toca. Conviene
revisarlo a 390 px al final y aplicarle los mismos patrones si se sale de la pantalla,
como fase aparte.

### Fases

1. **Preparación:** traer las referencias, crear la rama `odontoking-ui-mobile`, compilar y
   sacar capturas «antes» a 390 px y 1440 px.
2. **Base compartida (1, 2, 8, 9, regla decimal):** barra inferior, tarjetas, buscador sin
   perder el foco, clases marcadoras.
3. **Pedidos (3, 6, 7).**
4. **Cotizaciones (5, 10, 12).**
5. **Personas (14)** y **Productos (15).**
6. **Tablero (4, 11).**
7. **Doctores** (revisión y patrones, si hace falta).
8. **Cierre:** tests, capturas «después», revisión con el usuario y despliegue solo con su
   aprobación.

Cada fase termina con: compilación, capturas a 390 y 1440, medición de desbordes, tests de
los módulos tocados y un commit propio.

---

## Prompt para el equipo de Odontoking

> Copiar desde aquí.

Vas a llevar al CRM de **Odontoking** la mejora de la interfaz móvil que se hizo en el CRM
de Imprimir. Son Krayin (Laravel 10, Vue 3 en x-templates de Blade, Tailwind 3 con el corte
`max-lg:` en 1024 px). Respondé y escribí siempre en español.

### Dónde

- Repo `Jalx1000/fobo-crms`, rama **`feature/dropbox-fixes`** (la que corre en producción).
- 🔴 **No trabajes en la carpeta que monta el contenedor** de Odontoking
  (`/etc/easypanel/projects/heaven/odontoking-crm/code`): es la que sirve producción.
  Cambiar de rama ahí cambia lo que ven los usuarios. Usá un clon o un `git worktree` aparte.
- `tessa` es `feature/dropbox-fixes` + 3 commits de Tessa Spa (otro cliente): no es la base.
- Referencias:

  ```bash
  # La rama de trabajo
  git fetch origin feature/dropbox-fixes sensia
  git checkout -b odontoking-ui-mobile origin/feature/dropbox-fixes

  # El original de Imprimir (sin pasar por GitHub)
  git fetch /etc/easypanel/projects/heaven/imprimir_laravel/code ui-mobile:refs/remotes/imprimir/ui-mobile
  git log --oneline fad31bb^..imprimir/ui-mobile        # los 15 commits

  # El port a Sensia (adaptaciones y correcciones ya hechas)
  git log --oneline a8db115^..576f1c7                    # en origin/sensia
  ```

- Leé cada commit con `git show <sha>` antes de portarlo. Los mensajes y comentarios
  explican el porqué. Para cada uno, mirá también **su versión en Sensia**: muestra cómo
  quedó sin las piezas de Imprimir.

### Regla principal: portar, no fusionar

`feature/dropbox-fixes` **no tiene historia en común** con Imprimir ni con Sensia: no hay
merge posible, y un `cherry-pick` mete código que no corresponde o choca. Se porta **a
mano**, archivo por archivo. Lo que dependa de piezas que no existen en Odontoking queda
afuera, sin inventar equivalentes:

- `FiltroEjecutivo`, `Alcance`, `ContactChannel`, `ListaPrecios`, `Stage::TONOS`.
- Las vistas `leads/index/platform-filter`, `leads/index/ejecutivo-filter`,
  `settings/equipo-ventas` y las tortas del tablero que Odontoking no tiene.
- Las columnas medida, COD TR4, código de barra y unidad de la cotización.

Antes de portar un commit, verificá con `ls` o `grep` que existe lo que toca.

### Qué portar, en este orden

Un commit tuyo por fase, con mensaje en español que diga qué ve el usuario.

1. **Base:**
   - `fad31bb`: barra inferior y Bandeja.
     - La barra lee `config('admin.mobile_nav')` (default en el propio
       `bottom-nav.blade.php`). Para Odontoking, proponer
       `['whatsapp', 'leads', 'contacts', 'activities']` y **confirmarlo con la clínica**
       (si la agenda está en `doctors`, va ese en lugar de `activities`). «Más» abre el menú
       completo. El menú ya filtra por permisos.
     - La Bandeja de Odontoking solo filtra por etapa: plegá esa fila detrás del botón y
       que el contador cuente solo la etapa.
   - `cfe6989`: tarjetas `components/datagrid/mobile-card` y encabezados con
     `max-lg:flex-wrap`. **Con la corrección de «Editar»** (ver abajo).
   - `e83c0a6`: el buscador no se destruye mientras carga, y las pestañas de actividades.
   - `60d6173`: clase `datagrid-toolbar`.
   - La regla `decimal` de `assets/js/plugins/vee-validate.js` (de `9c54bbc`): que respete
     `decimal:N`.
2. **Pedidos:** `bfd35ee`, `86c65cf` y `f6c880f`.
   - Kanban sin colores por etapa.
   - El panel `leads/index/filtros-movil` lleva **los controles de fecha que ya tiene
     Odontoking** (el rango del kanban de `kanban/toolbar.blade.php`, o `global-date-range`
     en la lista) y la vista kanban/lista. No lleva ciudad, ejecutivo ni plataforma. En el
     celular, esconder esos controles de la barra con `max-lg:hidden lg:contents`.
   - «Mover» de etapa va tal cual: usa el mismo `handleUpdate` que el arrastre.
   - No toques `LeadDataGrid`.
3. **Cotizaciones:** `eb4ceb5`, `6b93ccc` y `6cc5746`.
   - Lista: de borde a borde y la barra compacta; **sin panel lateral** (quedaría vacío):
     el botón de filtros de la tabla abre los filtros por columna.
   - Productos de la cotización: tarjeta de dos columnas en el celular sobre las **7
     columnas estándar**, con `data-etiqueta` en cada celda. **No** cambiar los campos
     `inline` por inputs comunes sin verificar el envío (ver abajo).
4. **Personas:** `3b28571`.
   - El panel lleva el `global-date-range` que ya está en `contacts/persons/index`.
   - El cambio de `components/attributes/view.blade.php` (datos apilados) sí se porta.
5. **Productos:** `910e211`. `min-w-0` en las columnas, de borde a borde y «←». La
   corrección del historial en `components/activities/index.blade.php`.
6. **Tablero:** `216c9d8` y `4c75f7d`, adaptados al `v-dashboard-filters` de Odontoking
   (solo fechas).
7. **Doctores:** revisar a 390 px y aplicar los patrones si se sale de la pantalla.

### Patrones (lo que hay que reproducir)

- **Solo celular.** Todo cambio va con `max-lg:` (o `lg:hidden`). En la computadora nada
  cambia, y hay que comprobarlo con capturas a 1440 px.
- **De borde a borde:**
  - encabezado: `max-lg:-mx-3 max-lg:-mt-3 max-lg:rounded-none max-lg:border-x-0 max-lg:border-t-0 max-lg:px-3`;
  - tarjetas: `max-lg:-mx-3 max-lg:rounded-none max-lg:border-x-0`;
  - entre bloques: `max-lg:gap-0`.
- **«←» al listado:** `<x-admin::layouts.volver listado="ruta.index" />` al lado del título.
- **Lista:** arriba solo el buscador y un botón «Filtros» (`x-admin::drawer position="right"`)
  con contador de filtros activos; los controles de escritorio se esconden con
  `<div class="max-lg:hidden lg:contents">`.
- **Columnas flex con tablas anchas:** `min-w-0`.
- **Textos largos sin espacios:** `[overflow-wrap:anywhere]`.
- **Dentro de x-templates:** `@lang` no funciona dentro de atributos de un componente
  Blade (usar `{{ trans('…') }}`); `document` no existe en las expresiones de Vue (usar
  `$event.target.ownerDocument`); el contenido de un slot de `x-admin::datagrid` se compila
  en el scope de la tabla.
- **Traducciones:** claves nuevas en los idiomas de
  `packages/Webkul/Admin/src/Resources/lang/*/app.php` (por lo menos `es` y `en`).

### Cómo verificar cada fase

1. **Compilar** como lo hace el deploy (`npm install && npm run build`) y confirmar que se
   regeneran los assets del admin; si no, `cd packages/Webkul/Admin && npx vite build`.
   Después `php artisan view:clear`. **No commitear el build**: en Odontoking no está
   versionado.
2. Que **todas las vistas tocadas compilen** (compilar cada Blade y pasarle `php -l`).
3. Capturas con Playwright a 390×844 (`isMobile`, `hasTouch`) y a 1440×900: lista, crear,
   editar y ver de cada módulo tocado, con una sesión de administrador temporal que se borra
   al terminar.
4. **Sin desborde:** en el celular, `document.documentElement.scrollWidth === innerWidth`.
5. En la computadora: misma captura que antes del cambio.
6. Probar de verdad lo que se toca: elegir un filtro y comparar el total; en un formulario,
   interceptar el envío con Playwright (`page.route`, abortando todo lo que no sea GET) y
   comprobar que se mandan los mismos campos. **No guardar datos reales.**
7. Tests de los módulos tocados.

### Reglas

- Seguí el `CLAUDE.md` del repo.
- No toques `.env`.
- Commits chicos, uno por fase, terminados en
  `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- **No hagas push a `feature/dropbox-fixes` ni despliegue sin aprobación del usuario.**
  Trabajá en `odontoking-ui-mobile`.
- La mejora no trae migraciones. Si una fase pareciera necesitar una, pará y preguntá.
- Al terminar cada fase, reportá qué cambió para el usuario, qué quedó afuera, el
  resultado de los tests y las rutas de las capturas.

> Hasta aquí el prompt.

---

## Lo que aprendimos en Sensia

Errores que trae el original de Imprimir, o que aparecieron al portarlo. Corregirlos en
Odontoking desde el principio:

1. **«Editar» en la tarjeta del celular mostraba JSON** (bug del original, `cfe6989`). La
   tarjeta compartida emite la acción genérica, que para «editar» navega a la URL; en las
   pantallas que editan en un **modal**, esa URL devuelve JSON. Afecta a Usuarios, Grupos,
   Orígenes, Etiquetas, Tipos, Correo, Campañas y Eventos. Corrección:

   ```blade
   <v-datagrid-mobile-card
       ...
       @action="accion => accion.index === 'edit' ? editModal(accion.url) : performAction(accion)"
   ></v-datagrid-mobile-card>
   ```

   (Con la variante de cada pantalla: `selectedType = true, editModal(...)` en Tipos y
   Orígenes, `selectedGroup` en Grupos, `selectedTag` en Etiquetas, `editModal(accion)` en
   Correo, `actionType = 'edit', edit(record)` en Campañas y Eventos.) Ver commit `3558d04`
   de Sensia.
2. **Una barra fija propia queda tapada por la barra inferior.** En Sensia pasó con la barra
   «Total / Crear pedido» del Punto: hay que subirla con
   `max-lg:bottom-[calc(56px+env(safe-area-inset-bottom))]` y agregar margen abajo a la
   página. Revisar si Odontoking tiene alguna (`grep -rn "fixed.*bottom-0"`).
3. **El commit de la barra de la lista (`60d6173`) depende del de la lista de Pedidos
   (`f6c880f`)**: aplicar la clase `datagrid-toolbar` en la fase 1 y el estilo de la tabla de
   Pedidos recién con `f6c880f`.
4. **«Guardar» fijo (`eb4ceb5`)** trae además el ancho de la columna de producto pensado
   para «Medida»; en una cotización con columnas estándar no va.
5. **Productos de la cotización (`6cc5746`)**: el original cambia los campos `inline` por
   inputs comunes, y eso cambia lo que se envía al guardar. En Sensia se portó solo lo
   visual (tarjetas en el celular). Si se cambian los campos, verificar el envío
   interceptándolo antes de commitear.
6. **Hilo de WhatsApp desordenado o con mensajes duplicados** (no es de la mejora móvil,
   pero aparece en la Bandeja y Odontoking tiene el mismo código): el navegador agregaba al
   final lo que traía cada poll, sin ordenar, y si el poll traía el mensaje real antes de
   que respondiera el envío, quedaba duplicado. Corrección en Sensia: commit `ce067db`
   (`guardarMensaje()` / `ordenarMensajes()` en `inbox-component.blade.php`). Conviene
   portarlo junto con la Bandeja.

---

## A revisar fuera de la mejora

- El deploy de EasyPanel de Odontoking hace `git pull origin master`, pero la carpeta del
  contenedor está en `feature/dropbox-fixes`. Confirmar de qué rama se despliega antes de
  publicar la mejora.
- El último cambio de `feature/dropbox-fixes` (`a6d037d`, 15/10, «dockerfile») es un commit
  grande (347 archivos) que incluye los flujos de WhatsApp de Odontoking (contexto de
  paciente, vínculo con SMD, recepción, números del personal). La mejora móvil toca
  `whatsapp/chat.blade.php` e `inbox-component.blade.php`: portar sobre esa versión sin
  pisar esos flujos.
