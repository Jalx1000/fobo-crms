# Auditoría de datos — Junio 2026 (cerrada el 31/10/2026)

Promo vigente: **Promo Mamá Elia Rosa** (Bs 300, del 28/05 al 30/06).

## Lo que queda hoy en el CRM

| | Cantidad | Valor |
|---|---|---|
| Leads creados en junio | 25 | — |
| Entregados (#73, #158, #161, #182) | 4 | Bs 1.371 |
| Sin interés, cancelados y otros servicios | 21 | Bs 0 |
| Contactos nuevos | 26 | — |

## Hallazgos y cómo quedaron

| # | Hallazgo | Estado | Qué se hizo |
|---|---|---|---|
| 1 | Falta ~80 % de junio: 110 de 135 ids de leads, 103 de 129 de contactos, 2.520 de 3.370 de actividades y 1.272 de 1.603 filas de la memoria de n8n. | ⚠️ Documentado | Se concluye que eran **datos de prueba** de la puesta en marcha (ver detalle abajo). No hay nada que restaurar desde la base. |
| 2 | Promo Mamá no tiene ninguna venta registrada en junio, aunque estuvo vigente todo el mes. | ⚠️ Abierto | Solo un respaldo de la base de junio podría mostrar si hubo ventas entre lo borrado. |
| 3 | 22 contactos de junio tenían el teléfono con un 0 de más (`591XXXXXXXX0`). El CRM no los reconocía al volver a escribir y creaba un duplicado. | ✅ Corregido | Se sacó el 0 a los 19 que no estaban duplicados. Se verificó que `findByPhone` ahora los encuentra. |
| 4 | 3 de ellos ya se habían duplicado. | ✅ Unidos | #84 → **#7992 "Mario"**, #146 → **#1215 "Sarah Ayad"**, #167 → **#1519 "Camilo Haibara"**. Queda el contacto con la conversación de WhatsApp; leads e historial pasaron a él y tiene una nota de la unión. |
| 5 | #182 Rimski: entregado el 03/07, pero las líneas se cargaron el 12/08. | ✅ Correcto | El chat de n8n confirma Icono Malbec + Raíz Tannat por Bs 405. Jorge las cargó tarde. |
| 6 | #73 Polo: líneas cargadas el 01/07; el agente lo reabrió el 11/06 (Entregado → Confirmado). | ⚠️ Sin verificar | Las líneas coinciden con el título (3× Tinto Clásico, Bs 66). No hay chat que muestre si el 11/06 fue un segundo pedido. |
| 7 | #158, #161 | ✅ Correcto | Líneas y valor coinciden. |

Respaldo: `docs/normalizacion_contactos_junio_respaldo.json`. Script: `storage/normalizar_contactos_junio.php` (si se vuelve a correr no hace nada).

## Productos y promociones

| Fecha | Qué pasó |
|---|---|
| 11/06 | Puesta en marcha: se reactivaron casi todos los productos (Estado No → Sí). |
| 15/06 | Se creó el Cabernet Sauvignon Block 63 (#41, Bs 63). Los Iconos (#10, #21, #24) bajaron de Bs 380 a Bs 330. |
| 29/06 09:33–11:40 | ⚠️ La **Promo Vinos Icónicos (#42) estuvo activa 2 horas, 28 días antes del lanzamiento oficial del 27/07**. Un cliente (Rimski) llegó citando el anuncio; no se vendió ninguna. |
| 29/06 12:13 | Se creó la Promo Varietales (#43, "Z Promo Varietales test"), 2 días antes del lanzamiento del 01/07. Está dentro de la tolerancia y no tuvo ventas en junio. |
| 29/06 | Fundador pasó de Bs 24 a Bs 25. |
| Junio | Danitza fue ajustando en qué ciudades se muestra cada producto (packs con copa fuera de La Paz, por ejemplo). |

## Por qué se concluye que lo borrado eran pruebas

- El CRM y la memoria de n8n perdieron la misma proporción (~80 %). Eso es lo esperable al limpiar conversaciones de prueba en los dos lados.
- Los 26 teléfonos que siguen en n8n están casi todos en el CRM (25 de 26). No hay clientes con chat vivo y lead borrado.
- Los huecos cubren días enteros, sobre todo del 01 al 16/06 (puesta en marcha el 11/06).
- Las pruebas del CRM y del agente creaban contacto, lead y actividades. Los tests con `DatabaseTransactions` hacen rollback, pero MySQL no devuelve los ids autoincrementales, así que dejan huecos sin dejar filas.
- El binlog de MySQL guarda solo 30 días (desde ~01/10). `binlog.000067` (hasta el 08/10) tiene 106 borrados de contactos y 42 de leads, que coinciden con la limpieza de basura de los tests (commit `bf805d9`).

## Pendiente para julio

- **96 contactos de julio** (#201–#358, hasta el 09/07) tienen el mismo 0 de más en el teléfono.
- Leads 218 (Enrique, sin interés) y 296 (José Luis, **entregado** Bs 239), borrados en la limpieza de duplicados del 28/09; están en `kl_dup_bkp_leads`.
