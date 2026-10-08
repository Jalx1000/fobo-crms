# Respuesta: el replay de la caída por cuota de OpenAI

> Medido en el CRM el 2026-09-26 sobre la ventana que nos dieron: desde el
> 2026-09-25 14:00 (hora de La Paz, que es como guarda las marcas el CRM).

## Resumen

**No hay una caída visible desde el CRM.** El agente respondió en todas las horas
de la franja. Sin respuesta quedaron **9 mensajes en 5 conversaciones**, y 8 de
ellos tienen una explicación que no es la cuota.

**Para re-emitir hay 1 mensaje**, y hay que hablar de otra cosa: en 3
conversaciones el bot está apagado del lado del CRM y el replay no cambiaría nada.

## Por qué decimos que no se ve la caída

Respuestas del agente (`direction = outbound`, `sender = 'ia'`) por hora:

```
2026-09-25 14:00   12     18:00    7     22:00    5
           15:00   10     19:00    7     23:00    5
           16:00    9     20:00   11   26 00:00    1
           17:00   20     21:00   11      01:00    1
                                          02:00   16
```

Ninguna hora en cero. El hueco más largo sin que el agente hablara fue de **83
minutos** (23:23 → 00:46), de madrugada y con un solo mensaje entrante en esa
franja: es tráfico bajo, no una interrupción.

La latencia de respuesta tampoco muestra nada: **mediana de 0,1 a 0,3 minutos en
todas las horas**, incluidas las de la supuesta caída. Si el agente hubiera
estado caído y hubiera recuperado después, veríamos una franja con latencias de
horas. No aparece.

## Los 9 sin respuesta, uno por uno

| conv | mensaje | cuándo | qué pasó |
|---|---|---|---|
| **2526** | 10386 | 25/09 14:37 | 🟢 **Único candidato real.** Bot activo, primer y único mensaje del contacto, sin respuesta. El CRM lo procesó bien (creó persona 3406 y pedido 2861 a las 14:37:02) y les mandó el evento. |
| 2395 | 10424-10426, 10530 | 25/09 15:46 y 20:27 | 🔴 `ai_enabled = false`: **el bot está apagado en esa conversación.** |
| 2355 | 10450 | 25/09 17:01 | 🔴 `ai_enabled = false`. Además el último mensaje saliente lo mandó un humano. |
| 1365 | 10563 | 25/09 21:49 | 🔴 `ai_enabled = false`. Último saliente, de un humano. |
| 2571 | 10648 | 26/09 02:19 | ⚪ Es una **reacción** (👍🏼). Por diseño no despierta al agente, y el agente ya había contestado el texto 14 s antes. |
| 2744 | 10749 | 26/09 02:40 | ⚪ Es una **imagen**, 23 s después de que el agente contestara el texto. Díganos si esperan recibir evento por imágenes. |

## Lo que sí conviene mirar: el bot apagado

Tres de las cinco conversaciones tienen `ai_enabled = false` **sin derivación
abierta**. O sea que alguien apagó el bot a mano desde el CRM y nadie lo volvió
a prender; no es que se haya perdido una respuesta.

Re-emitir esos eventos no serviría: nuestro `aiEffective()` los filtra antes de
avisarles, y está bien que lo haga. Si el negocio quiere que el bot vuelva a
atenderlas, hay que prenderlo en el CRM, no re-emitir.

La más cara es la **2395 (Micaela Careaga, +59173392245)**: escribió *"Me interesa
esa promoción"* y *"Hola quiero pedir una Promo Primavera"* y nadie le contestó
desde ayer. Eso es una venta parada, pero la causa es el interruptor, no OpenAI.

## Lo que hacemos

Con un solo mensaje no hace falta montar un replay: **les re-emitimos el evento
de la conv#2526** apenas nos confirmen, con el mismo endpoint, el mismo bearer y
el `reply.url` vigente. Sin ritmo ni escalonado: es uno.

Los filtros que pidieron ya están aplicados en la tabla de arriba:
derivaciones abiertas (**0** en esta ventana), ya respondidas después de reponer
crédito (excluidas por construcción: la consulta busca entrantes **sin ningún
saliente posterior**), y ventana de 24 h (**los 9 están dentro**, así que no hay
ninguno que necesite plantilla).

## Si la ventana era otra

La consulta es directa y la podemos correr con cualquier rango:

```sql
SELECT m.id, m.conversation_id, m.created_at, c.wa_phone,
       c.handoff_state, c.ai_enabled, c.last_inbound_at
FROM whatsapp_messages m
JOIN whatsapp_conversations c ON c.id = m.conversation_id
WHERE m.direction = 'inbound'
  AND m.created_at BETWEEN :desde AND :hasta
  AND NOT EXISTS (
    SELECT 1 FROM whatsapp_messages r
    WHERE r.conversation_id = m.conversation_id
      AND r.direction = 'outbound' AND r.id > m.id);
```

Si tienen del lado de ustedes la hora exacta en que empezaron a fallar las
llamadas a OpenAI, pásenla y la corremos sobre esa franja. Con lo que vemos
hasta ahora, el impacto en clientes fue de un mensaje.
