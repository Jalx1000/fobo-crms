# Plan: Cotización con formato RG-VCO-005

**Spec:** `docs/specs/cotizacion-rg-vco-005-spec.md`
**Fecha:** 2026-09-11

---

## Componentes y dependencias

```
                   ┌──────────────────────┐
                   │ 1. Atributos cotiz.  │  SQL · sin código · sin dependencias
                   │    + validez 10 días │
                   └──────────────────────┘

┌──────────────────────┐    ┌──────────────────────┐
│ 2. Producto: entrega │    │ 3. Variantes         │  ← migraciones + modelo
│    (3 columnas)      │    │    (tabla + ejes_id) │
└──────────┬───────────┘    └──────────┬───────────┘
           │                           │
           │                ┌──────────▼───────────┐
           │                │ 4. Formulario de     │  ← _variantes.blade.php
           │                │    variantes         │
           │                └──────────┬───────────┘
           │                           │
           │                ┌──────────▼───────────┐
           │                │ 5. Seeder: variantes │  ← datos del PDF real
           │                │    + entrega + mínimo│     (también usa 2)
           │                └──────────┬───────────┘
           │                           │
┌──────────▼───────────┐    ┌──────────▼───────────┐
│ 6. Reglas de entrega │    │ 7. Ítem = foto de la │  ← quote_items + repo
│    (Support/Entrega) │    │    variante          │
└──────────┬───────────┘    └──────────┬───────────┘
           │                           │
           └─────────────┬─────────────┘
                         │
              ┌──────────▼───────────┐
              │ 8. Cotización en     │  ← create/edit: columnas de ítem,
              │    pantalla          │     condiciones propuestas
              └──────────┬───────────┘
                         │
              ┌──────────▼───────────┐    ┌──────────────────────┐
              │ 9. API del agente    │    │ 10. PDF RG-VCO-005   │  ← depende de 7 y 8
              │    (cotizacion +     │    │                      │
              │     precio)          │    └──────────┬───────────┘
              └──────────┬───────────┘               │
                         │                           │
              ┌──────────▼───────────────────────────▼──┐
              │ 11. Documento y prompt para el agente   │
              └─────────────────────────────────────────┘
```

## Orden de implementación

| Fase | Pasos | Se puede frenar después | Por qué en este orden |
|---|---|---|---|
| **A · Datos** | 1, 2, 3, 4, 5 | ✅ el CRM guarda códigos y entrega; nada los usa todavía | Sin datos no hay nada que mostrar ni copiar |
| **B · Lógica** | 6, 7 | ✅ las cotizaciones nuevas llevan la foto; el PDF viejo la ignora | La foto tiene que existir antes de que el PDF la lea |
| **C · Pantalla** | 8 | ✅ el asesor ve y edita códigos y condiciones | Valida el modelo con un humano antes de fijarlo en el PDF |
| **D · Salida** | 9, 10 | ✅ | El PDF es lo que el cliente ve: va último y **se mira** |
| **E · Agente** | 11 | - | Documenta lo que ya funciona |

**Paralelizable:** 2 ∥ 3, y 6 ∥ 7 (una vez que 2 y 3 existen). El resto es secuencial.

## Riesgos

| Riesgo | Mitigación |
|---|---|
| Pisar datos en producción (ayer se perdieron 292 contactos) | Solo migraciones reversibles; seeder idempotente por SKU; **jamás** la suite completa |
| La foto de la variante no coincide con lo que el asesor ve en pantalla | Paso 8 antes que 10: el asesor valida en pantalla antes de que el PDF sea la verdad |
| dompdf renderiza distinto a lo que el HTML sugiere (ya pasó con las firmas) | Rasterizar con `gs` y mirar; el test del PDF no cierra el paso, la imagen sí |
| El agente pasa criterios que no resuelven variante | Variante ausente = códigos vacíos, no error; el asesor los completa |
| Cambiar `expired_at` default rompe cotizaciones del agente | `cotizacion_vence_dias` ya está en config; se unifica en `config/cotizacion.php` |
| El formulario de cotización es Vue (`v-quote-item-list`) y agregar columnas rompe el cálculo de totales | Las columnas nuevas son informativas, no numéricas; el cálculo no las lee |

## Puntos de verificación

- **Fin de A:** `SELECT * FROM kl_product_variantes` muestra 5 filas de bolsas con TR4; el formulario de producto las lista y edita.
- **Fin de B:** crear una cotización por API para 50 L HORECA SC deja un ítem con `codigo_tr4 = 020117-0035`; borrar el producto no lo cambia.
- **Fin de C:** en pantalla, un pedido de tapas impresas + bolsas propone "20 días · Recoge en planta"; el asesor lo cambia y al guardar queda lo suyo.
- **Fin de D:** PDF rasterizado con las 9 columnas, condiciones, firmas, código de documento. Comparado visualmente contra la propuesta real.
- **Fin de E:** el agente crea una cotización cuyo PDF es igual al de una creada en pantalla.
