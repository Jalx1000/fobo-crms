# Spec: la ventana de la derivación — cuándo y hasta cuándo

**Estado:** esperando revisión · **Fecha:** 2026-10-09

## Objetivo

Poder responder, para cualquier conversación de WhatsApp, **cuándo un asesor
tomó el caso y hasta cuándo lo tuvo**.

Hoy no se puede. Existen `handoff_requested_at` y `assigned_at`, pero **no hay
marca de cierre**: hay 48 derivaciones en estado `resolved` y de ninguna se
puede decir cuánto duró. La hora del cierre sólo vive en el texto de una
actividad de auditoría, que sirve para leer un caso suelto y no para medir.

**Para quién:** supervisión. Las preguntas que hoy quedan sin respuesta:

- ¿Cuánto tiempo tuvo un asesor una conversación antes de devolverla?
- ¿La cerró una persona o la cerró el cron a las 6 h?
- ¿Cuántas derivaciones se están resolviendo solas por abandono?

El dato ya existe parcialmente y apunta a que el problema es real: de **57
cierres registrados en el log, 52 fueron automáticos** — o sea que 9 de cada 10
derivaciones las termina el reloj, no un humano. Eso merece ser medible.

## Fuera de alcance, explícitamente

- **Acotar el tiempo de respuesta del asesor a la ventana de la toma.**
  Decidido el 2026-10-09: no se hace. Habrá veces en que el cliente no pida
  un humano y el asesor escriba igual, y esas respuestas también cuentan.
- **Cambiar cómo se cuentan los mensajes seguidos.** Decidido el 2026-10-09:
  se cuentan todas las respuestas, como hoy. Queda documentado que eso infla
  la mediana del asesor —13% de sus respuestas son el 2.º o 3.er mensaje
  contra el mismo entrante, contra 3% del agente— y es un costo aceptado.
- Alerta por fallos de envío, limpieza de fantasmas y la campaña de
  plantillas: pendientes de decisión, cada uno con su propio spec.

## Lo que hay hoy

```
handoff_requested_at   ✓  cuándo se pidió
assigned_at            ✓  cuándo la tomó el asesor
handoff_state          ✓  requested | assigned | resolved
assigned_user_id       ✓  quién la tiene
handoff_resolved_at    ✗  NO EXISTE
```

Estado actual de los datos:

```
sin derivación     923
resolved            48   (46 con assigned_at, o sea medibles si hubiera cierre)
assigned             1
```

`Handoff::resolve()` se llama desde dos lugares: `InboxController` (un asesor
cierra a mano) y `ReactivateAgent` (el cron a las 6 h).

## Commands

```bash
# Tests
docker exec -u www-data -w /code -e XDG_CONFIG_HOME=/tmp -e HOME=/tmp <contenedor> ./vendor/bin/pest
docker exec ... ./vendor/bin/pest tests/Feature/WhatsappHandoffTest.php

# Estilo (nunca sobre directorios)
./vendor/bin/pint packages/Webkul/Whatsapp/src/Services/Handoff.php

# Migración
docker exec ... php artisan migrate --force

# Assets, si se toca una vista
cd packages/Webkul/Admin && npm run build
```

## Project Structure

```
packages/Webkul/Whatsapp/src/Database/Migrations/   la columna nueva
packages/Webkul/Whatsapp/src/Models/Conversation.php  cast y fillable
packages/Webkul/Whatsapp/src/Services/Handoff.php     escribe el cierre
packages/Webkul/Whatsapp/src/Services/HandoffReport.php   (nuevo) la consulta
tests/Feature/WhatsappHandoffTest.php                  tests
```

## Code Style

Como el resto del módulo: el comentario explica **por qué**, con el dato que lo
motivó, no qué hace la línea.

```php
/**
 * Cuándo se cerró la derivación, y quién la cerró.
 *
 * `assigned_at` decía cuándo empezaba la toma y nada decía cuándo terminaba:
 * 48 derivaciones resueltas sin forma de saber cuánto duraron. La hora estaba
 * sólo en el texto de la auditoría, legible de a una pero no medible.
 *
 * `resolved_by` distingue el cierre humano del automático: de 57 cierres
 * registrados, 52 los hizo el cron a las 6 h.
 */
$table->timestamp('handoff_resolved_at')->nullable();
$table->unsignedInteger('handoff_resolved_by')->nullable();
```

## Testing Strategy

Pest, en `tests/Feature/`. La suite corre contra la base de **producción**, así
que todo va dentro de `DatabaseTransactions` o `DB::beginTransaction()`.

Casos a cubrir:

1. Cerrar a mano deja `handoff_resolved_at` y `handoff_resolved_by` con el id
   del asesor.
2. El cierre del cron deja la fecha y `handoff_resolved_by` en `null`.
3. Volver a derivar la misma conversación **limpia** las dos columnas: una
   derivación nueva no hereda el cierre de la anterior.
4. La duración se calcula desde `assigned_at`, y desde
   `handoff_requested_at` cuando nunca se asignó (quedó en el pool).
5. El reporte no cuenta las derivaciones abiertas como duración 0.

## Boundaries

- **Siempre:** correr la suite completa antes de commitear; Pint sobre archivos
  individuales; los comandos de artisan dentro del contenedor como `www-data`.
- **Preguntar primero:** cualquier escritura masiva en producción; el backfill
  de las 48 derivaciones ya resueltas; agregar la tarjeta al tablero.
- **Nunca:** mandar mensajes ni correos a clientes como parte de esto; tocar
  `handoff_state`, que ya funciona; inventar una fecha de cierre para el
  histórico que no la tiene.

## Success Criteria

1. Una derivación cerrada a mano guarda fecha y autor; una cerrada por el cron
   guarda fecha y autor `null`.
2. `HandoffReport` devuelve, para un rango de fechas: cantidad de derivaciones,
   mediana y p90 de duración, y el reparto entre cierre humano y automático.
3. Se puede responder "esta conversación la tuvo María Rene de 10:05 a 16:00"
   sin leer el log de actividades.
4. El histórico **no se inventa**: las 48 ya resueltas quedan con
   `handoff_resolved_at = null` y el reporte las excluye diciendo cuántas
   excluyó.
5. La suite sigue en verde (hoy: 302 pasan, 11 salteados).

## Open Questions

1. **¿El backfill del histórico?** La hora del cierre está en el texto de la
   actividad de auditoría y se podría extraer para las 48. Es un parseo de
   texto libre, así que puede fallar en casos raros. Mi recomendación: no
   hacerlo, y que el reporte arranque limpio desde hoy.
2. **¿Tarjeta en el tablero o sólo el comando?** El dato más fuerte —52 de 57
   cierres son automáticos— es de supervisión y encajaría en el tablero, pero
   suma una tarjeta más. Alternativa: un comando que se corre cuando se quiere.
3. **¿Qué duración se le asigna a una derivación que el cliente nunca
   contestó?** Hoy el cron la cierra a las 6 h. Esa duración mide el abandono
   del cliente, no el trabajo del asesor, y mezclarla con las reales distorsiona
   la mediana. Propongo reportarlas aparte.
