# Auditoría de datos — Mayo 2026 (cerrada el 31/10/2026)

Promo del mes: **Promo Mamá Elia Rosa**, lanzada el 28/05, a Bs 300 (precio regular Bs 455) y vigente hasta el 30/06.

## Hallazgos y cómo quedaron

| # | Hallazgo | Estado | Qué se hizo |
|---|---|---|---|
| 1 | Promo Mamá registrada como "Icono Elia Rosa" (#19) en los leads 69, 70 y 72. El #19 era la Promo Mamá y después se renombró. | ✅ Corregido | Esas líneas se pasaron al producto #183 "Promo Mamá Elia Rosa" (inactivo), manteniendo el precio de venta. Cada lead tiene una nota. |
| 2 | Al lead 72 (Fer Candia, entregado el 19/06) el agente le cargó encima el 30/10 un pedido nuevo. | ✅ Restaurado | Volvió a Entregado por Bs 600, cerrado el 19/06 y a nombre de Jorge Bares. El pedido de octubre (2× Querkus, Bs 540) y la conversación 8088 pasaron al lead nuevo #7525. Respaldo en `docs/restauracion_lead_72_respaldo.json`. |
| 3 | Enrique Pomar figuraba dos veces: el #62 (28/05, teléfono mal cargado 591765627290) y el #1325 (09/09, 59176562729). | ✅ Unido | Quedó solo el #1325 "Enrique Pomar", que tiene la conversación de WhatsApp. El lead 70 y las actividades del #62 pasaron al #1325 y el #62 se borró. Respaldo en `docs/union_persona_62_1325_respaldo.json`; script en `storage/unir_persona_62_1325.php`. |
| 4 | Según la nota de Stefani del 11/06, Heber (#61, tel. 71214179) sería el mismo cliente que Enrique. | ✅ Decidido | Se dejó como contacto aparte, por decisión del usuario. La nota de la unión en el #1325 lo menciona. |
| 5 | El lead 70 vale Bs 594, pero sus líneas suman Bs 420. | ✅ Documentado | Los Bs 174 que faltan son el Pack Red Blend + Copa (#34) y el Pack Tannat + Copa (#26). Los dos productos se borraron y sus líneas se borraron con ellos. El valor se mantiene y el lead tiene una nota. |
| 6 | 9 productos no se mostraban en La Paz del 29/05 al 29/06. | ✅ Sin cambios | Fue a propósito: faltaban packs con copa en la regional (nota de Stefani). |

## Visto en mayo, pero corresponde a julio

| Lead | Detalle | Estado |
|---|---|---|
| 218 | Enrique Pomar, 01/07, 1× Promo Varietales por Bs 239. Stefani lo pasó a "Sin interés" el 03/07 y el chat de n8n termina el 01/07. No fue venta. | Borrado el 28/09 por la limpieza de duplicados; está en `kl_dup_bkp_leads`. Se decide en la auditoría de julio. |
| 296 | José Luis, 04/07, **Entregado**, Bs 239. | Borrado el 28/09 sin tener duplicado; hoy no cuenta como venta de julio. Se decide en la auditoría de julio. |

## Notas

- Antes de las correcciones el usuario hizo un respaldo de la base.
- Todos los cambios se hicieron directo en la base y en transacciones, sin disparar eventos (sincronización de ciudad ni avisos).
- Los archivos de `docs/` contienen datos personales de clientes y no están en `.gitignore`.
