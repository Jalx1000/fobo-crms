# Limitaciones e informe de lo que no se pudo hacer

Lo que el brief pedía y no se implementó, por qué, y qué se hizo en su lugar. Ordenado por impacto.

---

## 1. Días de rotación por etapa — no soportado por el esquema

**Pedido:** un `rotten_days` distinto para cada etapa (Nuevo 1, Calificando 2, Con match 3…).

**Por qué no.** `rotten_days` es una columna de **`lead_pipelines`**, agregada por la migración
`2021_09_30_135857_add_column_rotten_days_in_lead_pipelines_table`. No existe columna equivalente en
`lead_pipeline_stages`. Implementarlo requeriría alterar una tabla del core.

**Qué se hizo (decisión D1).** Un `rotten_days` por pipeline, tomando el máximo de sus etapas — lo
más que un lead puede sentarse legítimamente en una sola etapa:

| Pipeline | `rotten_days` | Viene de |
|---|---:|---|
| Venta | 30 | Documentación |
| Alquiler y anticrético | 5 | Contrato |
| Captación | 7 | Autorización |

**Consecuencia.** Un lead estancado en "Nuevo" de `Venta`, donde el brief quería alertar al día 1,
recién se marca podrido a los 30. **La señal fina queda del lado de n8n**, que ya vigila estos leads y
puede comparar `updated_at` contra un umbral por etapa.

**Alternativa descartada.** Una migración aditiva en el paquete que agregue `rotten_days` a
`lead_pipeline_stages`. No modifica archivos del core, pero sí su tabla, y el core nunca leería la
columna: sería inerte hasta construir también el consumidor, que no estaba en el alcance.

---

## 2. Sin cobertura de tests automatizados

**Por qué no.** La suite de esta instancia es **destructiva sobre datos reales**. `phpunit.xml` no
override `DB_CONNECTION` ni `DB_DATABASE`, ningún test usa `RefreshDatabase` ni `DatabaseTransactions`,
y tests existentes como `tests/Feature/DoctorApiTest.php:9-13` arrancan con
`DB::table('doctors')->truncate()`. Correr `php artisan test` acá **borra tablas de `kohlberg`**.

Se decidió (D4) no crear una base `kohlberg_test`.

**Qué se hizo en su lugar.**
- `--dry-run` en ambos comandos como verificación primaria, segura contra producción.
- Checklist manual en el README.
- La guarda del rollback se mantuvo deliberadamente simple —una consulta de conteo por entidad, sin
  ramas— porque nada automatizado la cubre.

**Brecha.** SC8 ("el rollback se niega si hay datos") no tiene test de regresión. Se verificó una vez
a mano, insertando un lead de prueba dentro de una transacción revertida: la guarda disparó, listó los
dos bloqueos y salió con código 1 sin borrar nada. **Cualquier cambio futuro a esa guarda hay que
volver a verificarlo así.**

> ⚠️ **Riesgo aparte, fuera del alcance de este trabajo:** que `php artisan test` apunte a la base de
> producción es peligroso independientemente de este catálogo. Vale arreglarlo (un `DB_DATABASE` de
> test en `phpunit.xml`) antes de que alguien corra la suite por costumbre.

---

## 3. `bot_puntaje` y `op_dormitorios` se guardan como texto

**Pedido:** numéricos en la base.

**Por qué no.** Krayin **no tiene tipo de atributo numérico**. Los 15 tipos disponibles son
`text, textarea, price, boolean, select, multiselect, checkbox, email, address, phone, lookup,
datetime, date, image, file`, y según `AttributeValue::$attributeTypeFields` la única ruta a una
columna numérica que no sea un `select` es `price → float_value`. Pero `price` hace que el formulario
**anexe el símbolo de moneda a la etiqueta** (`components/attributes/index.blade.php:29-31`):
"Dormitorios mínimos ($)". Desactivarlo exige editar un blade del core.

**Qué se hizo (decisión D5).** Tipo `text` con `validation = 'numeric'` — valor soportado por el core,
que valida en el formulario. Se priorizó la etiqueta correcta.

**Consecuencia.** Ambos viven en `attribute_values.text_value` (TEXT):

```sql
-- n8n debe castear para comparar por rango; no usa índice
WHERE CAST(text_value AS UNSIGNED) >= 70
```

`ORDER BY text_value` es **lexicográfico**: en `bot_puntaje` (0-100), `"100"` ordena antes que `"9"`.
Si el scoring necesita ranking real, la salida es castear en la consulta, no cambiar el tipo.

---

## 4. `bot_wa_id` no es único

**Contexto.** El brief lo describe como "clave de deduplicación". La tabla `attributes` tiene una
columna `is_unique` que podría marcarlo.

**Por qué no se marcó.** El scope de atributos es **por entidad**, no por pipeline: los tres pipelines
comparten el mismo formulario. Un `bot_wa_id` único impediría que **una misma persona tenga más de un
lead** — exactamente el caso que estos tres pipelines existen para cubrir (alguien que compra y además
alquila, o un propietario que además es comprador).

**Qué se hizo.** `is_unique = 0`. La deduplicación queda del lado de n8n, que puede buscar por el
atributo antes de crear. Si preferís la restricción dura, es un cambio de una línea en
`Config/catalogo.php` — pero conviene decidirlo sabiendo qué bloquea.

---

## 5. El catálogo no renombra ni borra

Por restricción explícita del brief, el setup **solo crea**. Si cambiás el nombre de una etapa o de un
atributo en `Config/catalogo.php` y volvés a correr, se crea uno nuevo y el viejo queda. Renombrar se
hace desde la UI del CRM.

Caso relacionado: si un atributo del catálogo ya existe **con otro tipo**, el setup lo reporta como
omitido con el motivo y no lo toca. Cambiar el tipo de un atributo con datos cargados haría ilegibles
esos valores, porque viven en otra columna.

---

## 6. Deuda preexistente detectada (no tocada)

Encontrada durante el reconocimiento, **fuera del alcance** de este trabajo:

- **`LeadRepository.php:230-232`** decide si un lead está ganado o perdido por **substring del nombre
  visible** de la etapa, con códigos de otro cliente heredados del fork
  (`pedido-entregado`, `pedidos-cancelado`, `pedidos-confirmado`):
  ```php
  $isWon = ($code === 'won') || ($code === 'pedido-entregado')
        || str_contains($name, 'ganado') || str_contains($name, 'entregado');
  ```
  Ninguna etapa del catálogo nuevo colisiona, así que **hoy no rompe nada**. Pero si alguien renombra
  una etapa a algo que contenga "entregado" o "cancelado", se cerrarán leads solos.

- **`APP_ENV=local` con `APP_DEBUG=true`** sirviendo en `https://c21.sofopolis.com`. Stack traces
  expuestos al público en lo que es producción de facto.

- **No hay entorno de staging.** Todo lo de arriba se verificó contra producción, con backup previo.
