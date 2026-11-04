# Plan técnico - el agente IA solicita el teléfono en Messenger

> Spec: `docs/specs/messenger-agente-solicita-telefono.md` (APROBADA, 2026-08-21)
> Tareas: `tasks/todo-messenger-telefono.md`

---

## Idea central

El circuito es **señal → pedido → captura → persistencia → marca**. El CRM es
dueño de todo menos del pedido; el agente solo decide *cuándo* pide y manda el
resultado por HTTP. Todo el estado (intentos, si ya lo dio, si se negó) vive en
`whatsapp_conversations` porque el agente es stateless entre eventos.

Ningún cambio toca el módulo Contact ni el resolver de conversaciones: el
teléfono capturado entra por un servicio nuevo que reusa `PhoneNumber` y
`ConversationResolver::resolvePerson()` (ya existentes y probados).

---

## Componentes y dependencias

```
  [1] Migración                (columnas de estado)
        │
        ├──► [2] ContactPhoneCapture      (servicio: validar, persistir, marcar)
        │          │
        │          └──► [4] AgentController::contactPhone + ruta + Request
        │
        └──► [3] AgentPayload             (phone_required / state / attempts)
                   │
                   └──► [5] Chip "Sin teléfono" en el inbox
                              │
   [6] docs/agent/messenger-phone-capture.md ◄── depende de [3] y [4] cerrados
                              │
   [7] MESSENGER_AI_ENABLED=true (despliegue) ◄── último, tras aprobación
```

**Secuencial:** 1 → 2 → 4, y 1 → 3.
**Paralelo:** [3] (payload) y [2] (servicio) no se pisan; [5] solo necesita [3].
**Último siempre:** [7], porque enciende el agente en producción.

---

## Rebanadas verticales

Cada tarea deja el sistema funcionando; ninguna deja media feature colgada.

- **Rebanada A (1+3):** el agente ya *sabe* que falta el teléfono. Sin endpoint
  todavía, pero el payload es correcto y no rompe a los canales con teléfono.
- **Rebanada B (2+4):** el agente ya *puede* devolverlo. Circuito cerrado.
- **Rebanada C (5):** el humano lo ve en el inbox.
- **Rebanada D (6+7):** el otro equipo lo implementa y se enciende.

---

## Decisiones de diseño

1. **`phone_required` es derivado, no columna.** Se calcula como
   "el canal no es de familia telefónica **y** la conversación no tiene
   `wa_phone`". Un canal futuro sin teléfono (Instagram) lo hereda gratis y no
   hay una columna más que pueda quedar desincronizada con la realidad.

2. **`duplicate_of_person_id` va en `whatsapp_conversations`, no en `persons`.**
   Es donde lo lee el chip, y mantiene el cambio de esquema dentro del módulo
   Whatsapp en vez de tocar el core de Contact y su `$fillable`.

3. **La validación vive en el CRM.** El número lo extrae un LLM de texto libre
   dictado por un cliente. `ContactPhoneRequest` rechaza cualquier cosa con
   menos de 7 dígitos tras `PhoneNumber::digits()`, antes de tocar la base.

4. **El contador de intentos lo lleva el CRM.** Si lo llevara el agente, un
   reinicio suyo reiniciaría la cuenta y el cliente recibiría el pedido para
   siempre.

5. **El endpoint es idempotente.** El mismo número dos veces no duplica en
   `contact_numbers` ni suma intentos. n8n reintenta; un 409 solo lo haría
   reintentar en loop - mismo criterio que ya usa `handoff`.

6. **Se reusa `resolvePerson()` para detectar el duplicado**, no una query
   nueva: es el mismo matcheo por cola de dígitos que usa el ingreso de
   WhatsApp, así que "duplicado" significa exactamente lo mismo en los dos lados.

---

## Riesgos

| Riesgo | Mitigación |
|---|---|
| El agente manda un número mal extraído ("es el 700..." → `700`) | Validación de longitud mínima + 422 sin escribir nada. La conversación queda en `asked` y el agente puede repreguntar (hasta 3). |
| El chip del inbox aparece en conversaciones de Cloud API | `phone_required` se deriva de la familia del canal; test explícito de que Cloud API da `false`. |
| Encender `MESSENGER_AI_ENABLED` hace que el agente conteste conversaciones que nadie entrenó | Es la última tarea, y va con aprobación explícita. Además `ai_enabled` es por conversación: se puede apagar una sin apagar el canal. |
| El worker corre código viejo tras el deploy | Reiniciar el `queue:work` de supervisord dentro del contenedor - es un paso obligatorio del despliegue en este proyecto. |

---

## Puntos de verificación

- Tras Rebanada A: `php artisan test tests/Feature/MessengerPhoneCaptureTest.php` verde en los casos de payload; un evento real de Cloud API sigue sin `phone_required`.
- Tras Rebanada B: `curl` con token Sanctum contra el endpoint deja el número en la ficha del contacto.
- Tras Rebanada C: `npm run build` y revisión visual del inbox en claro y oscuro.
- Antes de [7]: contrato aprobado por el equipo del agente.
