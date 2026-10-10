# Plan: API mobile de WhatsApp

Spec: [spec-api-mobile-whatsapp.md](spec-api-mobile-whatsapp.md) · 2026-10-10

## Componentes y dependencias

```
            ┌──────────────────────────┐
            │ 1. Base de autenticación │  middleware sanctum→guard user,
            │    + limiter + /me       │  limiter mobile-api, grupo de rutas
            └────────────┬─────────────┘
                         │
            ┌────────────▼─────────────┐
            │ 2. Visibilidad           │  scopeVisibleTo + resolver por id
            │    (web y mobile)        │  ← va ANTES de exponer datos
            └────────────┬─────────────┘
          ┌──────────────┼───────────────┬──────────────────┐
┌─────────▼───────┐ ┌────▼─────────┐ ┌───▼──────────┐ ┌─────▼──────────┐
│ 3. Lectura      │ │ 4. Envío     │ │ 5. Control   │ │ 6. Contexto CRM│
│ listado, hilo,  │ │ texto, media,│ │ IA, claim,   │ │ persona, lead, │
│ unread-count    │ │ ubic, plant. │ │ resolve, nota│ │ catálogos, QR  │
└─────────┬───────┘ └────┬─────────┘ └───┬──────────┘ └─────┬──────────┘
          └──────────────┴───────┬───────┴──────────────────┘
                     ┌───────────▼────────────┐
                     │ 7. Swagger: schemas,   │
                     │ generate, revisión UI  │
                     └────────────────────────┘
```

Cada porción de 3 a 6 es **vertical**: ruta, controlador delgado, `@OA` y test
juntos, de modo que al cerrarla ya se puede probar desde Swagger.

## Orden y por qué

1. **Base primero.** Sin el guard `user` cargado desde el token, el bouncer
   devuelve `false` y todo lo demás da 403. Es lo único que bloquea a todo.
2. **Visibilidad antes de exponer datos.** Si la lectura sale antes que el
   scope, durante un rato la API filtra menos que lo pedido. Además toca la
   web, así que conviene aislarla en su propio commit para poder revertirla.
3. **Lectura** (listado, hilo, contador): lo que la app necesita primero para
   pintar algo, y lo de menor riesgo (no escribe a WhatsApp).
4. **Envío, control y contexto** dependen sólo de 1 y 2, así que el orden
   entre ellos es libre. Van envío → control → contexto por valor para la app.
5. **Swagger al final, como cierre**: las `@OA` se escriben en cada porción;
   la tarea 7 sólo junta los schemas compartidos, genera y revisa la UI.

## Decisiones técnicas

- **Guard:** `Auth::guard('user')->setUser($request->user('sanctum'))`.
  `setUser` no escribe sesión ni cookie, porque el grupo `api` no tiene
  `StartSession`. Un test verifica que la request siguiente sin token da 401.
- **Resolución por id:** un único `VisibleConversation::findOrFail($id)` que
  aplica el scope y lanza 404. Lo usan los métodos que hoy hacen
  `ConversationProxy::modelClass()::findOrFail($id)` y `existingConversation()`
  (por `conversation_id`, `person_id` o `lead_id`).
- **Controladores mobile:** una clase por área en `Api/Mobile/` que recibe
  `{id}`, hace `$request->merge(['conversation_id' => $id])` cuando la bandeja
  lo espera por input, y llama al método de la bandeja con `app()->call()`
  para conservar la inyección de dependencias. No se copia lógica.
- **Actor:** las acciones toman el usuario de `auth()->guard('user')`, que el
  middleware ya cargó con el usuario del token, así que el actor sale solo. Se
  prueba explícitamente porque el agente IA usa la misma tabla con otro actor.
- **Limiter:** `mobile-api` por `user_id`, configurable por
  `MOBILE_API_RATE_LIMIT` (default 120). `agent-api` no se toca.

## Riesgos

| Riesgo | Mitigación |
|---|---|
| El scope cambia lo que ve un asesor restringido en la web | Commit propio (tarea 2); con usuarios `global` no hay diff; tests web existentes en verde |
| El scope hace lenta la query del listado (ya usa ROW_NUMBER) | `EXISTS` sobre `leads(user_id, person_id)` en vez de JOIN; sólo se agrega si `$ids !== null` |
| Un método de la bandeja busca la conversación por un camino que no pasa por el resolver | Grep de `findOrFail`, `find(` y `existingConversation` en el módulo; test de 404 por endpoint |
| Que un test envíe un WhatsApp real | Gateway fake en todos los tests de envío (como `WhatsappComposerActionsTest`) |
| Las `@OA` se desalinean de la respuesta real | Los ejemplos de Swagger se copian de la respuesta que devuelve el test |

## Puntos de verificación

- **Después de 1:** `curl -H "Authorization: Bearer …" /api/v1/mobile/whatsapp/me` → 200; sin token → 401.
- **Después de 2:** suite completa de WhatsApp en verde y tests de visibilidad nuevos en verde.
- **Después de 3:** desde Swagger UI, con Authorize, se lista y se lee un hilo.
- **Después de 7:** `php artisan l5-swagger:generate` sin warnings; `/api/documentation` muestra los 18 endpoints; `php artisan test` en verde.
