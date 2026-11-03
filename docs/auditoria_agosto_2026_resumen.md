# Auditoría de datos — Agosto 2026 (31/10/2026)

Promo del mes: **Promo Varietales** (Bs 239). La Promo Vinos Icónicos (Bs 599) siguió activa.
Método: cada pedido se revisó en el **Historial IA** (memoria de n8n, hasta el 04/09), en **Cloud API** (WhatsApp del CRM, desde septiembre) y en el **registro de actividades** del lead. Regla: cada pedido en fechas distintas tiene su propio lead.

## Números del mes

| Leads de agosto | Antes | Después |
|---|---|---|
| Pedidos entregados | 53 · Bs 18.804 | **52 · Bs 18.931** |
| Cliente confirmado / contactado / no atendido | 27 | 19 |
| Sin interés / cancelados / otros servicios | 79 | 88 |
| Total de leads | 159 | 159 (2 duplicados eliminados y 2 pedidos perdidos registrados) |

Cómo se mueve el valor entregado: −621 (#647 tenía el pedido de octubre de Diego), +599 (#683 Saúl), +239 (#712 Willy), −90 (#713 Albin pasa a confirmado).

En productos no hubo errores: solo se cargó el precio del #7 Souvignon Blanc el 12/08. No hubo ventas de promos fuera de fecha.

## A. Leads pisados — ✅ separados

**#647 Diego Álvarez (contacto #488).** El 07/10 el agente reabrió el lead, que ya estaba entregado, y le escribió encima 3 pedidos de octubre. Decisión del usuario: las fechas están bien separadas, así que cada pedido va en su lead y todos entregados.

| Lead | Fecha | Ciudad | Pedido | Valor | Estado |
|---|---|---|---|---|---|
| #523 | 29/07 | Santa Cruz | 1× Promo Vinos Icónicos | Bs 599 | Entregado 31/07, Liliana (ya estaba bien) |
| **#647** | 13/08 | La Paz | 1× Promo Varietales | Bs 239 | Entregado 27/08, Stefani |
| **#7532** (nuevo) | 07/10 | Santa Cruz | Icono Don Julio "con caja" @390 + Icono Elia Rosa @420 | Bs 810 | Entregado, Liliana (\*) |
| **#7533** (nuevo) | 16/10 | La Paz | 4× Raíz Tannat @80 | Bs 320 | Entregado, Stefani (\*) |
| **#7534** (nuevo) | 28/10 | La Paz | 2× Querkus + 4× Raíz Tannat | Bs 860 | Entregado 30/10, Stefani, con la conversación 3608 |

(\*) No hay fecha de entrega registrada, así que se usó como fecha de cierre la hora del pedido.

Respaldo: `docs/separacion_diego_y_pruebas_agosto_respaldo.json`. Script: `storage/separar_diego_y_pruebas_agosto.php`.

**#713 Albin Pardo (contacto #643).** El 22/08 pidió Varietales y no confirmó; Stefani lo pasó a confirmado con Bs 0. Como el lead quedó abierto, el 15/10 el agente lo reutilizó para otro pedido. Decisión del usuario: los dos en Cliente confirmado.

| Lead | Fecha | Pedido | Valor | Estado |
|---|---|---|---|---|
| **#713** | 22/08 | — | Bs 0 | Cliente confirmado, Stefani |
| **#7536** (nuevo) | 15/10 | 1× Promo Mujer Boliviana | Bs 90 | Cliente confirmado, Stefani, con la conversación 6441 (antes figuraba entregado el 28/10) |

Respaldo: `docs/separacion_albin_agosto_respaldo.json`. Script: `storage/separar_albin_agosto.php`.

## B. #683 Saúl — ✅ valor corregido

En el chat dijo "solo una", pero Sofía le mostró 2 promos (Tempranillo + Malbec ×2, Bs 1.198), él respondió "Sí" y Liliana tomó la conversación. El agente registró el valor con 1 promo (Bs 599), aunque la línea ya decía 2×. Decisión del usuario: **son 2**. Quedó en **Bs 1.198**, y la línea tiene ahora su monto, que estaba en 0. La edad (5556) no se tocó. Respaldo: `docs/correccion_683_saul_respaldo.json`.

## C. Duplicados — ✅ eliminados (con respaldo completo)

| Eliminado | Original | Motivo | Original queda |
|---|---|---|---|
| #648 Fabiola | #567 | El 13/08 escribió "Puedo pasar el sábado" y el agente abrió otro lead | Entregado 24/08, Gabriel. La conversación 1036 (Promo Tannat del 15/09) pasó a su lead, el #1326 |
| #723 Willy | #712 | El 24/08 escribió "hoy no podré pasar, mañana" y el agente abrió otro lead | **Entregado** por decisión del usuario, con fecha 25/08 09:00 (el día que avisó que pasaba) |

Respaldos: `docs/eliminacion_648_fabiola_respaldo.json`, `docs/eliminacion_723_willy_respaldo.json`.

## D. Leads sin pedido (Bs 0, sin productos ni conversación) — ✅ revisados uno por uno

En ninguno hubo un pedido confirmado en el Historial IA. Etapa decidida por el usuario:

| Lead | Cliente | Qué pasó en el chat | Quedó en |
|---|---|---|---|
| #585 | Edgar | Preguntó cómo unirse al Club del Vino | Otros Servicios |
| #664 | Ligia Velásquez | Pidió Varietales y no siguió | Pedidos cancelados |
| #686 | gontellez | "Quiero realizar un pedido" y no siguió | Pedidos cancelados |
| #689 | Fernanda | Pidió Varietales y no siguió | Pedidos cancelados |
| #711 | Karla Salazar | Dio nombre y edad | Cliente contactado |
| #556 | Fermín Paz | Consulta corporativa: 70 Cautivo con etiqueta personalizada | Otros Servicios |
| #708 | Ramiro | Preguntó por una agencia en Cochabamba | Otros Servicios |

Respaldo: `docs/cierre_vacios_agosto_respaldo.json`. Script: `storage/cerrar_vacios_agosto.php`.

**Quedan con su etapa (decisión del usuario):** #735 Adolfo Mendoza, #739 Sofía Pardo, #543 Ever Quispe, #550 Mariela Calveti, #586 Sergio Torrico y #740 Waldo, que solo consultaron. También #570 Marcelo, #656 Osmar y #726 Antonio: dijeron "una promo", pero no confirmaron con Sofía.

## E. Pruebas con el teléfono de Gabriel (contacto #525) — ✅ en Otros Servicios

Los leads #573, #694 (estaba en "Pedidos entregados" con Bs 0), #733 (Bs 474), #748, #1781 y #2768 (Bs 1.320) tienen una nota y ya no cuentan como clientes ni como ventas.

## Para que confirmen los asesores (pedidos reales sin cerrar)

| Asesor | Lead | Pedido | Valor |
|---|---|---|---|
| Gabriel Muñoz | #727 | 2× Promo Varietales | Bs 478 |
| María René Solano | #714 | 2× Promo Varietales (no atendido) | Bs 478 |
| Stefani Mendieta | #662, #663 (2 Syrah + 2 Malbec + 2 Raíz), #715, #720 | — | — |
| | #713 Albin y #7536 | Ver A | Bs 90 |
| Sin decidir | #684 y #757 | Bs 0, con conversación | — |

## Pedidos que nunca entraron al CRM — ✅ registrados como cancelados

En los dos chats, Sofía respondió "¡Listo! Tu pedido ha sido registrado correctamente", pero no creó el lead. Nadie contactó a ninguna de las dos clientas. Decisión del usuario: registrarlos como **Pedidos cancelados**, para que queden anotados sin contar como venta.

| Lead | Fecha | Clienta | Pedido | Valor | Asesor |
|---|---|---|---|---|---|
| **#7537** | 29/08 | Yovanna "Emi Yovi" (#679), La Paz | 1× Promo Varietales | Bs 239 | Stefani (con la conversación 7) |
| **#7538** | 30/08 | Mariela Saavedra (#689), Santa Cruz | 1× Promo Varietales + 1× Tinto Clásico @20 + 1× Clásico Blanco @20 | Bs 279 | Liliana |

Mariela escribió el 07/09: **"No se pusieron en contacto"**. Su lead #1354 (15/09) es posterior y no se tocó. Script: `storage/crear_perdidos_agosto.php`.

## Revisados y correctos

- **Olga #761 y Antonio #759**: pedidos del 29/08 registrados el 01/09, los dos entregados.
- **#547 y #666**: Silvia ajustó el valor el 30/09 para que coincida con las líneas.
- **#546, #548, #580 y #681**: eran confirmaciones sin productos, o cambios del mismo día.
- **#651 y #655**: están cancelados, con valor pero sin líneas.
- **01/09 11:03**: Daniel cargó valores atrasados en #645, #650, #665, #698, #743 y #755.

## Encontrado en otros meses (se revisa cuando toque)

Al cruzar los pedidos aparecieron leads de **septiembre y octubre** que el agente reutilizó o reabrió: #1448, #3693, #2695, #3633, #3645, #1199, #1609, #1745, #2263, #2751, #3691, más 9 leads viejos y vacíos que se usaron para pedidos de octubre. También hay reclamos de clientes: Vanessa (#1745, Cochabamba), Rosario (#2732, Sucre) y Ale (#2751, Cochabamba). Los detectores se pueden volver a correr: `storage/aud_cruce_pedidos.php`, `storage/aud_pisados.php` y `storage/aud_reusados.php`.
