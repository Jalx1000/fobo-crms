# Spec: seguimiento automático a las 16 h y cierre por falta de respuesta a los 3 días

Estado: **aprobado** (15/10/2026). Origen: auditoría de datos,
`docs/agent/auditoria-datos-2026-10.md`.

> **Cambios del 28/10/2026 (decididos por el usuario). Reemplazan lo que diga abajo:**
>
> - **Recordatorio a las 4 h de NUESTRO último mensaje** (asesor, agente o recordatorio), no a
>   las 16 h del último del cliente. Solo sale si la ventana de 24 h sigue abierta. Es **uno
>   solo por silencio**: el recordatorio no dispara otro.
> - **No atendido se cierra al cerrarse la ventana:** si el pedido está en `no-atendido`, el
>   último mensaje es nuestro y pasaron 24 h desde el último mensaje del cliente, pasa a
>   `cliente-sin-inters` sin esperar los 3 días. Si el último mensaje es del cliente no se
>   toca, porque el pendiente es del equipo. **Incluye lo acumulado:** no mira la fecha de
>   activación (al 28/10 eran 195 pedidos). Deja su propia marca (`UNATTENDED_MARKER_TITLE`) y
>   se reabre igual que el cierre de 3 días.
> - **Reactivación del agente a las 4 h del último mensaje:** el nuestro si el cliente no
>   respondió, o el del cliente si el asesor no le contestó. Además tienen que haber pasado
>   4 h desde que se apagó. Antes eran 6 h desde el apagado.
> - Activado en producción con `WHATSAPP_AUTO_CLOSE_SINCE="2026-10-28 11:45"` y
>   `WHATSAPP_FOLLOWUP_HOURS=4`.

## Objective

Dos piezas que trabajan juntas sobre los pedidos abiertos de WhatsApp:

1. **Seguimiento dentro de la ventana:** si le escribimos al cliente y no contestó, a las
   **16 h de su último mensaje** (cuando todavía quedan 8 h de la ventana de 24 h de WhatsApp)
   Sofía le manda un recordatorio de **texto normal**, no una plantilla.
2. **Cierre a los 3 días:** si después de nuestro último mensaje el cliente sigue sin contestar
   **72 h corridas**, el pedido pasa a **"Cliente sin interés" dentro de su propio pipeline**.

Por qué: las plantillas de reapertura tuvieron **0 respuestas de 36** en 30 días, contra 59 %
de los mensajes de asesores y 85 % del agente. El seguimiento tiene que llegar mientras la
conversación sigue viva.

### Línea de tiempo

```
T        el cliente escribe por última vez     (abre la ventana de 24 h)
T+x      respondemos (agente o asesor)         → el último mensaje es nuestro
T+16h    seguimiento de Sofía (texto)          ← solo si el cliente sigue sin contestar
T+24h    se cierra la ventana de WhatsApp
S+72h    S = nuestro último mensaje (normalmente el seguimiento) → "Cliente sin interés"
```

### Reglas (decididas por el usuario el 15/10/2026)

**Seguimiento**

1. Se manda a las **16 h** del último mensaje del cliente.
2. Solo si **el último mensaje de la conversación es nuestro**. Si el último es del cliente, el
   pendiente es del equipo y no se manda nada.
3. **Texto fijo firmado por Sofía**, en mayúsculas y minúsculas normales (**no todo en
   mayúsculas**). Tiene que ser **neutro**, sin suponer qué se habló (opciones, pedido,
   precios), porque la conversación varía. Texto propuesto:
   > Hola {nombre} 👋 ¿Te puedo ayudar en algo más? Cuando quieras, seguimos por aquí 🍷
4. **No se usan plantillas** para el seguimiento.

**Cierre**

5. **Etapas que se mueven:** solo `no-atendido` y `cliente-contactado`. Nunca
   `cliente-confirmado` ni las etapas cerradas.
6. **"No responde":** el último mensaje es nuestro y pasaron **72 h corridas** sin ningún
   mensaje del cliente. Una **reacción** del cliente (👍) cuenta como respuesta.
7. **Qué cuenta como "mensaje nuestro":** los mensajes del agente, los de asesores (incluso los
   pegados en muchos chats) y el seguimiento. **Las plantillas no cuentan**: no reinician los
   3 días.
8. **Solo hacia adelante:** aplica a pedidos cuyo último mensaje nuestro se envió **después de
   activar la regla**. Los 426 pedidos que hoy ya cumplen la condición no se mueven.
9. **Si el cliente vuelve a escribir:** un pedido que **cerró esta regla** vuelve a
   `cliente-contactado` en su mismo pipeline. Si un asesor lo había cerrado a mano, queda
   como está.
10. **Ciudad:** el cambio de etapa se resuelve por `code` dentro del pipeline del pedido, nunca
    por id fijo.
11. **Todos los contactos tienen pedido.** La regla no filtra a los que no son clientes.

### Supuestos (corregir si no aplican)

- **Un solo seguimiento** por cada silencio del cliente. Si el cliente responde y vuelve a
  quedarse callado, se cuenta de nuevo.
- (Decidido 15/10) El seguimiento **no** se manda si la conversación está **derivada a un
  asesor** (`handoff_state` `requested`/`assigned`); ahí el seguimiento lo hace el asesor.
  **Sí** se manda aunque el agente esté apagado (`ai_enabled = 0`). El cierre a los 3 días
  aplica en todos los casos.
- El seguimiento solo se manda para pedidos en `no-atendido` o `cliente-contactado`, las mismas
  etapas del cierre.
- Las 16 h no respetan horario: si el cliente escribió a las 10:00, el seguimiento sale a las
  02:00. (Es lo que se eligió; marcarlo si hay que cambiarlo.)
- El nombre es el del título del pedido o, si no está, el de perfil. Si no hay ninguno, el
  saludo va sin nombre.
- Cada cierre queda en el historial a nombre del usuario Sistema (13), con el motivo "Sin
  respuesta del cliente en 3 días".

## Diseño propuesto

- Comando programado `whatsapp:seguimiento-y-cierre`, cada 15 minutos, con `--dry-run`. Tiene
  dos pasos: manda los seguimientos que vencen y cierra los pedidos sin respuesta.
- El seguimiento se manda por el mismo gateway que usa el agente (`sender = ia`), y se marca en
  el `payload` (`{"followup": true}`) para poder medirlo y no repetirlo.
- Reapertura: en la ingesta de un mensaje entrante, si el pedido lo cerró esta regla, vuelve a
  `cliente-contactado`.
- Fecha de activación en config (`whatsapp.auto_close.since`) para la regla 8.
- El comando `whatsapp:reabrir-ventana` (plantillas) no se toca. Sus envíos no cuentan para la
  regla.

## Commands

```bash
php artisan whatsapp:seguimiento-y-cierre --dry-run   # lista seguimientos y cierres pendientes
php artisan test tests/Feature/SeguimientoYCierreTest.php
```

## Testing Strategy

Tests Pest en `tests/Feature/` (corren contra la DB de producción: sin RefreshDatabase,
limpiando lo que crean):

- **Seguimiento:** sale a las 16 h si el último mensaje es nuestro; no sale a las 15:59; no
  sale si el último mensaje es del cliente; no se repite; no sale con handoff activo; **sí**
  sale con el agente apagado; el texto no está en mayúsculas.
- **Cierre:** a las 72 h exactas se mueve; a las 71:59 no; una plantilla posterior no
  reinicia el plazo; una reacción del cliente sí lo reinicia; `cliente-confirmado` no se mueve;
  un mensaje anterior a la activación no cuenta.
- **Pipeline:** el pedido de Cochabamba pasa a "sin interés" de Cochabamba.
- **Reapertura:** el cliente escribe → vuelve a contactado solo si lo cerró la regla.

## Boundaries

- **Always:** `--dry-run` primero en producción; dejar cada cambio en el historial.
- **Ask first:** activar el scheduler en producción; el texto final del seguimiento; cualquier
  cambio sobre los 426 pendientes.
- **Never:** mandar plantillas desde este flujo; mover `cliente-confirmado` o etapas cerradas;
  hardcodear stage ids; escribir el seguimiento en mayúsculas.

## Success Criteria

- [ ] Los seguimientos salen solo en conversaciones que cumplen las reglas 1 y 2, uno por
      silencio.
- [ ] La tasa de respuesta del seguimiento se puede medir (marca en el `payload`).
- [ ] Cada pedido cerrado tiene la actividad con el motivo y el usuario 13.
- [ ] Un cliente que vuelve a escribir reabre su pedido en `cliente-contactado`.
- [ ] El tablero no cambia para fechas anteriores a la activación.

## Open Questions

Ninguna: el texto del seguimiento quedó confirmado el 15/10.
