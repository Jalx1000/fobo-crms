# Spec: filtros por plataforma, teléfono y nombre

> Estado: **IMPLEMENTADA** (2026-08-21). Módulos: **Personas, Leads y
> Cotizaciones**.
>
> **ALCANCE REDUCIDO durante la revisión:** solo se implementa el filtro de
> **plataforma**. Nombre ya existía en los tres y queda intacto; teléfono salió
> del alcance por decisión del usuario - el filtro de Personas sigue siendo el
> LIKE sobre el JSON crudo que ya tenía.
>
> Rama: `imprimir-modulo-whatsapp`

---

## Contexto: qué existe hoy

Relevado en el código, no asumido.

| Módulo | Nombre | Teléfono | Plataforma |
|---|---|---|---|
| Inbox WhatsApp | ✅ `search` | ✅ `search` | ✅ `channel` |
| Personas | ✅ `person_name` | ✅ `contact_numbers` | ❌ |
| Leads | ✅ `person_name` | ❌ | ⚠️ `lead_source_name` |
| Cotizaciones | ✅ `person_name` | ❌ | ❌ |
| Organizaciones | ✅ `name` | ❌ | ❌ |

**El inbox ya tiene los tres.** `ChatController::conversations()` filtra por `search`
(cruza `wa_name`, `wa_phone` y el nombre del contacto) y por `channel` validado
contra `gateways->has()`. Si el inbox era uno de los tres módulos, ahí no hay
trabajo.

**"Plataforma" solo existe en `whatsapp_conversations.gateway`.** Ninguna de las
grillas tiene esa columna: filtrar por plataforma significa cruzar contra
conversaciones.

**`lead_source_name` NO es plataforma.** `ConversationResolver::createLead()` abre
los leads con una fuente "WhatsApp" o "Messenger", así que ese filtro separa por
canal **solo para los leads que creó el bot**. Uno cargado a mano por un vendedor
no tiene esa fuente aunque el cliente venga de WhatsApp. Las dos columnas
responden preguntas distintas:

- `lead_source_name` → *por dónde entró este lead cuando se creó*
- `gateway` → *por dónde le puedo escribir hoy*

---

## Supuestos que estoy haciendo

Corregime ahora o procedo con estos:

1. **Los tres módulos son Personas, Leads y Cotizaciones.** Son las tres grillas
   donde se busca a alguien y les falta teléfono y/o plataforma. Ver Pregunta
   Abierta #1 - es lo único que bloquea.
2. "Plataforma" = el canal de mensajería (`cloud_api`, `kommo`, `messenger`),
   tomado de `whatsapp_conversations.gateway`, no de `lead_source`.
3. Un contacto puede tener varias conversaciones. La plataforma que se muestra y
   por la que se filtra es la del canal **activo**, con el mismo desempate que ya
   usa el inbox (`preferActiveGateway`): gateway activo primero, luego el más
   reciente.
4. Los filtros son de la DataGrid nativa de Krayin (`filterable`,
   `filterable_type`), no una UI nueva.
5. "Nombre" ya está resuelto en los cinco módulos. **No se toca.** El trabajo
   real es teléfono y plataforma.
6. MySQL 8+ (el docker-compose declara MySQL 9), así que `REGEXP_REPLACE` está
   disponible. El módulo ya lo usa en `ChatController::conversations()`.

---

## Objetivo

Que un vendedor o supervisor pueda encontrar un registro por **teléfono** y
acotar por **plataforma** desde las mismas grillas que ya usa, sin abrir el inbox.

**Usuario:** vendedor buscando un contacto que lo llamó y solo dejó un número;
supervisor que quiere ver todo lo que entró por un canal.

### Historias

- Como vendedor, quiero pegar un número en el filtro de Personas y encontrar al
  contacto **aunque lo hayamos guardado con otro formato** (`+591 756…` vs
  `756…`).
- Como vendedor, quiero filtrar Cotizaciones por teléfono, porque el cliente me
  escribe desde su celular y no me dice su nombre completo.
- Como supervisor, quiero ver solo los leads que llegaron por WhatsApp para saber
  cuánto pesa el canal.

---

## Alcance

### Dentro

- Columna **teléfono** filtrable en Leads y Cotizaciones (Personas ya la tiene).
- Columna **plataforma** filtrable (dropdown) en los tres módulos.
- Normalización de dígitos para que el filtro de teléfono tolere formatos.
- Traducciones `es` / `en`.

### Fuera

- El inbox de WhatsApp (ya tiene los tres).
- Cambiar el filtro de nombre en ningún módulo.
- Migraciones o columnas nuevas en base de datos.
- Un buscador global entre módulos.

---

## Stack

Sin dependencias nuevas. Laravel 10, Krayin DataGrid, MySQL 9, Pest.

---

## Comandos

```bash
php artisan test --filter=DataGridFilter     # los tests de esta feature
php artisan test                             # suite completa
./vendor/bin/pint <ruta>                     # SIEMPRE con ruta explícita
php artisan route:list --name=persons
```

> **Nunca correr `./vendor/bin/pint` sin ruta.** Reformatea ~56 archivos ajenos
> con violaciones preexistentes y ensucia el diff. Aprendido en la rama anterior.

---

## Estructura

```
packages/Webkul/Admin/src/DataGrids/
  Contact/PersonDataGrid.php    → + columna plataforma
  Lead/LeadDataGrid.php         → + teléfono, + plataforma
  Quote/QuoteDataGrid.php       → + teléfono, + plataforma

packages/Webkul/Whatsapp/src/
  Support/ContactChannel.php    → NUEVO: el SQL compartido de los 3 grids

packages/Webkul/Admin/src/Resources/lang/{es,en}/app.php

tests/Feature/DataGridFilterTest.php  → NUEVO
```

La expresión SQL vive **en un solo lugar**. Copiarla tres veces garantiza que
las tres diverjan: ya pasó con `preferActiveGateway`, que existe en
`InboxController` y de nuevo, distinto, en `DocumentDispatcher`.

---

## Estilo

Los tres grids usan `DB::table()` (query builder crudo, no Eloquent) y mapean el
filtro con `addFilter($indiceDeColumna, $columnaDeQuery)`. El `columnName` puede
ser un `DB::raw`, y `Text::processFilter` lo usa tal cual.

Teléfono normalizado, tomando el patrón que ya está en
`ChatController::conversations()`:

```php
/**
 * Los dígitos del teléfono de un contacto, sin formato.
 *
 * contact_numbers es JSON y el equipo carga el mismo número de mil formas
 * ("+591 756-40698", "75640698"). Un LIKE sobre el texto crudo falla en cuanto
 * cambia un separador, así que se normaliza la COLUMNA y no lo que se tipea.
 */
public static function phoneDigitsExpression(string $table): Expression
{
    return DB::raw("REGEXP_REPLACE(COALESCE({$table}.contact_numbers, ''), '[^0-9]', '')");
}
```

Plataforma vía `leftJoin` a una subconsulta (una fila por contacto), **no** vía
subconsulta correlacionada en el `WHERE`: la correlacionada se ejecuta por fila y
el `count(*)` de la paginación la vuelve a pagar.

Reglas vigentes: Pint pasa; los textos salen por `trans()`; nada de Eloquent
suelto en controladores.

---

## Estrategia de tests

Pest, `tests/Feature/DataGridFilterTest.php`.

**Restricción real:** la base no es alcanzable desde el host de desarrollo
(`heaven_imprimir` no resuelve), así que los tests **no pueden ejecutar
consultas**. Se verifica el **SQL generado** con `toSql()` y sus bindings - que
es además donde viven los bugs de esta feature.

| # | Caso | Qué prueba |
|---|---|---|
| 1 | El filtro de teléfono normaliza la columna | Que el SQL tenga `REGEXP_REPLACE`, no un LIKE crudo |
| 2 | `75640698` matchea un número guardado como `+591 756-40698` | La normalización, contra strings reales en PHP |
| 3 | El dropdown de plataforma lista los canales de `GatewayManager::channels()` | Que no haya canales hardcodeados |
| 4 | Un canal desconocido no rompe la grilla | Bookmark viejo tras desconectar un canal |
| 5 | La plataforma se resuelve al canal activo, no al más reciente | El desempate de `preferActiveGateway` |
| 6 | El join de plataforma no duplica filas | Un contacto con 3 conversaciones sigue siendo 1 fila |
| 7 | Los tres grids siguen paginando (el `count(*)` no rompe) | Columnas ambiguas tras agregar un join |

El test 6 es el que más importa: un `leftJoin` mal hecho multiplica filas y la
grilla muestra el mismo contacto tres veces sin ningún error.

---

## Límites

**Siempre:**
- `./vendor/bin/pint` **con ruta explícita**.
- Una sola definición del SQL compartido, consumida por los tres grids.
- Verificar el diff con `git status` antes de cerrar: solo los archivos previstos.

**Preguntar primero:**
- Cualquier migración o columna nueva (esta spec asume ninguna).
- Tocar `packages/Webkul/DataGrid/` (el core de la grilla, lo usan todos los módulos).
- Cambiar el filtro de nombre que ya funciona.

**Nunca:**
- Correr `pint` sin ruta.
- Subconsulta correlacionada en el `WHERE` de una grilla paginada.
- Hardcodear la lista de canales: sale de `GatewayManager`.
- Romper el filtro `lead_source_name` que ya existe en Leads - es otra pregunta,
  y alguien la está usando.

---

## Criterios de éxito

1. En Personas, Leads y Cotizaciones aparece un filtro **Plataforma** tipo
   dropdown con los canales registrados.
2. En Leads y Cotizaciones aparece un filtro **Teléfono**.
3. Buscar `75640698` encuentra al contacto guardado como `+591 756-40698`.
4. Un contacto con varias conversaciones aparece **una sola vez** en cada grilla.
5. Filtrar por un canal inexistente devuelve una grilla vacía, no un error 500.
6. Las tres grillas siguen ordenando, paginando y exportando como antes.
7. Los 7 tests pasan. La suite completa queda en el mismo baseline que HEAD.
8. `pint --test` pasa sobre los archivos tocados.

---

## Preguntas abiertas

### 1. Los tres módulos - RESUELTA

**Personas, Leads y Cotizaciones.** El inbox queda fuera: ya tiene los tres
filtros. Organizaciones queda fuera.

### 2. El teléfono se muestra, no solo se filtra - RESUELTA

Columna visible en Leads y Cotizaciones. Un filtro sobre algo invisible obliga a
abrir cada registro para confirmar que el resultado es el correcto.

### 3. Contacto sin conversación - RESUELTA

El dropdown incluye una opción explícita **"Sin canal"**, implementada como
`COALESCE(contact_channel.gateway, 'none')` en la columna del filtro: un dropdown
hace `where(columna, valor)` y eso nunca matchearía un `NULL`.

---

## Hallazgos del relevamiento técnico

Dos cosas verificadas en el código que gobiernan la implementación:

**`DB_PREFIX=kl_`.** `DB::table()` aplica el prefijo solo; el SQL crudo NO. Toda
expresión `DB::raw` tiene que interpolar `DB::getTablePrefix()`, como ya hacen
`LeadDataGrid` y `QuoteDataGrid`.

**Solo Leads tiene `groupBy('leads.id')`** (línea 110) - lo necesita porque ya
joinea `lead_tags`/`tags`. Personas y Cotizaciones **no agrupan**, así que están
desprotegidas contra un join que multiplique filas. Por eso la subconsulta de
plataforma debe garantizar una fila por contacto por construcción
(`ROW_NUMBER() = 1`), y no depender de un `GROUP BY` que en dos de los tres grids
no existe.

---

## Riesgos

| Riesgo | Impacto | Mitigación |
|---|---|---|
| El join de plataforma duplica filas | El mismo contacto tres veces, sin error | Subconsulta con una fila por contacto + test 6 |
| Columna ambigua tras el join rompe el `count(*)` de la paginación | Grilla en 500 | Columnas siempre calificadas; test 7 |
| `REGEXP_REPLACE` sobre `contact_numbers` impide usar índices | Grilla lenta con muchos contactos | Medir con datos reales; si duele, columna generada + índice (requiere migración → preguntar) |
| El usuario tipea `+591 756` y no matchea | "El filtro no anda" | Se normaliza la columna, no lo tipeado. Documentar: se busca por dígitos |
