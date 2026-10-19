# Plan: seguimiento a las 16 h y cierre a los 3 días

Spec: `tasks/spec-sin-respuesta-3-dias.md` (aprobado el 15/10/2026).

**Estado (15/10/2026):** T1–T5 hechas, 24 tests en verde. Simulación en producción
revisada. **T6 (activación) pendiente de OK.** Cambio respecto del plan: la etapa se mueve con
`LeadRepository::update` + eventos `lead.update.*` (igual que el kanban), no con `save()`,
porque la etapa es un atributo y un `save()` directo no deja historial.

## Lo que ya existe y se reutiliza

| Pieza | Dónde | Para qué |
|---|---|---|
| `last_inbound_at` | `whatsapp_conversations` | Se actualiza con **cualquier** mensaje del cliente, incluidas las reacciones (`IngestInboundPayload::persistMessage`). Desde ahí corre la ventana de 24 h |
| `MessagingWindow::compute()` + `messagingWindowHours()` | `Gateways/Dto`, gateways | Saber si la ventana sigue abierta (Kommo devuelve `null`: no hay ventana) |
| `Conversation::handoffOpen()` | Modelo | Condición "derivada a un asesor" |
| `Message` (`sender = ia`, `type = text`, `payload` JSON) + `SendMessage` job | Igual que `AgentController` | Enviar el seguimiento por el mismo camino que el agente |
| Mover etapa por `code` dentro del pipeline | Patrón de `DiscardDuplicateLeads::descartar()` | Cerrar en la ciudad del pedido sin ids fijos |
| `LogsActivity` | Toma `auth()->id()` | Hay que autenticar como usuario 13 para que el cambio quede a nombre de Sistema |
| Scheduler | `app/Console/Kernel.php` (`whatsapp:reactivar-agente` ya corre ahí) | Mismo estilo: `withoutOverlapping()` |

## Decisiones técnicas (revisar)

1. **Sin migraciones.**
   - El seguimiento se marca en el `payload` del mensaje (`{"followup": true}`).
   - El cierre se marca con una **actividad propia** en el historial del pedido, con un título
     fijo "Cerrado automáticamente: sin respuesta del cliente en 3 días". Hace falta porque
     el agente también usa el usuario 13 para mover etapas, así que el usuario no alcanza para
     saber si el cierre lo hizo la regla.
2. **"Último mensaje nuestro"** = el último mensaje de la conversación que no es plantilla ni
   falló (`status != failed`) tiene `direction = outbound`. Un mensaje que falló no le llegó
   al cliente, así que no cuenta.
3. **Activación:** `WHATSAPP_AUTO_CLOSE_SINCE` en `.env` (fecha y hora). Vacío = todo apagado:
   ni seguimientos ni cierres. Es lo que implementa "solo hacia adelante".
4. **Un pedido por conversación:** se actúa sobre `whatsapp_conversations.lead_id`. Si ese
   pedido ya está cerrado (el caso de las conversaciones que apuntan a un pedido viejo), no se
   toca.

## Configuración nueva (`Config/whatsapp.php`)

```php
'auto_close' => [
    'since'          => env('WHATSAPP_AUTO_CLOSE_SINCE'),        // null = apagado
    'followup_after' => (int) env('WHATSAPP_FOLLOWUP_HOURS', 16),
    'close_after'    => (int) env('WHATSAPP_AUTO_CLOSE_HOURS', 72),
    'stages'         => ['no-atendido', 'cliente-contactado'],
    'closed_stage'   => 'cliente-sin-inters',
    'reopen_stage'   => 'cliente-contactado',
    'followup_text'  => 'Hola {nombre} 👋 ¿Te puedo ayudar en algo más? Cuando quieras, seguimos por aquí 🍷',
],
```

## Tareas

### T1 — Selección de candidatos (servicio, sin efectos)
- `Services/SilenceTracker.php`, con dos métodos:
  - `followupsDue(): Collection`: ventana abierta y `last_inbound_at` con 16 h o más; último
    mensaje nuestro; sin seguimiento desde `last_inbound_at`; sin derivación abierta; pedido en
    las etapas de la regla; último mensaje nuestro posterior a `since`.
  - `closuresDue(): Collection`: último mensaje nuestro hace 72 h o más y posterior a
    `since`; ningún mensaje del cliente después; pedido en las etapas de la regla.
- **Acepta:** devuelve exactamente los casos del spec.
- **Verifica:** tests de selección (16 h vs 15:59; último del cliente; con derivación; agente
  apagado **sí**; seguimiento ya enviado; plantilla que no cuenta; mensaje fallido que no
  cuenta; 72 h vs 71:59; `cliente-confirmado` excluido; anterior a `since` excluido).
- **Archivos:** `Config/whatsapp.php`, `Services/SilenceTracker.php`, test.

### T2 — Enviar el seguimiento
- `Services/SilenceTracker::sendFollowup($conversation)`: crea un `Message` (`sender = ia`,
  `type = text`, `payload.followup = true`), con el nombre del título del pedido o del perfil,
  o sin nombre si no hay. Actualiza `last_message_at` y despacha `SendMessage`.
- **Acepta:** un solo seguimiento por silencio; el texto no está en mayúsculas.
- **Verifica:** tests con `Queue::fake()`.
- **Archivos:** el servicio y el test.

### T3 — Cerrar el pedido
- `SilenceTracker::close($lead)`: autentica como usuario 13 (`auth()->guard('user')->setUser`),
  busca la etapa `cliente-sin-inters` del **pipeline del pedido**, la guarda (queda en el
  historial) y agrega la actividad marcadora.
- **Acepta:** Cochabamba se cierra en el "sin interés" de Cochabamba; historial a nombre del
  usuario 13; la marca existe.
- **Verifica:** tests.
- **Archivos:** el servicio y el test.

### T4 — Reabrir cuando el cliente vuelve a escribir
- En `IngestInboundPayload::persistMessage`, después de guardar el mensaje entrante, llamar a
  `SilenceTracker::reopenIfAutoClosed($conversation)`. Si el pedido está en
  `cliente-sin-inters` y **el último cambio de etapa lo hizo la regla** (la actividad marcadora
  es posterior al último cambio de etapa), pasa a `cliente-contactado` como usuario 13.
  Cualquier error se registra en el log y no corta la ingesta (mismo criterio que `LeadOpener`).
- **Acepta:** reabre solo lo que cerró la regla; un cierre manual queda igual.
- **Verifica:** tests a través de `IngestInboundPayload`, como `WhatsappPedidoAutomaticoTest`.
- **Archivos:** `Jobs/IngestInboundPayload.php`, el servicio y el test.

### T5 — Comando y scheduler
- `Console/Commands/FollowupAndClose.php` → `whatsapp:seguimiento-y-cierre {--dry-run}`. Corre
  T2 y después T3 sobre los candidatos de T1 y muestra una tabla de lo que hizo o haría.
- Registrarlo en `WhatsappServiceProvider` y en `Kernel.php` cada 15 minutos con
  `withoutOverlapping()`.
- **Acepta:** con `since` vacío no hace nada y lo dice; `--dry-run` no escribe.
- **Verifica:** test del comando + `--dry-run` contra producción (lo reviso con vos).
- **Archivos:** comando, provider, `Kernel.php`, test.

### T6 — Activación (pide confirmación)
1. Correr `--dry-run` con una fecha `since` de prueba y revisar la lista con vos.
2. Poner `WHATSAPP_AUTO_CLOSE_SINCE` en el `.env` de producción. **Solo con tu OK.**
3. Primera semana: medir cuántos seguimientos salieron, cuántos respondieron y cuántos se
   cerraron.

## Orden y checkpoints

```
T1 → T2 → T3 → T4 → T5 → [checkpoint: dry-run con vos] → T6
```

- Después de T1: los tests de selección en verde. Es el corazón de la regla.
- Después de T5: suite completa (`php artisan test`) en verde, Pint solo sobre los archivos
  tocados.

## Riesgos

| Riesgo | Mitigación |
|---|---|
| El agente IA responde al seguimiento como si fuera un mensaje propio (o lo repite) | Va con `sender = ia` como cualquier mensaje suyo. Avisar al equipo del agente que existe y cómo reconocerlo (`payload.followup`) |
| El seguimiento sale de madrugada (16 h exactas) | Elegido así. Si molesta, se agrega una franja horaria sin tocar la selección |
| El `LogsActivity` de la ingesta (cola) queda sin usuario | La reapertura autentica explícitamente como 13 y después restaura el estado anterior |
| Tests contra la DB de producción | Transacción + rollback, como el resto de la suite |
