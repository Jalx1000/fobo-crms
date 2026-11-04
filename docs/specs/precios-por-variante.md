# Precios por variante

**Estado:** implementado · **Rama:** `imprimir-modulo-whatsapp` · 2026-09-09

## El problema

Tres de las cuatro líneas de Imprimir no tienen *un* precio:

| Producto | El precio depende de | Combinaciones |
|---|---|---|
| Bolsas Magia Verde | Tamaño (35/50/75/140/200 L) × Canal (TRADICIONAL / HORECA / EMPRESARIAL) | 15 |
| Hules | Color (transparente 472 · colores 460 · negro 260) | 7 |
| Tapas | Impresión (sin 927,50 · con 954) | 2 |
| Stretch Film | Presentación (manual 4,7 kg · automático 15,7 kg) | 2 |

Hasta ahora el CRM tenía una sola columna `price`, en 0 para estos productos. El
agente que leyera la ficha ofrecería **bolsas a 0 Bs**. El de arriba es el error
más caro que puede cometer: un precio equivocado dicho con total seguridad.

Y hay combinaciones que **existen y a propósito no se cotizan por chat**: «Para
mi casa» se deriva al supermercado y «Quiero revender» al equipo comercial
(§«Equivalencia de botones a canales de precio» del documento de estructura). Si
esas combinaciones no existieran como dato, el agente diría «no lo vendemos».

## La forma

```
products.ejes_precio   JSON   ["Tamaño", "Canal"]      ← por qué varía
product_prices          fila   {"Tamaño":"50 L","Canal":"HORECA"} → 180 Bs
```

**Una lista de filas y no una columna `precio_por_caracteristica`.** Un solo eje
cubriría Hules y Tapas pero no Bolsas, que es justamente el caso que pidió el
negocio. Un eje o tres, misma estructura.

**Los ejes viven en el producto, no en `config/caracteristicas.php`.** No son una
propiedad de la categoría: dos productos de embalaje pueden cotizarse por ejes
distintos, y el negocio los cambia sin deploy.

**`precio` es NULLABLE.** Es lo que hace que una derivación sea un dato y no un
hueco. La `nota` de esa fila dice qué corresponde hacer.

**Sin bandera `precio_variable` en la tabla.** Se deduce de si hay filas. Una
bandera en `true` sin filas cargadas dejaría al agente sin precio y sin poder
explicar por qué, que es peor que no tener la bandera. Por la misma razón, el
formulario borra los ejes cuando se vacía la tabla.

## Cómo resuelve el CRM (no el modelo)

`POST /api/v1/productos/catalogo/{sku}/precio` con `{"criterios": {...}}`.

| Estado | Cuándo | Qué hace el agente |
|---|---|---|
| `resuelto` | una sola fila coincide | da el precio |
| `ambiguo` | faltan ejes | pregunta **lo que dice `faltan`** y reintenta |
| `sin_datos` | la combinación no está cargada | deriva, **no acerca la más parecida** |

`faltan` trae solo los ejes que **discriminan** entre las candidatas: si todas
comparten canal, preguntar el canal le hace contestar al cliente algo que no
cambia el precio.

La comparación ignora acentos, mayúsculas y espacios (`tamano: 50l` = `Tamaño:
50 L`) pero **no acepta sinónimos** (`50 litros` no coincide). Tolerar la
escritura del mismo valor evita que un acento se lea como «no hay precio»;
tolerar un sinónimo haría cotizar una combinación que el cliente no pidió.

Un producto de precio fijo también responde `resuelto`, con su `price`: así el
agente puede llamar siempre a la misma herramienta sin decidir antes de qué tipo
es el producto.

## Guardados parciales

`products.update` la usan dos formularios distintos: la pantalla de edición
completa y el **editor inline de atributos** de la vista del producto
(`products/view/attributes.blade.php`), que postea un puñado de campos.

Sin distinguirlos, cambiar el SKU desde el editor inline se leía como «el asesor
vació la tabla» y borraba las 25 combinaciones -y las características, que ya
tenían el mismo agujero desde antes de este cambio-. En silencio, porque un
`update` parcial es una operación legítima.

Los dos parciales emiten un marcador (`precios_enviados`,
`caracteristicas_enviadas`) y el controlador **no toca lo que el formulario no
mandó**. Se eligió un marcador explícito sobre inferirlo de la presencia del
campo porque el campo puede faltar legítimamente cuando el asesor borra la
última fila.

Las columnas de eje se renderizan **server-side** además de mantenerlas el
script. Si dependieran solo del JS, un fallo de carga dejaría la tabla sin los
`<input name="precios[N][valores][Tamaño]">` y el guardado borraría la lista
entera - una falla de JavaScript se convertiría en pérdida de datos.

## El catálogo cargado

`database/seeders/ProductosImprimirSeeder.php` deja las cuatro líneas del
documento. Es idempotente (identifica por SKU y reescribe la lista entera), así
que se puede correr de nuevo después de cargar los precios que faltan:

```bash
php artisan db:seed --class=Database\\Seeders\\ProductosImprimirSeeder
```

| SKU | Producto | Ejes | Combinaciones | Precios |
|---|---|---|---|---|
| `CM_00002` | Bolsas Magia Verde | Tamaño × Canal | 25 | ⚠️ sin cargar |
| `CM_00003` | Hules | Color | 3 | ✅ 472 / 260 / 460 |
| `CM_00004` | Tapas | Impresión | 2 | ✅ 927,50 / 954 |
| `CM_00001` | Stretch Film | Presentación | 2 | ⚠️ sin cargar |

## Lo que falta cargar

El documento de estructura **no trae la matriz de precios de las bolsas** (5
tamaños × 3 canales = 15 números) ni los de stretch film. Trae los de Hules y
Tapas, y los canales por nombre, pero no los montos.

Nada se cargó inventado: la grilla está armada con la columna Precio vacía. Una
fila sin precio hace que el agente **derive**, que es exactamente lo que
corresponde mientras el precio no esté. Cuando el negocio los cargue desde el
CRM, empieza a cotizar solo - sin tocar el agente.

## Archivos

- `packages/Webkul/Product/src/Database/Migrations/2026_09_09_00000{1,2}_*`
- `packages/Webkul/Product/src/Models/ProductPrice.php`
- `packages/Webkul/Product/src/Support/ListaPrecios.php` - resolución compartida
  entre el CRM y el agente: dos implementaciones se despegan a la primera
  corrección que se hace en una sola
- `packages/Webkul/Product/src/Repositories/ProductPriceRepository.php`
- `packages/Webkul/Product/src/Http/Controllers/Api/AgenteController.php` - `precio()`
- `packages/Webkul/Admin/src/Resources/views/products/_precios.blade.php`
- `docs/agent/langchain/crm_productos.py` - herramienta `precio_producto`
- `tests/Feature/Product/{ListaPreciosTest,PreciosFormularioTest}.php`
