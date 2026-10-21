# Recontacto antes de cerrarse la ventana de 24 h — respuesta del CRM

> Para el equipo del agente.
> Respuesta a `CRM (Krayin) — Follow-up automático antes de cerrarse la ventana de 24 h`.
> **MVP §4.1 implementado y ACTIVO en producción (`poncedeleon.sofopolis.com`).**
> **No requiere ningún cambio del lado del agente.** La Fase 2 (§4.2 `reengage`) no se implementó,
> como acordamos.

---

## 1. Qué hace, en una línea

Si un cliente deja de responder, el CRM le manda **un** recordatorio en voz de Sofía cuando faltan
~60 minutos para que expire la ventana de 24 h. Sale como mensaje de texto normal, marcado `ia` en el
inbox, igual que cualquier respuesta del agente.

Para ustedes esto es invisible: **no hay evento nuevo, no hay endpoint nuevo, no hay nada que
implementar.** Lo único que cambia es que van a ver mensajes `outbound` con `sender: "ia"` que el
agente no generó.

---

## 2. Checklist §5 — estado

| Punto | Estado |
|---|---|
| Programar a `expires_at − 1 h` | **Hecho** — por sondeo, ver §4 |
| Validar las 5 condiciones al disparar | **Hecho** — 8 escenarios verificados |
| Reprogramar al responder el cliente; cancelar al derivar / tomar / cerrar | **Hecho** — sin código de cancelación, ver §4 |
| Enviar el texto marcado como `ia` | **Hecho** |
| Idempotencia: máx. 1 por ventana | **Hecho** |
| Fase 2 (`event: "reengage"`) | **No implementada**, por acuerdo |
| Prueba extremo a extremo con un cliente real | **Pendiente**, ver §7 |

13 comprobaciones automatizadas, 0 fallos. Lo verificado son las condiciones de disparo y el armado
del texto, no un mensaje llegando a un teléfono.

---

## 3. Las condiciones de §2, tal como quedaron

Se envía **solo si se cumplen todas**, revalidadas en el momento del disparo:

| Condición | Cómo se evalúa |
|---|---|
| IA sigue a cargo | `ai_enabled` **y** el interruptor global (`aiEffective()`) |
| No derivada ni cerrada | no hay handoff en estado `abierto` |
| Cliente no respondió | `last_message_at > last_inbound_at` |
| Ventana abierta | `last_inbound_at > ahora − 24 h` |
| No enviado en esta ventana | `followup_sent_at < last_inbound_at` (o nulo) |

Escenarios probados, todos pasando:

- 23 h 30 m sin responder → **envía**
- 23 h 05 m (recién entra en la franja) → **envía**
- 22 h 50 m (todavía no toca) → no envía
- 25 h (ventana cerrada) → no envía
- el cliente respondió último → no envía
- ya se envió en esta ventana → no envía
- se envió en una ventana **anterior** y el cliente volvió a escribir → **envía**
- IA apagada → no envía
- derivación abierta → no envía

---

## 4. Dos cosas donde nos apartamos del documento

Las dos producen **el comportamiento que ustedes pidieron**; cambia el mecanismo.

### 4.1 Sondeo cada 10 minutos, no un job agendado por conversación

El documento pide programar una tarea a `expires_at − 1 h` y reprogramarla o cancelarla. Lo hicimos
con un comando que corre cada 10 minutos y busca quién entró en la franja.

Por qué: un job agendado hay que cancelarlo y reprogramarlo en tres caminos distintos —el cliente
escribe, el asesor toma la conversación, se deriva— y **olvidarse de cancelar en uno solo manda un
mensaje que no correspondía**. Además las condiciones hay que revalidarlas al disparar de todas
formas, porque el estado pudo cambiar entre que se agendó y que corrió.

Sondeando, la reprogramación es automática: la ventana se deriva de `last_inbound_at`, así que un
mensaje nuevo del cliente mueve el momento del disparo sin que nadie haga nada. Y no hay nada que
cancelar: si la IA se apagó o entró una derivación, la conversación deja de calificar.

El costo es resolución: el recordatorio sale en una franja de ±10 minutos alrededor de los 60 previos
al cierre, no en el minuto exacto. Frente a una hora de margen es despreciable.

### 4.2 Una columna, no una tabla de envíos

«Máximo 1 por ventana» se resuelve con `whatsapp_conversations.followup_sent_at`. Una ventana queda
identificada por su `last_inbound_at`, así que «ya se envió en esta ventana» es exactamente
`followup_sent_at >= last_inbound_at`, y **el contador se reinicia solo** cuando el cliente vuelve a
escribir.

---

## 5. El texto que sale

Copy §4.1, primera variante, con el **primer nombre** del contacto:

> Hola Danitza 👋 Quedé a la orden para ayudarle con su departamento en Ponce de León. ¿Seguimos?
> Si quiere, coordinamos una visita para que lo conozca en persona.

Sin nombre conocido, el saludo se recorta a «Hola 👋 …» sin dejar hueco ni el marcador crudo.

Se usa solo el primer nombre porque el perfil de WhatsApp suele traer apellidos completos o apodos.

**El texto es configurable por variable de entorno**, sin deploy. Si quieren otro copy, nos lo pasan y
lo cambiamos en un minuto; no hace falta tocar código.

---

## 6. Configuración

| Variable | Default | Qué hace |
|---|---|---|
| `WHATSAPP_FOLLOWUP_ENABLED` | `true` | Apaga el recontacto por completo |
| `WHATSAPP_FOLLOWUP_HORAS_ANTES` | `1` | Antelación respecto del cierre |
| `WHATSAPP_FOLLOWUP_MENSAJE` | copy de §5 | El texto, con `{nombre}` opcional |

Las horas de la ventana no están fijas en `24`: salen de `$gateway->messagingWindowHours()`, así que
si entra un gateway con otra ventana, esto la sigue.

---

## 7. Lo que falta probar

Verificamos las condiciones de disparo y el armado del texto. **No probamos todavía un recordatorio
real llegando a un teléfono.** El escenario completo es:

1. Un cliente escribe y el agente responde.
2. El cliente no vuelve a escribir.
3. ~23 h después llega el recordatorio.
4. Si el cliente responde antes, o la conversación se deriva, **no** llega.

El paso 3 tarda casi un día en reproducirse de forma natural. Se puede forzar bajando
`WHATSAPP_FOLLOWUP_HORAS_ANTES` para que la franja abra antes, o moviendo `last_inbound_at` en una
conversación de prueba. Cuando quieran lo coordinamos.

Mientras tanto, `php artisan crm:recontactar-antes-de-cerrar --dry-run` muestra a quién se le
escribiría y con qué texto exacto, sin enviar nada.

---

## 8. Dos avisos operativos

**El scheduler de Laravel no estaba corriendo.** Lo descubrimos al activar esto: `cron` no está
levantado en el contenedor y el único `/etc/cron.d` que hay es la limpieza de sesiones de PHP. Es
decir, **ningún comando programado del CRM se estaba ejecutando** — ni éste, ni la escalada de
derivaciones, ni el cierre de derivaciones abandonadas, ni el procesamiento de correo entrante.

Quedó resuelto con un proceso `schedule:work` en supervisord. Si notaron que las derivaciones no
escalaban, ésa era la causa.

**La configuración de supervisord vive en el contenedor, no en el repositorio.** Un redespliegue que
reconstruya la imagen puede perder ese proceso y volver a dejar el scheduler apagado. Conviene
moverlo al `Dockerfile` o al arranque; lo dejamos anotado.

---

## 9. Si más adelante quieren la Fase 2

El contrato de §4.2 (`event: "reengage"` al webhook del agente, con `history` y `reply.url`) sigue en
pie como lo escribieron. Del lado del CRM el cambio es chico: donde hoy se arma el texto fijo, se
dispara el evento. Las condiciones de §2 las seguiría validando el CRM antes de disparar, así que el
agente puede confiar en que si llegó el `reengage`, corresponde enviar.
