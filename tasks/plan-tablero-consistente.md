# Plan de implementación: Tablero consistente

Spec: `docs/specs/tablero-consistente.md` (decisiones D1–D9 del 15/10/2026).

**Fuera de este plan (pendientes):**
- **P2 · "Sin ciudad":** cada tarjeta mantiene su comportamiento actual con "Sin ciudad".
- **P5 · "Tiempo en responder":** no se toca ni su cálculo, ni su título, ni su lista de asesores.

## Resumen

Se arregla el tablero en cuatro tramos, cada uno desplegable por separado:

1. **Base:** un solo lugar en `AbstractReporting` para las reglas compartidas (ciudad, ACL, ejecutivos).
2. **Productos:** solicitados y vendidos salen de las cotizaciones, el KPI y la torta comparten el método, y aparece el aviso de entregados sin cotización.
3. **Personas y permisos:** las tarjetas por asesor solo muestran ejecutivos, y el ACL se aplica en todas las tarjetas.
4. **Pantalla:** títulos, la grilla oculta y los números en las leyendas de las tortas.

## Decisiones de arquitectura

- **Las reglas se escriben una sola vez.** `AbstractReporting` expone `asesoresVisibles()` (ACL), `ejecutivosIds()` (rol Ejecutivo Comercial) y `pedidosDelTablero($query)` (ciudad y ACL sobre `leads`). Los métodos de `Lead` y `Product` las usan en lugar de armar su propio `when()`.
- **"Sin ciudad" queda detrás de un solo interruptor.** `sinCiudadPipelineIds()` ya existe. Las tarjetas que hoy lo excluyen lo siguen excluyendo, pero a través de un método con nombre (`excluirSinCiudad($query)`), para que P2 se resuelva cambiando un solo lugar.
- **Productos: un método por par KPI/torta.**
  `Product::solicitadosPorProducto()` devuelve, por producto, `unidades` y `cotizaciones`. El KPI "Total productos solicitados" es la suma de `unidades`, y la torta es la lista.
  `Product::vendidosPorProducto()` hace lo mismo, pero solo con pedidos en ENTREGADOS. Lo usan el KPI "Total productos vendidos", la torta "Más vendidos" y la pestaña "Productos vendidos" de Evolución (por día).
- **La fuente es `quote_items` unido por `lead_quotes` a `leads`, con fecha `leads.created_at`.** Así se aplican la ciudad, el ACL y el asesor del pedido. (Supuesto P4 del spec.)
- **El valor de ventas sale de `quotes.grand_total`** de las cotizaciones de pedidos entregados, no de `leads.lead_value`.
- **Ejecutivos por inclusión.** Las tarjetas por asesor listan usuarios cuyo `roles.name = 'Ejecutivo Comercial'`. `excludeIgnoredRoles()` deja de usarse en las tarjetas por ciudad y en los totales, porque esas cuentan **todos** los pedidos.
- **Sin cambios de esquema ni de rutas.** El aviso D6 usa un nuevo `type` en `DashboardController::$typeFunctions` y enlaza a `admin.leads.view`.
- **La exportación a Excel** (`DashboardSummarySheet`) llama a `getOverAllStats()` y `getRevenueStats()`, así que hereda los cambios. Hay que verificarla, no reescribirla.

## Grafo de dependencias

```
T1 Reglas compartidas (AbstractReporting)
 ├── T2 Solicitados desde cotizaciones (KPI + torta)
 │     └── T3 Vendidos desde cotizaciones (KPI + torta)
 │           ├── T4 Evolución: vendidos y valor desde cotizaciones
 │           └── T5 Aviso entregados sin cotización
 ├── T6 Por asesor: solo ejecutivos + nota de otros
 │     └── T7 ACL en todas las tarjetas
 └── T8 Títulos, grilla visible y sin duplicado   (independiente de T2–T7)
T9 Leyendas con números en las tortas            (después de T2, T3)
T10 Invariantes, script de auditoría y verificación en producción (al final)
```

Se pueden hacer en paralelo: la rama T2→T5, la rama T6→T7, y T8.

## Tareas

### Fase 1 · Base
- **T1** · Reglas compartidas en `AbstractReporting` (S)

### Checkpoint 1
- [ ] Tests del tablero en verde. T1 no cambia ningún número todavía: es una refactorización.

### Fase 2 · Productos (D3, D4, D5, D6)
- **T2** · Solicitados = unidades cotizadas: KPI y torta desde el mismo método (M)
- **T3** · Vendidos = unidades cotizadas de entregados: KPI y torta desde el mismo método (M)
- **T4** · Evolución: "Productos vendidos" y "Valor de ventas" desde cotizaciones (S)
- **T5** · Aviso "N pedidos entregados sin cotización" con enlaces (M)

### Checkpoint 2 · revisar con el usuario
- [ ] Producción, del 1 al 14/10: la torta de solicitados suma lo mismo que el KPI. El aviso dice 2 (#1124, #1669). Vendidos da 0.
- [ ] La exportación a Excel muestra los mismos números que el tablero.

### Fase 3 · Personas y permisos (D7, D8)
- **T6** · Tarjetas por asesor: solo Ejecutivo Comercial, más la nota "N pedidos de otros usuarios o sin asesor" (M)
- **T7** · ACL en totales, embudo, evolución y productos (M)

### Checkpoint 3 · revisar con el usuario
- [ ] Con Mauricio (`group`), cada total = la suma de su desglose, y todas las tarjetas muestran solo a su grupo.
- [ ] Con el Administrador (`global`), los números del checkpoint 2 no cambian.

### Fase 4 · Pantalla (D2b, D9, título 9)
- **T8** · Embudo "Pedidos por etapa", grilla para Administrador y Supervisores, y sin el `leads-by-users` duplicado (S)
- **T9** · Tortas con número por porción y total (S)
- **T10** · Test de invariantes, `scripts/auditoria-tablero.php` y corrida contra producción (M)

### Checkpoint final
- [ ] Todos los criterios de éxito del spec que no dependen de P2 ni de P5 están marcados.
- [ ] Deploy: `php artisan view:clear`. No hace falta reiniciar el worker.

## Riesgos y mitigación

| Riesgo | Impacto | Mitigación |
|---|---|---|
| **El KPI "Total productos solicitados" hoy cuenta "Sin ciudad" y la torta no.** Si comparten un método, uno de los dos cambia. | Alto | Pregunta abierta 1. Por defecto se usa la regla de la torta (excluye "Sin ciudad", la decisión del 13/10), detrás de `excluirSinCiudad()`. |
| Vendidos, valor y más vendidos quedan en 0 mientras los entregados no tengan cotización | Medio | Es lo esperado (D6). El aviso de T5 lo explica en pantalla y enlaza a los pedidos. |
| `quote_items.quantity` mezcla unidades (bolsas, rollos, tapas) | Bajo | Decidido en D3. La leyenda muestra las unidades **por producto**, y el KPI es la suma. |
| Tests existentes que fijan el comportamiento viejo (`TarjetasAtencionProductosTest`: solicitados "cuenta cotizaciones") | Medio | Se actualizan en la misma tarea que cambia la regla, diciendo cuál y por qué. No se borra ninguno. |
| El ACL en totales cambia lo que ve un asesor en la exportación a Excel | Bajo | Es lo esperado (D8). Se verifica en el checkpoint 3. |
| Vistas compiladas como root | Medio | Los tests y artisan se corren siempre con `-u www-data`. |

## Preguntas abiertas

1. **"Sin ciudad" en el par de solicitados.** Hoy el KPI la incluye (1741 unidades) y la torta la excluye. Si comparten método, ¿excluyen las dos (por defecto, sigue la decisión del 13/10) o incluyen las dos? Con D3 deben sumar lo mismo, así que no pueden diferir.
2. **ACL y "Total de contactos".** Las personas no tienen un asesor confiable. Supuesto: este KPI **no** se limita por ACL y sigue contando todas las personas del rango (D1).
