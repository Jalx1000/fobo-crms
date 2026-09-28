# Spec: Cotización con formato RG-VCO-005

**Estado:** especificación · pendiente de aprobación
**Fecha:** 2026-09-11
**Análisis previo:** `docs/specs/cotizacion-formato-rg-vco-005.md`
**Fuentes:** `docs/crm/` (notas de reunión 11/09 + propuesta real a Grupo Quiroz)

---

## Objetivo

Que la cotización que sale del CRM sea **la misma que Imprimir emite hoy a mano**
(formato RG-VCO-005), con los datos que su ERP y su cliente esperan: código TR4
y código de barra por línea, medida, unidad, condiciones comerciales y firmas.

**Usuarios:**
- El **asesor**, que arma la cotización en pantalla y la manda al cliente.
- El **agente de IA**, que la pre-elabora al cerrar la toma de interés por WhatsApp.
- El **cliente**, que la recibe, la firma y la devuelve (o emite orden de compra).

**Éxito:** un asesor abre una cotización del CRM y no encuentra nada que tenga
que corregir a mano antes de mandarla. Y el PDF que genera el agente es
indistinguible del que genera el asesor.

### Decisiones tomadas (11/09)

| Decisión | Elegido | Consecuencia |
|---|---|---|
| ¿Bolsas en 1 o 5 productos? | **1 producto** | Los códigos son por *variante*, no por producto |
| ¿Qué productos tienen TR4 por variante? | **Todos** (bolsas, hules, stretch, tapas) | El modelo de variantes es general, no un caso especial |
| ¿Dónde se ven los códigos? | **En la cotización** | Cada ítem lleva TR4, barra, medida y unidad, y son **editables** |
| Formas de pago | **Contado + crédito a días** | Select: Al contado · Crédito 15 días · Crédito 30 días |
| Alcance | **Todo** | Datos + reglas de entrega + PDF + agente |

### Supuestos (corregir si alguno está mal)

1. RG-VCO-005 es el **único** formato de cotización; las dos marcas lo usan cambiando logo y color, como hoy.
2. Los códigos TR4 y de barra se **cargan a mano una vez**; el CRM no consulta el ERP.
3. El asesor puede **cambiar el precio** de un ítem (precio negociado). La lista propone, no impone.
4. Las condiciones (tiempo, lugar, forma de pago) se **proponen** desde los ítems y el asesor las edita.
5. Un ítem cuya variante no tenga códigos cargados **se puede cotizar igual**, con los códigos vacíos para completar a mano. No bloquea.
6. Los plazos de crédito son 15 y 30 días **hasta que el negocio diga otros**. Cambiarlos es editar opciones, no código.

---

## Modelo de datos

### Variantes de producto — `product_variantes` (tabla nueva)

Los códigos dependen de **algunos** ejes del producto, no de todos: en bolsas
el TR4 cambia con el tamaño pero **no** con el canal ni la zona. Ponerlos en la
fila de precio los repetiría 10 veces por tamaño. Ponerlos en el producto no
alcanza porque hay cinco. Van en una tabla propia, indexada por los valores de
los **ejes de identidad**.

```
product_variantes
  id
  product_id      FK products ON DELETE CASCADE
  valores         JSON   {"Tamaño": "50 L"}          ← subconjunto de ejes_precio
  codigo_tr4      string 30
  codigo_barra    string 20 nullable                 ← opcional, carga manual
  medida          string 60 nullable                 ← "(65×80) cm"
  unidad          string 10 default 'PZA'
  nombre_erp      string 120 nullable                ← "BSA MULTIUSO MAGIA VERDE 50L 10UN"
  orden
```

El producto declara cuáles de sus ejes identifican la variante:

```
products.ejes_identidad   JSON   ["Tamaño"]      ← bolsas
                                 ["Color"]       ← hules
                                 ["Presentación"]← stretch film
                                 ["Impresión"]   ← tapas
```

**Resolución:** al cotizar `{Tamaño: 50 L, Canal: HORECA, Zona: Santa Cruz}`,
el CRM toma solo los ejes de identidad (`Tamaño`) y busca la variante
`{Tamaño: 50 L}`. Misma comparación tolerante que `ListaPrecios::filtrar()`.

### Producto — columnas nuevas

| Columna | Tipo | Default | Para |
|---|---|---|---|
| `ejes_identidad` | JSON | null | qué ejes identifican la variante |
| `tiempo_entrega` | string 60 | `Inmediata` | "20 días" en tapas impresas |
| `lugar_entrega` | string 60 | `Puesto almacén cliente` | o "Recoge en planta" |
| `entrega_domicilio_desde` | int nullable | null | 50 (stretch), 5 (hules) |

Estos tres últimos son por **producto**, no por variante: la entrega no cambia
con el color del hule.

> **Ajuste 11/09 (tarea 5):** tapas rompe esa regla — sin impresión es
> inmediata, con impresión 20 días. Se agregó `product_variantes.tiempo_entrega`
> nullable como **override**: null usa el del producto. Solo se carga donde
> difiere.

### Ítem de cotización — foto de la variante

| Columna nueva en `quote_items` | Por qué se copia |
|---|---|
| `codigo_tr4` | una cotización emitida no cambia si el producto cambia de código |
| `codigo_barra` | idem |
| `medida` | idem |
| `unidad` | idem |
| `variante` (JSON) | qué variante era: `{"Tamaño":"50 L"}` — para reconstruir |

Mismo principio que `sku`/`name` hoy, y la razón por la que arreglamos el bug
del `findOrFail`: el ítem es una foto, no un puntero.

### Cotización — atributos personalizados (SQL, sin código)

| Código | Tipo | Opciones / default |
|---|---|---|
| `forma_pago` | select | Al contado · Crédito 15 días · Crédito 30 días — default Al contado |
| `lugar_entrega` | select | Puesto almacén cliente · Recoge en planta · A domicilio |
| `tiempo_entrega` | text | propuesto desde los ítems |
| `orden_compra` | text | lo llena el cliente |
| `sucursal` | select | Santa Cruz · La Paz · Cochabamba · Otra — hereda de la persona |
| `observaciones` | textarea | "Escenario #3 compra mínima 3 bultos" |

Validez: `expired_at` con default **10 días** (hoy 7).

### Config

```php
// config/cotizacion.php
'documento' => [
    'codigo'  => 'RG-VCO-005',
    'version' => '00',
    'edicion' => '19/4/2024',
],
'validez_dias' => 10,
'texto_legal'  => 'PARA REALIZAR SU PEDIDO, DEBERÁ EMITIR UNA ORDEN DE COMPRA O DEVOLVER FIRMADA ESTA COTIZACIÓN A IMPRIMIR. …',
```

El código de documento es del sistema de calidad de Imprimir y cambia de
versión; no va hardcodeado en la plantilla.

---

## Reglas de negocio

### Plazos de entrega: por producto Y por cantidad ✅ confirmado 11/09

Los plazos **no son un dato fijo de la cotización**: salen del producto y de
cuánto lleva el cliente, exactamente como están en las notas de la reunión.

```
Producto              Cantidad                 Tiempo       Lugar
────────────────────  ───────────────────────  ───────────  ──────────────────────
Bolsas Magia Verde    ≥ 10 packs (mínimo)      Inmediata    Puesto almacén cliente
Hules                 1–4 rollos               Inmediata    Puesto almacén cliente
Hules                 ≥ 5 rollos               Inmediata    A domicilio
Stretch Film          1–49 rollos              Inmediata    Puesto almacén cliente
Stretch Film          ≥ 50 rollos              Inmediata    A domicilio
Tapas sin impresión   ≥ 1 caja                 Inmediata    Recoge en planta
Tapas con impresión   ≥ 30 cajas (mínimo)      20 días      Recoge en planta
```

Esto se guarda como **datos del producto** (`tiempo_entrega`, `lugar_entrega`,
`entrega_domicilio_desde`), no como reglas en código: cuando cambie el umbral
de stretch film de 50 a 40 rollos, es editar un número en el producto.

Para el ítem: `lugar = cantidad >= entrega_domicilio_desde ? 'A domicilio' : lugar_entrega`.

### Condiciones propuestas para la cotización entera

Una cotización tiene varios ítems y un solo bloque de condiciones. Se combinan:

```
tiempo_entrega  = el PEOR de los ítems         (tapas impresas → 20 días para todo el pedido)
lugar_entrega   = el más restrictivo:
                    algún ítem "Recoge en planta"    → Recoge en planta
                    todos los ítems "A domicilio"    → A domicilio
                    si no                            → Puesto almacén cliente
```

Se aplican al **crear** (pantalla y API del agente). Al editar, no se pisan:
si el asesor las cambió, son suyas.

`precio_producto` devuelve el plazo resuelto para la cantidad pedida, así el
agente puede decir *"desde 50 rollos lo llevamos a domicilio"* sin inventarlo.

### Datos cargados con este spec (del PDF real y la nota B)

**Variantes de Magia Verde:**

| Tamaño | Medida | TR4 | Barra | Nombre ERP |
|---|---|---|---|---|
| 35 L | (60×63) cm | 020117-0044 | 7779970880051 | BSA MULTIUSO MAGIA VERDE 35L 10UN |
| 50 L | (65×80) cm | 020117-0035 | 7779970880037 | BSA MULTIUSO MAGIA VERDE 50L 10UN |
| 75 L | (78×95) cm | 020117-0034 | 7779970880020 | BSA MULTIUSO MAGIA VERDE 75L 10UN |
| 140 L | (90×110) cm | 020117-0033 | 7779970880013 | BSA MULTIUSO MAGIA VERDE 140L 10UN |
| 200 L | (100×120) cm | 020117-0051 | 7779970880068 | BSA MULTIUSO MAGIA VERDE 200L 5UN |

**Entrega por producto:**

| Producto | tiempo | lugar | domicilio desde | mínimo (corrige lo cargado) |
|---|---|---|---|---|
| Bolsas | Inmediata | Puesto almacén cliente | — | **10 packs** |
| Hules | Inmediata | Puesto almacén cliente | 5 rollos | 1 rollo |
| Stretch Film | Inmediata | Puesto almacén cliente | 50 rollos | 1 rollo |
| Tapas sin impresión | Inmediata | Recoge en planta | — | 1 caja |
| Tapas con impresión | **20 días** | Recoge en planta | — | 30 cajas |

Hules, Stretch Film y Tapas: variantes creadas **sin TR4** (a completar cuando
el negocio los pase). El CRM cotiza igual con el código vacío.

---

## Stack

Laravel 10 · Krayin CRM 2.x · Concord · Pest · dompdf (PDF) · MySQL 9 ·
Vue 3 en el admin (formulario de cotización) · Tailwind.

## Comandos

```bash
# Tests — SOLO las suites afectadas, NUNCA la suite completa contra producción
docker exec -w /code <laravel> php artisan test tests/Feature/Quote/ tests/Feature/Product/

# Estilo
./vendor/bin/pint packages/Webkul/Quote packages/Webkul/Product packages/Webkul/Admin/src/Http/Controllers/Quote tests/Feature/Quote

# Migraciones (producción, reversibles)
docker exec -w /code <laravel> php artisan migrate --force

# Ver el PDF de verdad (obligatorio antes de dar por terminado el paso del PDF)
docker exec <laravel> gs -q -dNOPAUSE -dBATCH -sDEVICE=png16m -r80 -sOutputFile=/tmp/cot-%d.png /tmp/cot.pdf

# Atributos y datos
docker exec -i <mysql> mysql -u… < database/sql/imprimir/03-cotizacion-atributos.sql
php artisan db:seed --class=Database\\Seeders\\ProductosImprimirSeeder
```

## Estructura

```
packages/Webkul/Product/src/
  Database/Migrations/2026_09_1x_*                 ← ejes_identidad, entrega, product_variantes
  Models/ProductVariante.php (+Proxy, Contract)
  Repositories/ProductVarianteRepository.php
  Support/Variantes.php                            ← resolver variante desde criterios
  Support/Entrega.php                              ← reglas de tiempo/lugar
  Config/cotizacion.php
  Http/Controllers/Api/AgenteController.php        ← cotizacion() copia la variante
packages/Webkul/Quote/src/
  Database/Migrations/2026_09_1x_add_variante_to_quote_items
  Repositories/QuoteItemRepository.php             ← foto de la variante
packages/Webkul/Admin/src/
  Resources/views/quotes/pdf.blade.php             ← formato RG-VCO-005
  Resources/views/quotes/{create,edit}.blade.php   ← columnas de ítem, condiciones
  Resources/views/products/_variantes.blade.php    ← tabla de códigos por variante
  Http/Controllers/Quote/QuoteController.php       ← proponer condiciones
database/sql/imprimir/03-cotizacion-atributos.sql
database/seeders/ProductosImprimirSeeder.php       ← variantes + entrega + mínimo
docs/agent/…                                       ← prompt y doc para el agente
tests/Feature/Quote/  tests/Feature/Product/
```

## Estilo

Como el resto del proyecto: comentarios en español que explican el **por qué**,
no el qué; nombres en español para lo del dominio; helpers de test **nunca**
con nombres que existan como helpers globales de Krayin (`menu()` nos costó
media suite).

```php
/**
 * La variante de un producto para lo que el cliente eligió.
 *
 * Solo miran los EJES DE IDENTIDAD: en bolsas el TR4 cambia con el tamaño
 * pero no con el canal ni la zona. Filtrar por todos los criterios dejaría
 * sin variante a una combinación que sí la tiene.
 */
public static function resolver($product, array $criterios): ?ProductVariante
```

## Testing

Pest, `tests/Feature/`. Cada test limpia lo que crea; **sin** `RefreshDatabase`.
Cobertura mínima por paso:

- Variantes: resolver por ejes de identidad; ignorar los otros; sin variante → null, no excepción.
- Ítems: la foto sobrevive a que cambie o se borre el producto.
- Condiciones: peor tiempo; lugar más restrictivo; no pisar lo editado.
- PDF: cada columna presente; **y rasterizarlo y mirarlo**.
- Agente: `crear_cotizacion` copia la variante; `precio_producto` devuelve entrega.

## Límites

- **Siempre:** migraciones reversibles; correr solo las suites tocadas; mirar el PDF renderizado; pint antes de dar por hecho.
- **Preguntar antes:** borrar o renombrar productos existentes; cambiar `ejes_precio` de un producto cargado; tocar `vendor/`.
- **Nunca:** correr `php artisan test` completo contra producción; `truncate` en tests; inventar un TR4 o precio que no esté en las fuentes.

---

## Criterios de éxito

1. Una cotización de Magia Verde 50 L HORECA Santa Cruz muestra en su línea: `(65×80) cm · 020117-0035 · 7779970880037 · PZA`, sin que el asesor escriba nada.
2. El asesor puede **editar** esos códigos en el ítem y el PDF muestra lo editado.
3. Un pedido de tapas impresas + bolsas propone `20 días` y `Recoge en planta`; uno de 60 rollos de stretch film propone `A domicilio`.
4. El PDF reproduce RG-VCO-005: cabecera con NIT/sucursal/orden de compra, tabla con las 9 columnas, condiciones, texto legal, firmas, observaciones, código de documento desde config.
5. `crear_cotizacion` del agente produce una cotización cuyo PDF es igual al de una creada en pantalla con los mismos datos.
6. Borrar o renombrar un producto **no** altera los códigos de una cotización ya emitida.
7. El mínimo de bolsas se lee `10 packs` en `precio_producto`.
8. Ningún test corre la suite completa; ninguno hace `truncate`.

## Preguntas abiertas

1. Días de crédito en la forma de pago (¿15 y 30?). Los plazos de **entrega** ya están definidos por producto y cantidad; esto es solo el crédito.
2. TR4 y códigos de barra de hules, stretch film y tapas — se crean las variantes vacías y se completan cuando lleguen.
3. ¿Tapas impresas: 20 días hábiles o corridos?
4. Ubicaciones de almacenes (Mariana): ¿van en la cotización o solo las usa el agente?
5. ¿Quién actualiza el código de documento (RG-VCO-005 v00) cuando cambie de versión?
