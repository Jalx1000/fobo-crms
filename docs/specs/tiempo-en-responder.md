# Spec: Tiempo en responder, en horas hábiles

Estado: **aprobado** (16/10/2026). Resuelve en parte la pregunta P5 de `tablero-consistente.md`.

## Objetivo

La tarjeta «Tiempo en responder» cuenta **solo horas laborales**: lunes a viernes de 08:00 a 19:00
(hora de La Paz), el horario de los seis ejecutivos de la tabla del 16/10. Hoy cuenta las 24 horas de
los 7 días, así que un pedido que llega el viernes a la noche suma todo el fin de semana.

## Alcance (decisión del usuario, 16/10)

- **Solo cambia cómo se cuentan las horas.** El intervalo que se mide es el mismo de hoy (desde que
  se creó el pedido hasta el momento de su etapa actual, o hasta ahora si sigue abierto).
- No cambia qué cuenta como respuesta, ni desde cuándo corre el reloj, ni cómo se muestran los
  pendientes. Eso queda para otro momento si hace falta.

## Cuándo para el reloj (decisión del usuario, 16/10, tras corroborar con Bulmaro)

Al revisar los 185 pedidos de Bulmaro se vio que `confirmed_at` nunca se llena y `closed_at` solo en
CANCELADOS/ENTREGADOS: el reloj corría hasta «ahora» en casi todos. Regla nueva:

- **Para** cuando el pedido entra a una etapa final: **SIN INTERÉS, ENTREGADOS, CANCELADOS, OTROS
  SERVICIOS** (`Stage::TONOS` ganado + perdido). La fecha sale del historial («Actualizado Etapa»,
  la última entrada a esa etapa, comparando por **código** porque cada ciudad tiene sus etapas con otro
  id); si no hay historial, `closed_at` y después `updated_at`.
- En **cualquier otra etapa** (NO ATENDIDOS, SOLICITUD RECIBIDA, COTIZACIÓN ENVIADA) sigue contando
  hasta ahora.

## Regla de horas

Horas hábiles entre dos momentos = solo los tramos de **lunes a viernes, 08:00–19:00**. Ejemplos:
- viernes 18:00 → lunes 09:00 = **2 h** (antes 63 h);
- llega el domingo y se mide el lunes 10:00 = **2 h** (cuenta desde el lunes 08:00);
- martes 20:00 → miércoles 08:30 = **0,5 h**.

El horario sale de `cotizacion.horario_atencion` (ya existe: 08:00–19:00) más
`cotizacion.dias_laborales` (nuevo: lunes a viernes). Feriados: no se consideran.

## Cambios

- `Webkul\Admin\Helpers\HorasHabiles::segundos($desde, $hasta)`: el cálculo, en un solo lugar.
- `Reporting\Lead::getAverageResponseTimeByUsers()`: promedia por ejecutivo las horas hábiles de
  cada pedido (en PHP, por pedido) en lugar del `TIMESTAMPDIFF` de 24 h en SQL.

## Tests

1. Los tres ejemplos de arriba, más un intervalo dentro del mismo día (10:00 → 12:30 = 2,5 h).
2. La tarjeta, para un pedido creado el viernes 18:00 y medido el lunes 09:00, da 2 h.
3. En cada etapa final el reloj para al entrar (viernes 18:00 → lunes 10:00 = 3 h), también si el
   pedido cambió de ciudad; en las demás sigue hasta ahora (→ miércoles 12:00 = 27 h).
