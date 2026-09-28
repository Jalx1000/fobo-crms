# Todo: Cotización con formato RG-VCO-005

**Spec:** `docs/specs/cotizacion-rg-vco-005-spec.md` · **Plan:** `tasks/plan-cotizacion-rg-vco-005.md`

Cada tarea: ≤ 5 archivos, una sesión, criterio de aceptación y verificación.
Correr **solo** las suites tocadas. Nunca la suite completa.

---

## Fase A · Datos

- [x] **1. Atributos de cotización + validez 10 días** ✅ 2026-09-11
  - Acceptance: el formulario de cotización muestra Forma de pago (select), Lugar de entrega (select), Tiempo de entrega, Orden de compra, Sucursal (select), Observaciones. `expired_at` arranca en hoy + 10.
  - Verify: `SELECT code FROM kl_attributes WHERE entity_type='quotes'` lista los 6; abrir alta de cotización y ver los campos; test de `create.blade` con `expired_at` = +10.
  - Files: `database/sql/imprimir/03-cotizacion-atributos.sql`, `99-rollback-cotizacion.sql`, `quotes/create.blade.php`, `Product/Config/cotizacion.php` (nuevo, con `validez_dias`), `tests/Feature/Quote/AtributosCotizacionTest.php`

- [x] **2. Producto: columnas de entrega** ✅ 2026-09-11
  - Acceptance: `products` tiene `tiempo_entrega` (default Inmediata), `lugar_entrega` (default Puesto almacén cliente), `entrega_domicilio_desde` (nullable). Fillable. Editables en el formulario de producto.
  - Verify: migración up/down limpia; guardar un producto con `entrega_domicilio_desde=50` y releerlo.
  - Files: migración, `Product/Models/Product.php`, `products/_entrega.blade.php` (nuevo), `ProductController.php`, test

- [x] **3. Variantes: tabla, modelo, resolución** ✅ 2026-09-11
  - Acceptance: `product_variantes` con `valores` JSON, `codigo_tr4`, `codigo_barra`, `medida`, `unidad`, `nombre_erp`, `orden`. `products.ejes_identidad` JSON. `Variantes::resolver($product, $criterios)` devuelve la variante mirando **solo** los ejes de identidad, con la misma tolerancia que `ListaPrecios`; null si no hay.
  - Verify: tests — resuelve por Tamaño ignorando Canal/Zona; acentos/mayúsculas; sin variante → null; producto sin `ejes_identidad` → null.
  - Files: 2 migraciones, `Models/ProductVariante.php` (+Proxy, Contract), `Repositories/ProductVarianteRepository.php`, `Support/Variantes.php`, `tests/Feature/Product/VariantesTest.php`

- [x] **4. Formulario de variantes en el producto** ✅ 2026-09-11
  - Acceptance: sección "Códigos por variante" con los ejes de identidad (chips, subconjunto de `ejes_precio`) y una tabla: valores de eje · TR4 · barra · medida · unidad · nombre ERP. Server-side render + JS delegado, marcador `variantes_enviadas` (mismo patrón anti-borrado que precios).
  - Verify: guardar 5 variantes de bolsas desde el form y releer; editar solo el nombre del producto no las borra; test estructural de `<script>`.
  - Files: `products/_variantes.blade.php`, `create.blade.php`, `edit.blade.php`, `ProductController.php`, `Support/Variantes.php` (normalizar), test

- [x] **5. Seeder: variantes de bolsas, entrega de los 4, mínimo a packs** ✅ 2026-09-11
  - Acceptance: las 5 variantes de Magia Verde con TR4/barra/medida/nombre ERP del PDF real; hules (7), stretch (2) y tapas (2) creadas **sin TR4**; `tiempo/lugar/domicilio_desde` por producto según la tabla del spec; mínimo de bolsas = "10 packs"; tapas impresas `tiempo_entrega = 20 días`.
  - Verify: correr el seeder dos veces → mismos conteos; `precio_producto` para bolsas devuelve `minimo: "10 packs"`.
  - Files: `ProductosImprimirSeeder.php`, test de idempotencia

> Ajuste en la 5: tapas necesita tiempo de entrega **por variante** (con
> impresión = 20 días). Se agregó `product_variantes.tiempo_entrega` como
> override nullable. Registrado en el spec.

## Fase B · Lógica

- [x] **6. Reglas de entrega** ✅ 2026-09-11
  - Acceptance: `Entrega::paraItem($product, $cantidad)` → `[tiempo, lugar]` aplicando `entrega_domicilio_desde`. `Entrega::paraCotizacion(array $items)` → peor tiempo + lugar más restrictivo. `precio_producto` incluye `entrega: {tiempo, lugar}` cuando hay cantidad.
  - Verify: tests con los 7 casos de la tabla del spec; 60 rollos de stretch → A domicilio; tapas impresas + bolsas → 20 días · Recoge en planta.
  - Files: `Support/Entrega.php`, `AgenteController.php` (precio), `tests/Feature/Product/EntregaTest.php`

- [x] **7. Ítem de cotización = foto de la variante** ✅ 2026-09-11
  - Acceptance: `quote_items` gana `codigo_tr4`, `codigo_barra`, `medida`, `unidad`, `variante` (JSON). `QuoteItemRepository::create/update` las copia desde la variante resuelta si el ítem trae `criterios`/`variante`; si el form manda los códigos a mano, manda lo del form. Borrar el producto no las toca.
  - Verify: crear ítem con `{Tamaño: 50 L}` → `codigo_tr4 = 020117-0035`; editar el producto después → el ítem no cambia; ítem con códigos manuales → se respetan.
  - Files: migración, `Quote/Models/QuoteItem.php`, `QuoteItemRepository.php`, `tests/Feature/Quote/ItemVarianteTest.php`

## Fase C · Pantalla

- [x] **8. Cotización en pantalla: columnas de ítem + condiciones propuestas** ✅ 2026-09-11
  - Acceptance: en la fila de ítem se ven y editan medida, TR4, barra y unidad; al elegir producto + variante se completan solos. Al crear, `tiempo_entrega` y `lugar_entrega` se proponen desde los ítems; al editar no se pisan.
  - Verify: crear cotización de tapas impresas + bolsas en pantalla → condiciones propuestas correctas; cambiarlas y guardar → quedan las editadas; test de vista.
  - Files: `quotes/create.blade.php`, `quotes/edit.blade.php`, `QuoteController.php`, `Support/Entrega.php`, test

> Hallazgos de la 8: (a) los códigos van DENTRO de la celda del producto —la
> tabla ya tenía 8 columnas—; (b) un atributo `select` guarda el ID de la
> opción, no el texto: se agregó `Support/Opciones::nombre()` que el PDF va a
> necesitar para `lugar_entrega`, `forma_pago` y `sucursal`.

## Fase D · Salida

- [x] **9. API del agente: `cotizacion` copia la variante y las condiciones** ✅ 2026-09-11
  - Acceptance: `POST conversations/{id}/cotizacion` deja el ítem con la foto de la variante y la cotización con tiempo/lugar propuestos y `forma_pago = Al contado`. La respuesta incluye `entrega`.
  - Verify: test end-to-end por HTTP; el ítem tiene TR4; respuesta trae `entrega.lugar`.
  - Files: `AgenteController.php`, `tests/Feature/Product/CotizacionAgenteTest.php`

- [x] **10. PDF RG-VCO-005** ✅ 2026-09-11 · verificado rasterizando y comparando contra la propuesta real
  - Acceptance: plantilla nueva con cabecera (fecha, cliente, razón social, NIT, sucursal, orden de compra), tabla de 9 columnas, monto aproximado/descuento/total, bloque de condiciones (tiempo, forma de pago, validez, lugar), texto legal desde config, firmas (reubicar el bloque existente), observaciones, código de documento desde config. Logo y color por marca como hoy.
  - Verify: test de presencia de cada elemento **y rasterizar con `gs` y comparar contra `docs/crm/Propuesta…pdf`**. Con y sin firma; con y sin códigos; 1 ítem y 6 ítems (¿cabe en una página?).
  - Files: `quotes/pdf.blade.php`, `Product/Config/cotizacion.php`, `lang/es/app.php`, `tests/Feature/Quote/PdfFirmasTest.php` (extender)

> Pedidos extra del 11/09, ya hechos: se quitó la sección de dirección (del
> formulario Y del PDF), se agregó `razon_social` como atributo de cotización, y
> los códigos pasaron de la celda del producto a COLUMNAS de la tabla.
>
> Hallazgo de la 10: con nueve columnas, el padding general de 18px del PDF
> desbordaba la hoja y dompdf **cortaba la última columna (Monto) sin avisar**.
> La tabla de ítems lleva ahora su propio espaciado.

## Fase E · Agente

- [ ] **11. Documento y prompt para el agente**
  - Acceptance: `docs/agent/cotizacion-rg-vco-005.md` con: qué devuelve ahora `precio_producto` (entrega), qué copia `crear_cotizacion`, y el párrafo de prompt para decir plazos de entrega sin inventarlos. `system-prompt-imprimir.md` actualizado.
  - Verify: revisión de lectura; escenario de prueba de 60 rollos de stretch film.
  - Files: 2 docs

---

## Sucursales y responsables (14/09)

- [x] **Las 3 sucursales** cargadas en **Warehouse** (Configuración → Almacenes),
      con `linea` y `maps_url` como atributos: Imprimir 1 (Embalajes),
      Imprimir 2 (Tapas), Imprimir 3 (Envases Flexibles). Las tres en Santa Cruz.
- [x] **`products.warehouse_id`** — desde qué sucursal se despacha. Distinta de
      la relación de inventario, que dice dónde hay stock.
- [x] **Matriz de responsables** (`producto_responsables`): producto × ciudad →
      asesor, editable desde el formulario del producto.
- [x] **El agente recibe la sucursal** con su enlace de Maps en `entrega.sucursal`,
      solo cuando el lugar es «Recoge en planta».
- [x] **Correo y dirección dejan de ser obligatorios** en el PASO 4 del prompt.
      El CRM ya los aceptaba vacíos; verificado con un test.

> Decisión: las sucursales son almacenes del módulo Warehouse y no una tabla
> propia. Una segunda lista de lo mismo quedaría desactualizada apenas alguien
> cargue una sucursal desde Configuración.

## Abierto (no bloquea)

- Días de crédito reales para `forma_pago` (placeholder: 15 y 30).
- TR4 y barra de hules, stretch y tapas → completar en el formulario del paso 4 cuando lleguen.
- Tapas impresas: ¿20 días hábiles o corridos?
- **Qué sucursal fabrica bolsas, hules y stretch film.** Solo Tapas quedó
  asignada (nombre coincidente y único que se retira en planta). Los otros tres
  se asignan desde el formulario del producto cuando el negocio confirme.
- Direcciones escritas y teléfonos de las tres sucursales: hoy solo está el
  enlace de Maps.
- **Cargar los responsables por ciudad y producto**: la matriz está vacía.
