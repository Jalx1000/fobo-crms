# Spec: Tablero consistente

Estado: **listo para planificar**, con P2 («Sin ciudad») y P5 («Tiempo en responder») pendientes. Auditoría hecha el 15/10/2026 contra producción; reemplaza al borrador del 13/10.

## Objetivo

Las tarjetas del tablero (`/admin/dashboard`) tienen que contar **el mismo conjunto de pedidos**
para el mismo rango y los mismos filtros, y cada tarjeta tiene que medir lo que dice su título.

**Para quién:** el gerente de Imprimir y los asesores. Leen el tablero y sacan de ahí el reporte mensual.

**Éxito:** cualquier total del tablero es igual a la suma de su desglose (por ciudad, por asesor o
por etapa), y los dos son iguales a lo que da una consulta directa a la base con las reglas de
este documento.

## Auditoría por tarjeta (1 al 14 de octubre de 2026, sin filtro de ciudad)

Valores obtenidos llamando a los métodos reales de `Helpers/Dashboard.php` como usuario con permiso
`global`, al lado de la consulta directa a `kl_leads`. Script: `audit.php` (ver "Comandos").

**Universo de referencia:** 369 pedidos creados en el rango, todos con asesor asignado.

| Etapa | Pedidos | | Ciudad | Pedidos |
|---|---|---|---|---|
| NO ATENIDOS | 201 | | Sin ciudad | **169** |
| SIN INTERÉS | 67 | | Santa Cruz | 89 |
| COTIZACION ENVIADA | 54 | | Cochabamba | 50 |
| SOLICITUD RECIBIDA | 32 | | La Paz | 32 |
| OTROS SERVICIOS | 7 | | Tarija / Potosí / Sucre / Oruro / Beni / Pando | 10 / 7 / 6 / 4 / 1 / 1 |
| CANCELADOS | 6 | | | |
| ENTREGADOS | 2 | | | |

### Tarjetas visibles

| # | Tarjeta (título en pantalla) | Muestra | Debería cuadrar con | Por qué no cuadra |
|---|---|---|---|---|
| 1 | **Total de contactos** (KPI) | 370 | 369 pedidos | Cuenta **personas** (`persons.created_at`), no pedidos. Cuadra por casualidad (≈1 persona por pedido). Es un indicador distinto, y el hint lo aclara. |
| 2 | **Total productos solicitados** (KPI) | 821 | Tarjeta 7 (80 cotizaciones) | Suma `lead_products.quantity` de **todos** los pedidos. 237 de las líneas tienen cantidad 0 (el producto se carga solo, sin cantidad). Las 821 son en realidad 632 de Magia Verde + 182 de Stretch, cargadas en pocos pedidos. Mezcla unidades de productos distintos. |
| 3 | **Total productos vendidos (Entregados)** (KPI) | 0 | 2 entregados | Los 2 entregados (#1124, #1669) tienen el producto con cantidad 0 y `lead_value` = 0. La consulta está bien; **faltan datos** al cerrar el pedido. |
| 4 | **Atendidos vs no atendidos por encargado** | 143 / 57 = 200 | 369 (embudo) | 1) Excluye **"Sin ciudad"**: faltan los 169, de los cuales 144 son no atendidos (78 de Eduardo, 66 de Bulmaro). 2) Excluye los roles Supervisores y Administrador, y el usuario "Agente" (hoy no tienen pedidos, así que no restan nada, pero la regla sigue ahí). 3) "Atendidos" incluye Sin interés, Cancelados y Otros servicios. |
| 5 | **Evolución** (pestañas Pedidos entregados / Valor / Productos vendidos) | 2 / 0 / 0 | Tarjeta 3 | Cuadra con la 3, pero hereda el mismo hueco de datos: valor y cantidad en 0. |
| 6 | **Tiempo en responder** (todos los usuarios, ver C8) | Bulmaro 143,7 h, Eduardo 143,7 h, Fabio 123,4 h, Gabriela 118,6 h, Mauricio 116,6 h | Tiempo real hasta la primera respuesta | **No mide respuesta: mide la edad del pedido.** `confirmed_at` no está cargado en ningún pedido del rango y `first_stage_change_at` solo en 10 de 369. Para No atendidos y Cotización enviada el reloj corre hasta *ahora*, así que el número sube cada hora sin que nadie haga nada. No excluye "Sin ciudad", a diferencia de la tarjeta 4. El "Promedio total" es el promedio simple de los promedios de cada asesor (Fabio, con 4 pedidos, pesa lo mismo que Bulmaro con 147). El empate a 143,7 h es casual (517 391 s contra 517 415 s). |
| 7 | **Productos más solicitados** (torta) | Magia Verde 42, Stretch 35, Hules 2, Tapas 1 | Tarjeta 2 / pedidos por producto | 1) Cuenta **cotizaciones** (`quote_items`, por `quotes.created_at`), no pedidos. Los pedidos con el producto son 170 de Magia Verde y 122 de Stretch. 2) Excluye "Sin ciudad": Stretch baja de 38 a 35. 3) La fecha es la de la cotización; el resto del tablero usa la del pedido. |
| 8 | **Productos más vendidos** (torta) | vacía | 2 entregados | Igual que la 3: los entregados tienen cantidad 0 y la consulta filtra con `HAVING SUM(quantity) > 0`. |
| 9 | **Total contactos** (embudo por etapa) | 369 (201/32/54/67/2/6/7) | - | **Es la única tarjeta que cuenta el universo completo**: no excluye "Sin ciudad", roles ni nada. El título está mal: cuenta **pedidos**, no contactos, y choca con la tarjeta 1, que tiene casi el mismo nombre. |
| 10 | **No atendidos por Ciudad** (torta) | 57 | 201 no atendidos del embudo | Excluye "Sin ciudad" (144 no atendidos) y los roles excluidos. Cuadra con la tarjeta 4 (57), pero no con el embudo (201). |

### Tarjetas que nadie ve (C8)

`index.blade.php` muestra la grilla solo si `strtolower($user->role->name) === 'admin'`. En la base,
el rol se llama **"Administrador"**, así que la condición es falsa para todos y nunca se muestran:

| Tarjeta | Valor | Nota |
|---|---|---|
| Ventas por vendedor | 2 | Cuadra con ventas totales (2). |
| Leads por ciudad | 255 | Usa la definición de "Prospectos" (C2). |
| Ventas por ciudad | 2 | Cuadra. |
| "Atendidos vs no atendidos" (otra vez) | - | La grilla incluye `leads-by-users` por segunda vez. |

### Qué ve un asesor con permiso `group` (ejemplo: Mauricio)

Las tarjetas 4, 6 y 10 se limitan a los asesores de su grupo (Mauricio ve a Bulmaro, Eduardo y a él mismo, y La Paz desaparece
de la torta 10). En cambio, los KPI, el embudo, la evolución y los productos muestran los **totales de toda la empresa**.
En la misma pantalla, unas tarjetas cuentan su grupo y otras cuentan a toda la empresa.

## Causas raíz

| Id | Causa | Tarjetas afectadas | Impacto en el rango |
|---|---|---|---|
| **C1** | "Sin ciudad" se excluye en algunas tarjetas y en otras no | 4, 7, 10 la excluyen; 6 y 9 no | 169 pedidos (46 %), 144 no atendidos invisibles |
| **C2** | "Prospecto" = solo NO ATENIDOS + COTIZACION ENVIADA (`openStageIds`) | Prospectos/día (oculta), Leads por ciudad, conteo auxiliar de la 6 | 255 contra 369: Solicitud recibida (32), Sin interés (67) y Otros (7) no aparecen |
| **C3** | Títulos que no dicen lo que cuentan | 9 ("Total contactos" = pedidos), 6 ("Tiempo en responder" = edad) | Confusión entre la 1 y la 9 |
| **C4** | Tres medidas distintas de "producto solicitado" | 2 (unidades de `lead_products`), 7 (cotizaciones), pedidos con producto | 821 unidades contra 80 cotizaciones contra 297 pedidos con producto |
| **C5** | Los pedidos entregados no guardan valor ni cantidad | 3, 5, 8 | 2 entregados que suman 0 |
| **C6** | `confirmed_at` y `first_stage_change_at` no se llenan | 6 | La tarjeta mide antigüedad |
| **C7** | ACL solo en las tarjetas por asesor y por ciudad | 4, 6, 10 contra el resto | Un asesor `group` ve totales que no puede desglosar |
| **C8** | `$isAdminRole` compara con `'admin'` y el rol se llama "Administrador" | Grilla de ventas y ciudades | 4 tarjetas que nadie ve |
| **C9** | Exclusión de roles con `NOT IN` sobre `users.id` (LEFT JOIN) | 4, 10, grilla | Hoy no resta nada, pero un pedido sin asesor (`user_id` NULL) se descarta en silencio, y desde el 13/10 el reparto puede dejarlos así |

**Cambio respecto al borrador del 13/10:** Bulmaro Vaca y Eduardo Lujan ya tienen el rol **Ejecutivo
Comercial**, no *Supervisores*, así que sus pedidos ya aparecen en las tarjetas por asesor. El problema
que les tapaba los números ahora es C1: 144 de sus 283 pedidos están en "Sin ciudad".

## Decisiones del 15/10 (respuesta a la auditoría)

| # | Decisión | Dato que la respalda |
|---|---|---|
| D1 | **Total de contactos** = personas registradas **en el rango elegido** (`persons.created_at`), como hoy. No cambia. | Histórico: 386; del 1 al 14/10: 370. El usuario lo comparó con otra fecha: el cálculo está bien. |
| D2 | **"Sin ciudad"**: queda **pendiente** (P2). | Explica la diferencia de la tarjeta 10: el kanban muestra 201 no atendidos = **144 "Sin ciudad"** + 57 en ciudades (Santa Cruz 20, Cochabamba 17, Tarija 6, Potosí 5, Sucre 4, La Paz 3, Oruro 1, Beni 1). La torta descarta los 144. |
| D2b | Las tortas (No atendidos por ciudad, Más solicitados, Más vendidos) muestran **el número de cada porción en la leyenda y el total** de la tarjeta. | Hoy la leyenda solo tiene nombres y el número aparece al pasar el mouse. Por eso el 57 no se veía en ninguna parte: hay que sumar las porciones a mano. |
| D3 | **Productos solicitados = unidades cotizadas por producto** (`SUM(quote_items.quantity)`). El KPI "Total productos solicitados" y la torta "Productos más solicitados" salen del **mismo método**, y la suma de la torta = el KPI. Ejemplo: si 4 cotizaciones suman 29 bolsas de Magia Verde, la porción dice 29. | Del 1 al 14/10: Magia Verde 1199, Stretch 505, Tapas 30, Hules 7 = **1741**. El 821 no es confiable (ver abajo). |
| D4 | **Productos vendidos, valor de ventas y más vendidos salen de las cotizaciones** de los pedidos en etapa ENTREGADOS: unidades = `SUM(quote_items.quantity)`, igual que D3 pero solo para entregados; valor = total de esas cotizaciones. | Hoy dan 0 por otro motivo: los 2 entregados (#1124, #1669) **no tienen cotización**. Ver P9. |
| D5 | El KPI "Total productos vendidos (Entregados)" y la torta "Productos más vendidos" salen del **mismo método**, y la suma de la torta = el KPI. | La torta hoy filtra `HAVING SUM(quantity) > 0` sobre `lead_products`; deja de usar esa tabla. |
| D6 | **Entregados sin cotización:** no se bloquea el cambio de etapa. Cuentan 0 en vendidos y en valor, y las tarjetas de vendidos (KPI, Evolución, torta "Más vendidos") muestran un **aviso** "N pedidos entregados sin cotización - complétalos", con enlace a cada pedido (o a la lista de Pedidos filtrada) para cargar la cotización y sus productos. Respeta el rango, la ciudad y el ACL del tablero. Si N = 0, no se muestra. | Del 1 al 14/10: N = 2 (#1124 de Bulmaro, #1669 de Fabio, los dos en Santa Cruz). |
| D7 | Tarjetas por asesor: **solo rol Ejecutivo Comercial** (fuera Sistema, Supervisores y Administrador). | Hoy los 369 pedidos del rango son de ejecutivos. |
| D8 | ACL en **todas** las tarjetas, totales incluidos. | Mauricio hoy ve 255 prospectos de toda la empresa, pero solo 3 asesores. |
| D9 | La grilla oculta la ven Administrador y Supervisores. | Hoy no la ve nadie (C8). |

### Cuál dato es el verídico en productos solicitados

| Fuente | Magia Verde | Stretch | Hules | Tapas | Total |
|---|---|---|---|---|---|
| `lead_products` (KPI actual, unidades) | 632 | 182 | 7 | 0 | **821** |
| `quote_items` · cotizaciones (torta actual sin excluir "Sin ciudad") | 42 | 38 | 2 | 1 | **83** |
| `quote_items` · unidades cotizadas | 1199 | 505 | 7 | 30 | 1741 |

**El verídico es la cotización.** `lead_products` es el producto que el agente o el asesor carga al
pedido, casi siempre sin cantidad: de 298 líneas, **237 tienen cantidad 0**, y en los 80 pedidos con
cotización la cantidad del pedido coincide con la cotizada solo en 51 (en 29 no). Ejemplo: el pedido #948 dice 50
rollos de Stretch, y la cotización, 100. El 821 es la suma de las pocas líneas que alguien llenó.

**Unidad (decidida):** unidades cotizadas. La torta muestra las unidades de cada producto y el
KPI es su suma (1741 en el rango). En la leyenda o el tooltip de cada porción se agrega el número de cotizaciones
(ej. "Magia Verde - 1199 u. en 42 cotizaciones"), para que no se pierda el dato de cuántos clientes pidieron.

## Regla propuesta: un solo "universo de pedidos"

Toda tarjeta que cuente pedidos parte del mismo universo, implementado **una sola vez** en `AbstractReporting`:

1. **Fecha:** `leads.created_at` dentro del rango. Para productos: los productos **de los pedidos** creados en el rango (no `quotes.created_at`). Ver P4.
2. **Ciudad:** "Sin ciudad" es una ciudad más. Aparece como barra o porción propia y nunca se descarta en silencio. Ver P2.
3. **Personas (D7):** las tarjetas por asesor muestran **solo usuarios con rol *Ejecutivo Comercial***. Quedan fuera los roles Sistema ("Agente"), Supervisores y Administrador. La regla es por **inclusión** del rol, no por una lista de excluidos: un rol nuevo no aparece hasta que se decida. Los pedidos de otros roles o sin asesor no se pierden: se cuentan en los totales y la tarjeta por asesor muestra una nota "N pedidos de otros usuarios o sin asesor". Así, la suma por asesor + N = total. Hoy N = 0: los 369 pedidos son de ejecutivos.
4. **Filtros del tablero:** ciudad (`pipeline_id`) y asesor (`user_id`) se aplican en **todas** las tarjetas.
5. **ACL (D8):** si el usuario ve solo a ciertos asesores (`view_permission` = `group` o `individual`), **todas** las tarjetas se limitan a ellos: totales, embudo, evolución y productos incluidos.

Y se corrige lo que no es de filtros:

6. **Títulos:** la 9 pasa a "Pedidos por etapa". La 6 ("Tiempo en responder") **no se toca** hasta resolver P5.
7. **Grilla oculta (D9):** la ven los roles **Administrador y Supervisores**. Se reemplaza `strtolower(role) === 'admin'` por esos dos nombres reales. Se saca el `leads-by-users` duplicado. Los demás roles siguen viendo "Tiempo en responder" en ese lugar.
8. **Productos:** "solicitados" = pedidos que tienen ese producto (no unidades, no cotizaciones). Ver P6.

### Supuestos

1. No cambian las etapas que cuentan como entregado (`ENTREGADOS`) o cancelado (`CANCELADOS`).
2. C5 (entregados sin valor ni cantidad) es un problema de **carga de datos**: este spec lo hace visible pero no lo arregla. El arreglo (pedir cantidad y valor al pasar a Entregado) va en un spec aparte.
3. El rango por defecto del tablero no cambia (desde ayer).
4. No hay cambios de esquema.
5. El reporte PDF se regenera después con las reglas nuevas.

## Comandos

```bash
# Tests (siempre como www-data dentro del contenedor)
C=$(docker ps --format '{{.Names}}' | grep imprimir_laravel)
docker exec -u www-data -w /code $C php artisan test tests/Feature/Dashboard
docker exec -u www-data -w /code $C ./vendor/bin/pest --filter="tablero consistente"
docker exec -u www-data -w /code $C ./vendor/bin/pint packages/Webkul/Admin/src/Helpers/Reporting

# Auditoría contra producción (solo lectura): rango, usuario logueado, ciudad opcional
docker cp audit.php $C:/tmp/audit.php
docker exec -u www-data -w /code $C php /tmp/audit.php 2026-10-01 2026-10-14 1
docker exec -u www-data -w /code $C php /tmp/audit.php 2026-10-01 2026-10-14 257   # asesor con ACL group
```

Es web: no hace falta reiniciar el worker. Si cambian textos de Blade: `php artisan view:clear`.

## Estructura

```
packages/Webkul/Admin/src/Helpers/Reporting/AbstractReporting.php  → scope compartido "universo de pedidos" (nuevo)
packages/Webkul/Admin/src/Helpers/Reporting/Lead.php               → los métodos dejan de filtrar por su cuenta
packages/Webkul/Admin/src/Helpers/Reporting/Product.php            → idem, y "solicitados" por pedido
packages/Webkul/Admin/src/Resources/views/dashboard/index.blade.php → condición de la grilla, sin duplicado
packages/Webkul/Admin/src/Resources/views/dashboard/index/*.blade.php → títulos (9, 6), "Sin ciudad"/"Sin asesor"
tests/Feature/Dashboard/TableroConsistenteTest.php                 → invariantes (nuevo)
scripts/auditoria-tablero.php                                      → el script de auditoría, versionado (nuevo)
```

## Estilo de código

Un scope compartido, con el estilo de `sinCiudadPipelineIds()`:

```php
/**
 * Universo de pedidos del tablero: los mismos filtros para todas las tarjetas
 * (spec docs/specs/tablero-consistente.md). Recibe la query ya unida a `leads`.
 */
protected function scopePedidosDelTablero($query, string $leads = 'leads')
{
    return $query
        ->when($this->pipelineFiltro(), fn ($q, $id) => $q->where("{$leads}.lead_pipeline_id", $id))
        ->when($this->asesorFiltro(), fn ($q, $id) => $q->where("{$leads}.user_id", $id))
        ->when($this->asesoresVisibles(), fn ($q, $ids) => $q->whereIn("{$leads}.user_id", $ids))
        // whereNotIn sobre leads.user_id con NULL permitido: un pedido sin asesor no se pierde (C9).
        ->when($this->usuariosExcluidos(), fn ($q, $ids) => $q->where(
            fn ($w) => $w->whereNull("{$leads}.user_id")->orWhereNotIn("{$leads}.user_id", $ids)
        ));
}
```

Comentarios en español que expliquen el **porqué**, como el resto del módulo.

## Estrategia de tests

Pest, con `DatabaseTransactions` y fechas de enero de 2020 (el mismo patrón que `TarjetasAtencionProductosTest`).
Datos fijos: asesor común, Administrador, "Agente" y pedido sin asesor; Santa Cruz, La Paz y "Sin ciudad";
pedidos en No atendidos, Solicitud recibida, Cotización enviada, Sin interés y Entregado.

**Invariantes:**

1. Suma del embudo (9) = pedidos del universo.
2. Atendidos + no atendidos (4) = embudo (9).
3. Suma de No atendidos por ciudad (10) = barra NO ATENIDOS del embudo (9).
4. "Sin ciudad" aparece en 4, 7 y 10 cuando tiene pedidos.
5. Ventas por vendedor = Ventas por ciudad = ventas totales.
6. Con el filtro de ciudad X, cada tarjeta da lo mismo que su fila X sin filtro.
7. Un usuario con ACL `group` ve en **todas** las tarjetas solo los pedidos de su grupo.
8. La grilla se muestra para el rol Administrador.

Los tests existentes de `tests/Feature/Dashboard` se actualizan solo donde cambia la regla, y se dice cuál cambió y por qué.

## Límites

- **Siempre:** una sola implementación del universo; invariantes en verde antes del commit; comentario que apunte a este documento.
- **Preguntar antes:** cambiar qué etapas cuentan como prospecto, venta o cancelado; tocar el rango por defecto; obligar a cargar cantidad o valor al entregar (C5).
- **Nunca:** cambiar el esquema; filtrar en el frontend para que "cuadre"; borrar tests sin decir cuál y por qué; escribir en producción durante la auditoría.

## Criterios de éxito

- [ ] Las 8 invariantes pasan.
- [ ] Con producción, del 1 al 14/10: embudo = atendidos + no atendidos = suma de pedidos por ciudad = 369.
- [ ] No atendidos por ciudad suma 201 (con "Sin ciudad" a la vista).
- [ ] El administrador ve la grilla de ventas y ciudades.
- [ ] Ningún método de `Reporting/` arma su propio filtro de rol, usuario, "Sin ciudad" o ACL.
- [ ] Ningún título dice "contactos" si cuenta pedidos.
- [ ] Suma de "Productos más solicitados" = "Total productos solicitados", los dos desde cotizaciones (D3).
- [ ] Suma de "Productos más vendidos" = "Total productos vendidos (Entregados)", los dos desde cotizaciones de entregados y desde el mismo método (D4, D5).
- [ ] Ninguna tarjeta de productos lee `lead_products`.
- [ ] Con producción, del 1 al 14/10, el aviso de D6 dice 2 y enlaza a #1124 y #1669. Al cargarles una cotización, el aviso desaparece y vendidos deja de dar 0.

## Preguntas abiertas

1. ~~P1~~ Resuelta en D7: solo Ejecutivo Comercial.
2. **P2 · "Sin ciudad" - PENDIENTE.** ¿Se muestra como categoría propia (recomendado: son 169 pedidos y 144 sin atender) o se excluye de **todas** las tarjetas, embudo incluido? **Decidido para la tarjeta 4 (16/10):** «Atendidos vs no atendidos» cuenta los «Sin ciudad» (el kanban de Bulmaro tenía 48 no atendidos y la tarjeta mostraba 2). Mientras tanto, cada tarjeta mantiene el comportamiento actual y el invariante 3 y el criterio "No atendidos por ciudad suma 201" quedan en espera.
3. ~~P3~~ Resuelta en D8: sí, en todas las tarjetas.
4. **P4 · Fecha de productos - supuesto:** cotizaciones de **pedidos creados en el rango** (`leads.created_at`), igual que el resto del tablero, porque así el ACL, la ciudad y el asesor se aplican por el pedido. En el rango auditado da lo mismo (83). Avisar si se prefiere la fecha de la cotización.
5. **P5 · "Tiempo en responder" - PENDIENTE.**** mide antigüedad porque `confirmed_at` y `first_stage_change_at` no se llenan. Opciones: (a) llenar `first_stage_change_at` al primer cambio de etapa o al primer mensaje saliente del asesor, y medir eso; (b) renombrarla "Antigüedad promedio de pedidos abiertos"; (c) ocultarla hasta tener (a). Recomiendo (a), con el primer mensaje saliente por WhatsApp como evento, porque es lo que el cliente percibe como respuesta.
6. ~~P6~~ Resuelta en D3: unidades cotizadas.
7. ~~P7~~ Resuelta en D9: Administrador y Supervisores.
8. ~~P8~~ Resuelta en D1: respeta el rango, como hoy.
9. ~~P9~~ Resuelta en D6: se acepta el 0 y se muestra un aviso para completar.
