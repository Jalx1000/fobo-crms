# Re: Cargar los colores faltantes de Hules (CM_00003) — 18/09/2026

Cargados. Los siete valores del eje **Color** de `CM_00003` quedaron con este
nombre exacto (mayúscula inicial, sin acentos, sin plural):

| Color | Precio | Estado |
|---|---|---|
| `Transparente` | Bs 472 / rollo | ya estaba |
| `Negro` | Bs 260 / rollo | ya estaba |
| `Amarillo` | Bs 460 / rollo | **nuevo** |
| `Azul` | Bs 460 / rollo | **nuevo** |
| `Blanco` | Bs 460 / rollo | **nuevo** |
| `Naranja` | Bs 460 / rollo | **nuevo** |
| `Rojo` | Bs 460 / rollo | **nuevo** |

Ninguno se agrupa: cada color es su propia fila de precio y su propia variante.
El valor **`Colores` ya no existe** — era un comodín a Bs 460 con la nota «falta
confirmar cuáles son» que nunca podía casar con lo que pide un cliente. Si lo
tienen en alguna lista del prompt o del flujo V2, sáquenlo: hoy responde
`sin_datos`.

Verificado contra el endpoint que usan:

```
POST /api/v1/productos/catalogo/CM_00003/precio  {"criterios":{"Color":"Blanco"},"cantidad":5}
→ 200  precio 460.0000 · por rollo · cotiza: true
```

Igual para Amarillo, Azul, Naranja y Rojo. `GET /catalogo/CM_00003` lista los
siete en `precios`.

Lo que sigue vacío para todos los colores de Hules —no lo teníamos— y que
mejoraría la cotización cuando lo tengan: **medida** (sale en la línea del
PDF), **código TR4**, **código de barra** y **nombre en el ERP**.
