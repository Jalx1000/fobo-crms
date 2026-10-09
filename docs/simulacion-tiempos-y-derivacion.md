# Cómo responde el agente, cómo se mide el tiempo y cómo toma un asesor

> Estado al 2026-10-09. Todo lo que dice "hoy" está verificado contra el código
> y la base de producción; lo que falta está marcado como tal.

---

## 1. La simulación, paso a paso

Un cliente nuevo escribe a las **10:00:00**.

### 10:00:00 — entra el mensaje

Meta golpea el webhook. `IngestInboundPayload` hace, en este orden:

1. Resuelve o crea la **conversación** por el teléfono.
2. Si no hay contacto, crea la **persona** (`WHATSAPP_AUTO_CREATE_PERSON`).
3. `LeadOpener` abre un **pedido** en la ciudad del contacto; si no se sabe, en
   el pipeline "Sin ciudad" a nombre del revisor por defecto.
4. Guarda el mensaje con `direction = inbound`, `sender = contact`.
5. Actualiza `last_inbound_at` — **éste es el reloj de la ventana de 24 h**.

### 10:00:01 — se le avisa al agente

```php
if ($conversation->aiEffective() && $created->type !== 'reaction') {
    NotifyAgent::dispatch($created->id);
}
```

Dos compuertas, las dos importan:

- **`aiEffective()`** es `false` si hay una derivación abierta o si alguien apagó
  el interruptor. Mientras haya una persona del otro lado, el agente no se
  enteran del mensaje: no es que se calle, es que no lo recibe.
- **Las reacciones no lo despiertan.** Un 👍 no es un turno de conversación.

El payload que recibe lleva `contact`, `lead` (con `is_empty`, `products`,
`lead_value`), `history`, el estado de la ventana y la `reply.url`.

### 10:00:06 — contesta

El agente postea a `POST /api/v1/whatsapp/conversations/{id}/messages`. Se
guarda con `direction = outbound`, `sender = ia`, y sale por la Cloud API.

**Latencia registrada: 6 segundos.** Es la mediana real medida sobre 5.497
respuestas.

### 10:05 — el cliente pide un humano

El agente llama a `POST /conversations/{id}/handoff`. `Handoff::request()`:

```
ai_enabled           → false
ai_disabled_at       → 10:05            (marca el inicio del silencio del bot)
handoff_state        → assigned  (o requested si no hay asesor para la ciudad)
handoff_city_id      → la ciudad resuelta en cascada
assigned_user_id     → el Encargado de esa ciudad
handoff_requested_at → 10:05
assigned_at          → 10:05
```

Desde este momento `aiEffective()` devuelve `false` y **cada mensaje que entre
ya no le llega al agente**. El asesor recibe un mail y ve la conversación arriba
del listado.

### 15:08 — responde el asesor

Escribe desde el inbox del CRM. Se guarda `sender = human` y `user_id` con su id.

**Latencia registrada: 5 h 3 min.** Es la mediana real de los asesores.

### 16:00 — cierra la derivación

`Handoff::resolve()` deja `handoff_state = resolved`, `ai_enabled = true`,
`ai_disabled_at = null`, y escribe la auditoría. El agente vuelve a recibir los
mensajes entrantes.

### Si nadie cierra

`whatsapp:reactivar-agente` corre **cada hora** y, a las **6 h** de
`ai_disabled_at`, cierra la derivación y devuelve la conversación al bot. El
plazo cuenta desde el apagado, no desde el último mensaje: es un tope a cuánto
puede estar mudo el bot, no una medida de inactividad. Se configura con
`WHATSAPP_AI_REACTIVATE_HOURS` (0 lo desactiva).

---

## 2. Cómo se mide el tiempo

La regla, en una línea: **desde el último mensaje del cliente hasta la respuesta
que le siguió.**

```
10:00:00  cliente    "hola"
10:00:02  cliente    "quiero una promo"        ← el reloj arranca ACÁ
10:00:08  agente     responde                  → 6 segundos
```

Se toma el **último** entrante anterior a la respuesta, no el primero: si el
cliente escribió tres veces seguidas, la espera se cuenta desde que terminó de
hablar, no desde que empezó.

**Qué no cuenta:**

- Los mensajes **fallados**: son un intento, no una respuesta.
- Una respuesta **sin ningún entrante previo** (un primer contacto nuestro): no
  hay espera que medir.
- Las **reacciones** del cliente no abren un turno.

**Por qué mediana y p90, y no promedio.** Medido sobre 30 días:

```
Agente IA   5.497 respuestas   mediana   0,2 min   p90      0,2 min
Asesor        332 respuestas   mediana 305,8 min   p90  1.085,8 min
```

Un promedio conjunto da ~17 minutos: no describe a nadie y esconde que un
cliente derivado puede esperar 18 horas. Con una distribución así de torcida, el
promedio lo domina la cola.

La tarjeta del tablero muestra las dos ramas por separado y respeta el filtro de
fechas.

---

## 3. 🔴 Lo que falta para medir bien al asesor

### 3.1 No hay marca de cierre de la derivación

```
handoff_requested_at   ✓  cuándo se pidió
assigned_at            ✓  cuándo la tomó
handoff_resolved_at    ✗  NO EXISTE
```

Hay 48 derivaciones en estado `resolved` y de ninguna se puede decir **hasta
cuándo** duró. La hora sólo está en el texto de la actividad de auditoría, que
sirve para leer un caso suelto pero no para medir.

**Sin esto no se puede responder:** cuánto dura una toma, quién la cerró, cuántas
cerró el cron por las 6 h en vez de un humano.

### 3.2 El tiempo del asesor se mide fuera de su responsabilidad

Hoy entran **todos** los mensajes con `sender = human`, estén dentro de una
derivación o no. Si un asesor escribe por su cuenta en una conversación que
atiende el bot, esa latencia cuenta igual — y no debería: nadie le había pasado
el caso.

Con `handoff_resolved_at` se puede acotar la medición a la ventana
`assigned_at → handoff_resolved_at`, que es cuando el asesor es efectivamente
responsable de contestar.

### 3.3 El desglose por asesor arranca vacío

`whatsapp_messages.user_id` se agregó el 2026-10-08. El histórico queda en
`null`: antes el CRM guardaba `sender = human` pero no **cuál** humano.

Se intentó deducirlo del asesor asignado a la conversación y no alcanza: de 374
respuestas humanas sólo 29 tienen asignación —un 8%—, porque la mayoría escribe
sin tomar la derivación. **Hoy hay 0 respuestas con `user_id`**; la tabla se
llena desde el próximo mensaje que escriba un asesor.

---

## 4. Lo que falta en el resto del pedido

| | Qué | Estado |
|---|---|---|
| Correos | Aviso al asesor al confirmar un pedido | código listo, SMTP probado, **nunca se disparó solo** — no hubo confirmaciones desde que quedó andando |
| Correos | Dos avisos por confirmación | al arreglar el remitente se destrabó también el del almacén: hoy salen **dos** correos. **Falta decidir** si se apaga uno |
| Plantillas | Campaña por etapa | comando listo con `--etapa` y `--solo`. **0 plantillas enviadas**. No se pudo probar: la conversación de prueba fue borrada |
| Plantillas | Sin interés y Cancelados | `consulta_pendiente` dice "tu consulta quedó sin cerrar" y a esas 360 personas no les aplica. **Falta decidir** |
| Fantasmas | Limpieza sin la etapa Descartado | los dos comandos quedaron inertes al borrar la etapa; 5 tests salteados. **Falta decidir** qué hace el CRM con el próximo fantasma |
| Duplicados | `#1772/#1773` y `#2229/#2230` | esperan criterio humano: los dos del par tienen contenido |
| Alertas | Aviso por fallos de envío | sin hacer. La caída de 17 h del 29/09 la detectó una persona, no el sistema |
| Meta | `code_verification_status: EXPIRED` | no bloquea hoy; conviene re-verificar el número |
| Infra | `laravel.log` de 3,3 GB | sin rotación |
| Infra | El scheduler vive en `service.json` de EasyPanel | si alguien lo desactiva desde el panel, el cierre a 6 h deja de correr **en silencio** |

### Alcance de la campaña, medido

Conversaciones con la ventana de 24 h vencida y hasta 30 días de silencio:

```
no-atendido            245        US$ 2,77
cliente-contactado     182        US$ 2,06
cliente-sin-interés    296        US$ 3,34
pedidos-cancelados      64        US$ 0,72
                       ───        ────────
                       787        US$ 8,89
```

Tarifa Bolivia: utility **US$ 0,0113** contra marketing **US$ 0,0740** — 6,5
veces. Por eso se usa `consulta_pendiente` (UTILITY) y no
`retomar_conversacion` (MARKETING).

---

## 5. Lo que propongo hacer, en orden

1. **`handoff_resolved_at`** — columna nueva, escrita en `Handoff::resolve()`, y
   que el reporte acote la latencia del asesor a la ventana de la toma. Es lo
   que falta para que el número que te importa signifique lo que querés.
2. **Decidir los dos correos** por confirmación: ¿se apaga el del almacén?
3. **Probar la plantilla** en un número: mandá un "hola" al número de Kohlberg
   desde el 76616013 y se verifica con `--solo`.
4. **Alerta por fallos de envío**, para que la próxima caída no la descubra una
   persona 17 horas después.
