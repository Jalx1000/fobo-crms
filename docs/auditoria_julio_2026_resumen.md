# Auditoría de datos — Julio 2026 (31/10/2026)

Promos del mes: **Promo Varietales** (desde el 01/07, Bs 239) y **Promo Vinos Icónicos** (lanzamiento oficial el 27/07, Bs 599).

## Números del mes (antes de las correcciones)

| Leads de julio | Cantidad | Valor |
|---|---|---|
| Entregados | 97 | Bs 33.088 |
| Cliente confirmado (abiertos) | 26 | Bs 9.530 |
| Sin interés / cancelados / otros servicios | 110 | Bs 6.294 |
| Ids que faltan (219–539) | 86 | 16 en `kl_dup_bkp_leads`; el resto, datos de prueba |

## A. Leads de julio pisados por pedidos posteriores — ✅ separados

| Lead | Pedido | Productos | Valor | Estado | Quedó en |
|---|---|---|---|---|---|
| Víctor Catari | jul 28/07 | 12× Fundador @24 | Bs 288 | Entregado (29/09) | **#515** |
| | sep 29/09 | 1× Icono Don Julio @330 + 1× Icono Elia Rosa @420 | Bs 750 | Entregado (29/09) | **#7527** (nuevo) |
| | oct 31/10 | 1× Querkus @270 + 3× Fundador @27 + 1× Cautivo @130 | Bs 481 | Cliente confirmado | **#7528** (nuevo, con la conversación) |
| Henry Menacho | jul 27/07 | 1× Promo Varietales (deducido del chat y del valor) | Bs 239 | Entregado (03/08) | **#478** |
| | oct 16/10 | 1× Promo Mujer Boliviana | Bs 90 | Entregado (23/10) | **#7529** (nuevo, con la conversación) |
| Adri Rivera | jul 14/07 | 2× Promo Varietales | Bs 478 | Cliente confirmado (Cochabamba, Jorge) | **#425** |
| | oct 16/10 | 1× Promo Mujer Boliviana | Bs 90 | Cliente confirmado | **#6044** (ya existía; se le pasó la conversación) |
| Mariel | jul 27/07 | — (nunca tuvo pedido) | Bs 0 | Sin interés | **#482** |
| | sep 27/09 | 5× Promo Primavera @75 | Bs 375 | Cliente confirmado | **#7530** (nuevo) |
| | oct 16/10 | 3× Promo Mujer Boliviana @90 | Bs 270 | Cliente confirmado | **#7531** (nuevo, con la conversación) |

⚠️ **Mariel:** el 13/10 escribió "aún puedo recoger el pedido" y el 16/10 pidió unir "5 promo primavera y 3 mujer boliviana". El agente no reconoció la Primavera y el sistema le borró la reserva. **Gabriel debería contactarla.**

Respaldo: `docs/separacion_pisados_julio_respaldo.json`. Script: `storage/separar_pisados_julio.php`.

## B. Teléfonos con el 0 de más — ✅ corregidos

- **96 contactos** de julio (#201–#358) tenían `591XXXXXXXX0`. Hoy quedan **0** en toda la base.
- **20 pares duplicados unidos.** Se verificó que el número fuera exactamente el mismo, dígito por dígito, sin ninguna coincidencia parcial; nombre y ciudad también coincidían en todos. Queda el contacto con la conversación de WhatsApp; si ninguno tenía, el de julio. Se pasaron leads, actividades, etiquetas y atributos faltantes (edad, ciudad).
- Se verificó que `findByPhone` encuentra al contacto correcto.

Respaldo: `docs/normalizacion_contactos_julio_respaldo.json`. Script: `storage/normalizar_contactos_julio.php`.

## C. Entregados sin líneas — ✅ cargados

| Lead | Cliente | Línea cargada (según n8n) |
|---|---|---|
| #301 | Mario Tapia | 1× Promo Varietales, Bs 239 |
| #354 | Julio Sánchez | 1× Promo Varietales, Bs 239 |
| #452 | Omar Fernández | 4× Promo Varietales, Bs 956 |

## D. Abiertos desde julio

**Cerrados (14)**, después de verificar que coincidían persona, productos y valor:

| Lead | Se cerró como | Motivo |
|---|---|---|
| #516 | Cancelado | Duplicado del #548 (entregado) |
| #506 | Cancelado | Duplicado del #539 (entregado) |
| #438 | Cancelado | Duplicado del #452 (entregado) |
| #498 | Cancelado | Duplicado del #473 |
| #531 | Cancelado | Duplicado del #485 |
| #501, #518 | Cancelado | Duplicados del #486 |
| #449, #471, #500, #536 | Sin interés | Vacíos: Bs 0, sin productos, sin conversación |

Respaldo: `docs/cierre_julio_abiertos_respaldo.json`. Script: `storage/cerrar_julio_abiertos.php`.

**Para que confirmen los asesores** (pedidos reales que nadie cerró; no se tocaron):

| Asesor | Lead | Fecha | Pedido | Valor |
|---|---|---|---|---|
| Jorge Bares | #388 | 10/07 | 1× Promo Varietales | Bs 239 |
| | #407 | 12/07 | 6× Tinto Clásico | Bs 120 |
| | #425 | 14/07 | 2× Promo Varietales | Bs 478 |
| | #440 | 17/07 | 1× Promo Varietales | Bs 239 |
| | #465 | 27/07 | 1× Promo Varietales | Bs 239 |
| | #473 | 27/07 | 1× Promo Varietales | Bs 239 |
| | #485 | 27/07 | 1× Promo Varietales | Bs 239 |
| | #486 | 27/07 | 2× Promo Varietales | Bs 478 |
| | #513 | 28/07 | 1× Promo Varietales | Bs 239 |
| | #526 | 29/07 | **3× Promo Vinos Icónicos** | **Bs 1.797** |
| Stefani Mendieta | #404 | 11/07 | 1× Promo Varietales (el cliente volvió a comprar el 18/09, #1584) | Bs 239 |
| | #448 | 21/07 | 1× Promo Varietales | Bs 239 |
| | #451 | 22/07 | Sin líneas; en n8n, Jaime Gómez confirmó 1× Varietales | Bs 0 |
| | #524 | 29/07 | 1× Promo Vinos Icónicos | Bs 599 |
| Gabriel Muñoz | #7530 / #7531 | sep / oct | Mariel: 5× Primavera + 3× Mujer Boliviana (ver A) | Bs 645 |

No se tocó el **#493** (Liliana): el cliente escribió el 30/10 y tiene un pedido nuevo (#7271).

## Productos y promociones — sin errores

- **Icónicos antes del 27/07:** Danitza la activó a propósito **solo para Potosí y Oruro** (10–11/07 y 17–21/07). El pedido de Erika Alvarez (Potosí, 10/07) cae ahí.
- El 08/07 (16:44–17:01) se recargó todo el catálogo (30 productos); la Promo Vinos Icónicos (#42) quedó activa 1,5 h por error, sin ventas.
- El **#418** (Icónicos, creado el 13/07) es una venta de agosto: Daniel la cargó el 26/08 en un lead viejo.
- Precios: lo vendido en julio coincide con el precio de ese momento.
- El 31/07 se cargaron de una vez las líneas de 26 entregados de Varietales (carga atrasada; coinciden con el valor).

## E. Correcciones posteriores — ✅

- **#296 José Luis Zeballos restaurado** desde `kl_dup_bkp_*`, con su id, la línea 1× Varietales (Bs 239), sus atributos y sus 36 actividades. Entregado por Liliana el 06/07. No era duplicado: es una segunda compra (chat del 04/07); la primera es el #229 (02/07, entregada el 03/07). Quedó en el contacto **#1499**, porque el #211 se unió a ese. Respaldo: `docs/restauracion_296_y_telefonos_respaldo.json`.
- **Fernando (Tarija): no faltaba nada.** Sus 3 confirmaciones (01/07, 09/07 y 14/07) son una sola compra: el **#223**, que Daniel entregó el 14/07 a las 18:39. No apareció en el cruce porque su teléfono argentino también tenía el 0 de más.
- **5 teléfonos extranjeros con el 0 de más, corregidos.** Se verificaron contra el número real que guardó n8n en el correo del contacto: #65 Joaquín, #182 Elio Anacleto, #205 Fernando, #237 Hernán Rodríguez y #340 Carlos Zapata.
- **#257 "Gastimen" unido al #1510**: mismo número exacto con el 0 de más. Respaldo: `docs/union_persona_257_1510_respaldo.json`.
- Script: `storage/restaurar_296_y_telefonos_extranjeros.php`.

## Quedan como están (decisión del usuario)

- **Erika Alvarez** (Potosí, 10/07): confirmó con Sofía 1× Promo Vinos Icónicos (Don Julio + Raíz Tannat, Bs 599). No existe en el CRM, ni como contacto ni como lead.
- **#245 Patty**: el valor es Bs 438 y las líneas suman Bs 426. Los Bs 12 los cargó Gabriel a mano el 28/08 y no salen de ningún producto. Además, tiene juntos los 3 vinos sueltos (Bs 187) y la Promo Varietales (Bs 239).
- **#218 Enrique** (sin interés): sigue solo en el respaldo.
- **#493**: es un lead de julio que todavía se usa para la conversación de octubre.
- 4 contactos con números que no se pudieron verificar contra n8n (#418, #1075, #4426, #7281).
