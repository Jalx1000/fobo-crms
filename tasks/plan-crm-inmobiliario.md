# Plan técnico - CRM inmobiliario Century 21

**Spec:** `docs/specs/crm-inmobiliario-c21.md` (Fase 1, aprobada)
**Alcance:** solo escrituras en la DB `kohlberg`. Cero cambios de código. Cero n8n.

---

## Riesgos y mitigaciones

### R1 - `ON DELETE CASCADE` en `kl_leads.lead_pipeline_id` 🔴

Verificado en `information_schema`: borrar una fila de `kl_lead_pipelines` **borra en cascada
todos sus leads**. El pipeline 9 (Sucre) tiene 2 leads reales.

> **Mitigación:** los leads se migran al pipeline destino **antes** de cualquier `DELETE`, y cada
> borrado va precedido de una aserción `COUNT(*) = 0` sobre los leads de ese pipeline. Si la
> aserción falla, el script aborta.

### R2 - `SET NULL` en `lead_pipeline_stage_id`

Esa FK es `ON DELETE SET NULL`: borrar una etapa deja los leads sin etapa en vez de fallar
ruidosamente. Un lead con `lead_pipeline_stage_id = NULL` desaparece del kanban.

> **Mitigación:** reasignar `lead_pipeline_stage_id` en el mismo `UPDATE` que cambia el pipeline,
> nunca en pasos separados. Verificación final: cero leads con etapa nula o cruzada de pipeline.

### R3 - Los 24 atributos aparecen también en los leads de imprenta

Consecuencia inevitable de la decisión de 2 pipelines: los atributos son por entidad. El
formulario de imprenta va a mostrar campos inmobiliarios.

> **Mitigación:** `is_required = 0` en los 24 (no bloquean el guardado) y `sort_order >= 100`
> (quedan al final del formulario). La tarea 07 verifica explícitamente que un lead de imprenta
> se puede guardar sin tocarlos.

### R4 - El renombre de etiquetas afecta a los dos negocios

"Valor del Pedido" → "Valor / comisión esperada" también lo ve el operador de imprenta.
Aprobado explícitamente en Q1. Reversible con un `UPDATE` de una línea.

### R5 - Cambiar `is_default` redirige leads nuevos

Pasar el default al embudo inmobiliario hace que todo lead creado sin pipeline explícito
(webforms, integraciones) caiga ahí en vez de en imprenta.

> **Mitigación:** es la última tarea antes de la verificación, aislada, para poder revertirla
> sola si el flujo de imprenta se resiente.

---

## Orden de ejecución y dependencias

```
00 backup ──┬──> 01 renombre etiquetas        (independiente)
            ├──> 02 pipeline + 7 etapas       (independiente)
            ├──> 04 atributos + opciones      (independiente)
            └──> 05 sources + types           (independiente)

02 ──> 03 consolidar imprenta ──> 06 is_default ──> 07 verificación
                                        ▲
        01, 04, 05 ─────────────────────┘
```

- **00 es bloqueante absoluto.** Nada se escribe antes del dump.
- **01, 02, 04, 05 son paralelizables** - tocan tablas disjuntas. En la práctica se corren en
  secuencia porque son rápidos, pero un fallo en uno no ensucia a los otros.
- **03 depende de 02** solo por claridad operativa (querés ver el embudo nuevo funcionando antes
  de tocar los datos vivos de imprenta). Técnicamente son independientes.
- **06 va último** por R5.
- **07 no escribe nada.**

## Checkpoints de verificación

| Después de | Verificar |
|---|---|
| 00 | El `.sql` existe, pesa > 0 y contiene `CREATE TABLE .kl_leads.` |
| 02 | 7 etapas en orden; `won`/`lost` en `sort_order` 6 y 7 |
| 03 | 2 pipelines; 20 leads; 523 actividades; 0 leads con etapa nula o cruzada |
| 04 | 24 atributos, `SUM(is_required) = 0`, `SUM(is_unique) = 0`; opciones por select |
| 05 | 13 sources, 7 types |
| 06 | Exactamente un pipeline con `is_default = 1` |
| 07 | Smoke test manual en la UI (5 pasos del spec) |

## Rollback

`99-rollback.sql` revierte 01, 02, 04, 05 y 06 (borra lo creado, restaura etiquetas y el default
original). **La tarea 03 no es reversible por script** - la consolidación de pipelines y el
borrado de los 6 sobrantes solo se revierte restaurando el dump de la tarea 00. Por eso 03 tiene
su propio checkpoint y no se corre en el mismo lote que las demás.
