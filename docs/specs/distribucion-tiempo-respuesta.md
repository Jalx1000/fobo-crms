# Spec: Tablero - Distribución del tiempo de respuesta

Estado: **implementado** (20/10/2026). Pedido del usuario: «agregar gráfica de tiempo en responder
(igual que la de kohlberg)».

## Objetivo

La tarjeta de kohlberg «Distribución del tiempo de respuesta»: cuánto tarda un pedido entre que
entra a una etapa y pasa a la siguiente. Muestra Mejor, Promedio (con mediana), Peor y el % de
movimientos resueltos en menos de un día hábil, más un histograma por días hábiles (0–13+).

## Decisiones

| # | Decisión |
|---|---|
| D1 | **Horas hábiles** (lun–vie 08:00–19:00, `HorasHabiles`), igual que «Tiempo en responder». Un «día» del histograma es un día hábil (11 h). |
| D2 | **No cuentan los movimientos en bloque**: un mismo usuario que mueve 5 o más pedidos en el mismo minuto (`Lead::MOVIMIENTOS_EN_BLOQUE`). La tarjeta informa cuántos se excluyeron (`excluidos_en_bloque`). |
| D3 | Como en kohlberg, no cuentan los pasos de menos de 1 minuto (reloj real). |
| D4 | Cuenta el movimiento que **ocurrió en el período**; el tramo puede haber empezado antes. El primer tramo arranca en la creación (el pedido nace con su «Actualizado Etapa»). |
| D5 | Sin selector propio de encargado: manda el filtro «Ejecutivo comercial» del tablero (y ciudad y permisos), igual que el resto de las tarjetas. |

## Estructura

```
packages/Webkul/Admin/src/Helpers/Reporting/Lead.php::getResponseTimeDistribution()
packages/Webkul/Admin/src/Helpers/HorasHabiles.php::segundosPorDia()
packages/Webkul/Admin/src/Resources/views/dashboard/index/distribucion-tiempo-respuesta.blade.php
DashboardController type `distribucion-tiempo-respuesta`
tests/Feature/Dashboard/DistribucionTiempoRespuestaTest.php
```

## Resultado con datos reales (1–19/10/2026, todos)

442 movimientos (108 excluidos en bloque); promedio 22,6 h hábiles, mediana 12,4 h; 47,3 % en
menos de un día hábil.

## Torta «No atendidos por ciudad» (mismo día)

Ahora incluye la porción «Sin ciudad» y la leyenda muestra el número de cada ciudad y el total
(antes mostraba 42 de 155 no atendidos y no cuadraba con el kanban).
