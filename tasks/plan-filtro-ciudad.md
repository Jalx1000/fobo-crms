# Plan técnico — Filtro por ciudad (pipeline) en WhatsApp

Spec: [`spec-filtro-pipeline-whatsapp.md`](./spec-filtro-pipeline-whatsapp.md) · **aprobada 2026-09-07**
Tareas: [`todo-filtro-ciudad.md`](./todo-filtro-ciudad.md)
Rama: `kohlberg-wa-v2` · Estimado: **1 día**

> `tasks/plan.md` y `tasks/todo.md` son del Sprint 08, que sigue abierto. De ahí el sufijo.

---

## Grafo de dependencias

```
┌──────────────────────────────────────────────┐
│ F1 · ConversationCity: la expresión SQL      │  sin dependencias
└───────────────┬──────────────────────────────┘
                │  la usan las tres de abajo
    ┌───────────┼────────────────┬──────────────────┐
    │           │                │                  │
┌───▼──────┐ ┌──▼─────────────┐ ┌▼───────────────┐  │
│ F2 ·     │ │ F3 · endpoint  │ │ F4 · chip de   │  │
│ filtro   │ │ cities()       │ │ ciudad en fila │  │
│ ?city=   │ │ con conteos    │ │                │  │
└───┬──────┘ └──┬─────────────┘ └┬───────────────┘  │
    └───────────┴────────────────┴──────────────────┘
                        │
            ┌───────────▼──────────────┐
            │ F5 · desplegable (Vue)   │
            └──────────────────────────┘
```

**Camino crítico:** F1 → F2 → F5. F3 y F4 cuelgan de F1 y no se estorban.

---

## Orden, y por qué ese

### F1 · `Support/ConversationCity` — primero, y solo

La expresión SQL de la cascada: `COALESCE(handoff_city_id, atributo del contacto, pipeline del lead)`.

Va primero porque **la consumen tres lugares** —el `where` del filtro, el `select` de la fila y
el `groupBy` del conteo— y escrita tres veces es garantizar que un día devuelvan cosas
distintas. Ya validé la expresión contra la base: reparte las 40 conversaciones en
21 sin ciudad / Santa Cruz 10 / Cochabamba 5 / Potosí, Tarija, La Paz y "Sin ciudad" 1 cada una.

Cuidado con el prefijo: las tablas son `kl_*` y un `DB::raw` no lo agrega solo.

### F2 · El filtro `?city=`

Un `whereRaw` sobre la expresión. Tres formas de valor:

- `city=3` → esa ciudad
- `city=none` → sin ciudad **y** el pipeline llamado "Sin ciudad" (decidido: son lo mismo)
- ausente → la lista de hoy, sin cambios

Se agrega **después** de los filtros existentes en el método, para que combine en vez de
reemplazar (criterio 4).

### F3 · `cities()` con conteos

Una sola consulta agrupada por la expresión. Devuelve `[{id, name, count}]` más el conteo de
`none`. Las ciudades con 0 no salen: un desplegable con siete opciones vacías no ayuda.

El conteo respeta la deduplicación de la lista (el `ROW_NUMBER` que ya existe), o los números
del desplegable no van a coincidir con lo que se ve. **Este es el detalle que más fácil sale
mal.**

### F4 · El chip en la fila

Un `selectSub` con la expresión + el nombre desde el `pluck` de `lead_pipelines` que el
controlador **ya hace** (dos consultas sueltas para evitar N+1). Cero consultas nuevas.

### F5 · El desplegable

Un `<select>` estándar arriba de los chips de etapa. Sin clases de valor arbitrario: el CSS del
admin es del 28-08 y esas clases no existen en él — es lo que dejó el mapa invisible.

---

## Riesgos

| # | Riesgo | Prob. | Mitigación |
|---|---|---|---|
| R1 | Los conteos del desplegable no coinciden con la lista (dedup por `ROW_NUMBER`) | **alta** | El conteo usa la misma subconsulta de ranking. Criterio 5 lo verifica sumando. |
| R2 | El `DB::raw` sin prefijo `kl_` rompe la consulta | media | `DB::getTablePrefix()` en la expresión; ya me pasó al medir. |
| R3 | Clases de Tailwind arbitrarias que no existen en el CSS | media | Sólo clases estándar. Verificar en browser real. |
| R4 | Duplicar la lógica de ciudad al implementar F2/F3/F4 por separado | media | F1 primero y única fuente; hay test que compara las tres salidas. |
| R5 | La cascada agrega N+1 en una lista de 30 filas | baja | Subconsultas correlacionadas en el `select`, no consultas por fila. Criterio 11. |
| R6 | Un asesor pierde acceso a conversaciones que hoy ve | baja | El filtro es opt-in y no toca ACL. Está en *Never*. |

---

## Decisiones técnicas

1. **Expresión SQL, no atributo de modelo.** Un accessor en PHP no sirve para filtrar ni
   agrupar sin traer todo a memoria.
2. **`none` como valor de texto**, no un id mágico. Un `city=0` se confunde con "sin filtro".
3. **El pipeline "Sin ciudad" cae en `none`.** Para el asesor es lo mismo que no tener ciudad;
   distinguirlos sería una opción que nadie sabría cuándo usar.
4. **Sin columna cacheada.** 40 conversaciones; si algún día pesa, se cachea entonces.
5. **El filtro no se autoselecciona por la ciudad del asesor.** Es política de permisos, y está
   fuera de alcance por decisión explícita de la spec.

---

## Checkpoints

| Después de | Verificación | Cómo |
|---|---|---|
| F1 | Reparte las 40 igual que la medición manual | test contra la base real |
| F2 | `city=3` da 10; `city=none` da 22; sin parámetro no cambia nada | `--filter=filtro` |
| F3 | La suma de conteos = total de conversaciones | `--filter=conteo` |
| F4 | Cada fila trae `city`, y la lista no suma consultas | assert de `DB::listen` |
| F5 | Se ve el desplegable y **cero errores en consola** | browser real |
| cierre | Suite sin fallos nuevos sobre 9 preexistentes | suite completa como www-data |
