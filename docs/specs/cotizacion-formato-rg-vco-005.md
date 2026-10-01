# Cotización con formato RG-VCO-005 — análisis previo al código

**Fuentes:** `docs/crm/` — dos notas manuscritas de la reunión del 11/09 y la
propuesta comercial real *"MAGIA VERDE — GRUPO QUIROZ HOLDING #3"* (formato
RG-VCO-005, versión 00, edición 19/4/2024).
**Estado:** análisis. No se tocó código.

---

## 1. Qué piden (consolidado de las tres fuentes)

| # | Pedido | Fuente | Hoy en el CRM |
|---|---|---|---|
| 1 | **Código TR4** por producto, obligatorio en la cotización | notas A y B, PDF | ❌ solo existe `sku` (CM_xxxxx, código interno del CRM) |
| 2 | **Código de barra** por producto, carga manual | notas A y B, PDF | ❌ |
| 3 | **Medida** del producto, visible en el detalle de la cotización | nota A, PDF | ⚠️ está como texto en `caracteristicas`, no como campo |
| 4 | **Unidad** por ítem ("PZA") | nota A, PDF | ❌ |
| 5 | **Tiempo de entrega** por producto | notas A y B, PDF | ⚠️ `tiempo_produccion` en `caracteristicas` de algunos |
| 6 | **Forma de pago** en la cotización | nota A, PDF | ❌ |
| 7 | **Validez 10 días** | nota A, PDF | ⚠️ hoy el default es **7** |
| 8 | **Lugar de entrega** en la cotización, con reglas por producto | notas A y B, PDF | ❌ |
| 9 | **Compra mínima** por producto, precisada | nota B | ⚠️ cargada, pero con la ambigüedad de bolsas sin resolver |
| 10 | PDF con el **formato RG-VCO-005** | PDF | ❌ el PDF es la plantilla de Krayin + bloque de firmas |
| 11 | **Sucursal** y **Orden de compra** en la cabecera | PDF | ⚠️ `sucursal` existe en Personas; orden de compra no |
| 12 | Compartir **ficha técnica** | nota B (tema de la reunión) | ✅ adjuntos de producto + `enviar_material`; faltan subir los PDF |
| 13 | Ubicaciones de almacenes | nota B | ⏳ pendiente de Mariana |

---

## 2. Lo que la propuesta real resuelve (datos que ya no hay que preguntar)

### Los códigos de Magia Verde

| Tamaño | Medida | COD TR4 | Código de barra | Pack |
|---|---|---|---|---|
| 200 L | (100×120) cm | `020117-0051` | `7779970880068` | 5 un |
| 140 L | (90×110) cm | `020117-0033` | `7779970880013` | 10 un |
| 75 L | (78×95) cm | `020117-0034` | `7779970880020` | 10 un |
| 50 L | (65×80) cm | `020117-0035` | `7779970880037` | 10 un |
| 35 L | (60×63) cm | `020117-0044` | `7779970880051` | 10 un |

Unidad: **PZA**. El nombre del ítem en su sistema: `BSA MULTIUSO MAGIA VERDE 200L 5UN`.

### Mínimos y entrega (nota B) — esto **corrige** lo cargado

| Producto | Mínimo | Entrega |
|---|---|---|
| Magia Verde | **10 paquetes** | puesto almacén cliente · inmediata |
| Tapas sin impresión | 1 caja | **recoge en planta** |
| Tapas con impresión | 30 cajas | **20 días** |
| Hules | 1 rollo | a domicilio **desde 5 rollos** |
| Stretch film | 1 rollo | a domicilio **desde 50 rollos**; si no, almacén cliente |

> Resuelve la ambigüedad que dejé abierta: el mínimo de bolsas es **10 packs**
> (100 bolsas), no 10 bolsas. Hoy está cargado como "10 unidades" textual.

### Las condiciones de la propuesta real

```
TIEMPO DE ENTREGA   INMEDIATA          FORMA DE PAGO     AL CONTADO
VALIDEZ DE OFERTA   10 DIAS            LUGAR DE ENTREGA  PUESTO ALMACEN CLIENTE
```

Y un detalle que importa: los precios de esa propuesta (7,50 · 11,50 · 12,00 ·
13,00 · 11,50) **no coinciden con ninguna columna de la tabla de precios** —
son un *"Escenario #3, compra mínima 3 bultos por medida"*, o sea un precio
negociado. La lista de precios es el punto de partida, no el techo: el asesor
tiene que poder cambiar el precio del ítem en la cotización. Hoy puede.

---

## 3. La decisión estructural: ¿un producto o cinco?

Hoy **Bolsas Magia Verde es un solo producto** (`CM_00002`) y el tamaño es un
eje de precio. Eso funcionaba para cotizar por chat, pero choca con lo que
piden ahora:

- El **TR4 y el código de barra son por tamaño**, no por producto. El de 200 L
  no es el de 35 L.
- Cada línea de la cotización lleva **su** TR4 y **su** código de barra.
- En el ERP de Imprimir (TR4) cada tamaño **es** un producto.

### Opción A — cinco productos, uno por tamaño ✅ recomendada

```
CM_00002-35   Bolsa Magia Verde 35 L   TR4 020117-0044   barra 7779970880051
CM_00002-50   Bolsa Magia Verde 50 L   TR4 020117-0035   barra 7779970880037
…
```

Cada uno con su medida, su pack, su TR4 y su barra como **campos propios**. Los
ejes de precio quedan en `Canal × Zona` (10 filas por producto en vez de 50 en
uno solo).

- ✅ Refleja el ERP: un TR4 = un producto.
- ✅ La cotización queda igual que la propuesta real, línea por línea.
- ✅ Tablas de precios más chicas y legibles.
- ⚠️ El agente elige el **producto** en vez del eje "Tamaño". El menú de tamaños
  pasa a ser un menú de SKUs. Es un cambio de prompt, no de API.
- ⚠️ Hay que migrar las 50 filas de precio actuales (script, no a mano).

### Opción B — un producto, códigos por fila de precio

Poner TR4/barra/medida en cada fila de `product_prices`.

- ❌ El TR4 se repite en las 10 filas de cada tamaño; corregirlo es tocar 10.
- ❌ Un código de barra "de una fila de precio" no es lo que es.
- ✅ No cambia el modelo ni el agente.

**Recomendación: A.** La B ahorra una migración hoy y cobra intereses cada vez
que alguien toque un código.

> Lo mismo aplica, en menor escala, a **Hules** (7 colores) y **Stretch Film**
> (2 presentaciones) si en TR4 cada color/presentación tiene su código propio.
> Hay que preguntarlo. Tapas con/sin impresión probablemente también.

---

## 4. Diseño propuesto

### 4.1 Productos — columnas nuevas

| Columna | Tipo | Para qué |
|---|---|---|
| `codigo_tr4` | string 30, índice | el código del ERP; **va en cada línea del PDF** |
| `codigo_barra` | string 20, nullable | EAN; carga manual, opcional |
| `medida` | string 60 | "(100×120) cm" — columna MEDIDA del PDF |
| `unidad` | string 10, default `PZA` | columna UNID |
| `tiempo_entrega` | string 60, default `Inmediata` | "20 días" para tapas impresas |
| `lugar_entrega` | string 60 | "Puesto almacén cliente" / "Recoge en planta" |
| `entrega_domicilio_desde` | int nullable | 50 (stretch), 5 (hules): a partir de ahí va a domicilio |

Columnas reales y no `caracteristicas` JSON: aparecen en el PDF, se buscan, se
validan. Las características siguen siendo para lo descriptivo.

`minimo` sigue en la fila de precio, corregido a **packs** donde corresponda.

### 4.2 Cotización — atributos personalizados (por SQL, sin código)

Igual que `marca` y `nit`: se crean en `kl_attributes` y el formulario los
muestra solo.

| Código | Tipo | Opciones / default |
|---|---|---|
| `forma_pago` | select | Al contado · Crédito 15 días · Crédito 30 días · Transferencia |
| `lugar_entrega` | select | Puesto almacén cliente · Recoge en planta · A domicilio |
| `tiempo_entrega` | text | default "Inmediata"; la calcula el CRM del peor ítem (ver 4.5) |
| `orden_compra` | text | lo llena el cliente al aceptar |
| `sucursal` | select | Santa Cruz · La Paz · Cochabamba · Otra (hereda de la persona) |
| `observaciones` | textarea | "Escenario #3 compra mínima 3 bultos por medida" |

`validez` no es campo nuevo: es `expired_at`. Se cambia el default de **7 a
10 días** en `quotes/create.blade.php` (una línea).

### 4.3 Ítems de cotización — foto de los códigos

Igual que `sku`/`name` hoy: se **copian** al ítem al momento de cotizar.

| Columna | Por qué copia y no join |
|---|---|
| `codigo_tr4` | si el producto cambia de código, la cotización emitida no puede cambiar |
| `codigo_barra` | idem |
| `medida` | idem |
| `unidad` | idem |

Sin esto, editar un producto reescribiría cotizaciones ya enviadas — y ya
vimos con el bug de `findOrFail` lo que pasa cuando un ítem depende del
producto vivo.

### 4.4 El PDF — plantilla nueva, formato RG-VCO-005

Reescribir `quotes/pdf.blade.php` para reproducir la propuesta real:

```
┌ logo ──────────── REGISTRO / PROPUESTA COMERCIAL MASIVOS ──── Código RG-VCO-005 ┐
│                                                                Versión 00        │
│  FECHA · CLIENTE · RAZÓN SOCIAL · NIT · SUCURSAL · ORDEN DE COMPRA               │
├──────────────────────────────────────────────────────────────────────────────────┤
│ N° │ ITEM │ MEDIDA │ COD TR4 │ CÓDIGO DE BARRA │ UNID │ CANT │ PRECIO │ MONTO   │
├──────────────────────────────────────────────────────────────────────────────────┤
│                                 MONTO APROXIMADO · DESCUENTO · TOTAL GENERAL     │
│ TIEMPO DE ENTREGA │ …   │ FORMA DE PAGO    │ …                                   │
│ VALIDEZ DE OFERTA │ …   │ LUGAR DE ENTREGA │ …                                   │
│ texto legal ("Para realizar su pedido…")                                         │
│ AGENTE COMERCIAL (firma · nombre · cargo) │ CLIENTE (firma · nombre · cargo)     │
│ OBSERVACIONES                                                                    │
└──────────────────────────────────────────────────────────────────────────────────┘
```

El bloque de firmas **ya está hecho** (asesor con firma y cargo, cliente con
NIT). Se reubica dentro del formato nuevo.

El código de documento (`RG-VCO-005 · Versión 00 · Edición 19/4/2024`) va a
config, no hardcodeado: es un dato del sistema de calidad de Imprimir y va a
cambiar de versión.

### 4.5 Lugar y tiempo de entrega — regla, no dato suelto

Las reglas de la nota B son por producto **y** por cantidad:

```
stretch film:  cantidad >= 50 → A domicilio, si no → Puesto almacén cliente
hules:         cantidad >= 5  → A domicilio, si no → Puesto almacén cliente
tapas s/impr:  Recoge en planta
tapas c/impr:  Recoge en planta · 20 días
bolsas:        Puesto almacén cliente · Inmediata
```

Con `lugar_entrega` + `entrega_domicilio_desde` en el producto, el CRM las
resuelve solo:

- Al **crear la cotización** (pantalla y `crear_cotizacion` del agente), el
  lugar y el tiempo se **proponen** desde los ítems; el asesor los puede cambiar.
- Con varios ítems, el tiempo de entrega es el **peor** (tapas impresas
  arrastran a 20 días todo el pedido) y el lugar el más restrictivo.
- `precio_producto` los devuelve también, para que el agente pueda decirle al
  cliente *"se entrega en su almacén"* o *"desde 50 rollos lo llevamos"*.

### 4.6 Ficha técnica

Ya está el mecanismo (adjuntos de producto → `enviar_material`). Falta el
**contenido**: subir la ficha técnica en PDF a cada producto. Hoy ningún
producto tiene adjuntos.

---

## 5. Orden de implementación

Cada paso deja el sistema funcionando y se puede frenar entre uno y otro.

| Paso | Qué | Código | Riesgo |
|---|---|---|---|
| 1 | Atributos de cotización (4.2) + validez 10 días | SQL + 1 línea | bajo |
| 2 | Columnas de producto (4.1) + formulario | migración + blade | bajo |
| 3 | **Dividir bolsas en 5 productos** con sus códigos y migrar precios | seeder + script | medio — toca el agente |
| 4 | Foto de códigos en ítems (4.3) al crear/editar cotización | migración + repo | bajo |
| 5 | Reglas de entrega (4.5) en pantalla y en la API del agente | servicio + tests | medio |
| 6 | **PDF RG-VCO-005** (4.4) | plantilla nueva | medio — hay que mirarlo, no solo testearlo |
| 7 | Corregir mínimo de bolsas a "10 packs" | seeder | trivial |
| 8 | Prompt del agente: tamaños como SKUs, entrega, forma de pago | doc | — |

Los pasos 1, 2 y 7 se pueden hacer hoy sin decidir nada. El 3 es la decisión.

---

## 6. Preguntas para el negocio antes de tocar código

1. **¿Hules y Stretch Film también tienen un TR4 por variante?** (7 códigos para
   hules, 2 para stretch film). Si sí, se dividen igual que bolsas.
2. **¿Tapas con y sin impresión son dos TR4 distintos** o uno solo con nota?
3. **Formas de pago reales.** La propuesta dice "AL CONTADO"; ¿hay crédito?
   ¿a cuántos días?
4. **¿El código de barra es obligatorio o puede faltar?** La nota dice "se podría
   agregar para ingresar manualmente" — lo leo como opcional.
5. **Tiempo de entrega de tapas impresas: ¿20 días hábiles o corridos?**
6. Las **ubicaciones de almacenes** (pendiente de Mariana): ¿van en la
   cotización, en la ficha del cliente, o solo las usa el agente para decir
   dónde recoger?
7. El **código de documento** RG-VCO-005 v00: ¿quién lo actualiza y cuándo
   cambia? Define si va en config o en pantalla.

---

## 7. Lo que NO cambia

- La lista de precios por variante y `precio_producto` — se simplifican (menos
  ejes) pero el contrato con el agente es el mismo.
- El NIT y su sincronización cotización ↔ persona.
- El bloque de firmas del PDF — se reubica, no se rehace.
- Los adjuntos de producto y `enviar_material`.
