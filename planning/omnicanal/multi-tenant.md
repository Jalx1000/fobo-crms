# Multi-tenant - aislamiento de datos entre clientes

> Decisión tomada: el CRM conecta **también cuentas de clientes**, no solo las propias.
> Esto deja de ser una feature y pasa a ser una propiedad de seguridad del sistema.

---

## El problema central

El webhook de Meta llega **sin sesión de usuario**. Solo trae un identificador externo:
`phone_number_id`, `page_id` o `ig_id`. De ahí hay que deducir a qué tenant pertenece el mensaje,
antes de escribir una sola fila.

```
POST /api/v1/channels/messenger/webhook
  { "object": "page", "entry": [{ "id": "1234567890", ... }] }
                                        ↑
                            esto es todo lo que hay
```

Si esa resolución falla o se hace mal, un mensaje de un cliente termina en el inbox de otro. No hay
capa posterior que lo atrape: el `ConversationResolver`, el `IdentityResolver` y el `Handoff`
trabajan todos aguas abajo de esta decisión.

---

## Dónde se aplica el aislamiento

`tenant_id` se denormaliza en `conversations` para poder filtrar sin join. Puntos a cubrir:

| Superficie | Cómo se obtiene el tenant | Riesgo si falta |
|---|---|---|
| Inbox (lista, hilo, envío) | De la sesión del usuario | Un asesor ve conversaciones de otro cliente |
| Webhook entrante | **Del `external_id` del payload** | Mensajes escritos en el tenant equivocado |
| API del agente IA (Sanctum) | Del token → usuario → tenants | El agente responde en la conversación de otro cliente |
| Notificaciones de handoff | Del tenant de la conversación | Se mailea al asesor equivocado |
| Configuración → Canales | De la sesión, **nunca del request** | Escalada: conectar cuentas a un tenant ajeno |
| Comandos artisan | De `--tenant`, o todos si se omite | Backfills cruzados |

> Un `global scope` de Eloquent **no basta**: el webhook y los jobs de cola corren sin sesión.
> El scope resuelve el inbox, no la ingesta. La ingesta necesita resolución explícita.

---

## Decisión abierta

Hueco preparado en `Services/TenantResolver.php`:

```php
/**
 * Resuelve a qué tenant pertenece un webhook entrante.
 *
 * @param  string  $channel     whatsapp|messenger|instagram
 * @param  string  $externalId  phone_number_id | page_id | ig_id del payload
 * @return ?ChannelTenant
 */
public function resolveTenant(string $channel, string $externalId): ?ChannelTenant
{
    // TODO: ver los dos casos borde de abajo.
}
```

### Caso borde 1 - cuenta desconocida

El `external_id` no corresponde a ninguna `channel_account` registrada.

| Opción | Costo |
|---|---|
| Descartar en silencio | Se pierden mensajes de un cliente que se conectó mal, y nadie se entera |
| Cuarentena para revisión | Tabla `channel_orphan_payloads` + aviso en la UI. Nada se pierde, hay que mantenerla |
| Auto-crear tenant | **No.** Cualquiera que conozca la URL del webhook inyecta tenants |

### Caso borde 2 - colisión

Dos tenants con el mismo `page_id`. Pasa de verdad: un cliente se conecta dos veces, o una agencia
y su cliente conectan la misma página.

| Opción | Costo |
|---|---|
| Gana el más reciente | Silencioso, y le roba las conversaciones al primero |
| Fallar ruidosamente en la ingesta | Se para el canal hasta que un humano resuelva. Seguro pero brusco |
| Rechazar el duplicado **en el alta** | Preferible: el UNIQUE `(channel, external_id)` en `channel_accounts` lo impide antes de que llegue un solo mensaje |

> Nota de esquema: el UNIQUE propuesto es `(channel, external_id)` **sin** `tenant_id`. Eso implementa
> ya la tercera opción - una cuenta de Meta pertenece a un solo tenant, y el segundo intento de
> conexión falla en la pantalla de configuración con un mensaje claro. Si se prefiere permitir que
> dos tenants compartan una página, hay que cambiar el UNIQUE a `(tenant_id, channel, external_id)`
> y entonces el caso borde 2 vuelve a la ingesta, donde es mucho más caro de resolver.

---

## Tests obligatorios

Cada endpoint nuevo necesita, como mínimo:

1. Un usuario del tenant A **no** ve conversaciones del tenant B (lista, hilo y búsqueda).
2. Un usuario del tenant A **no** puede enviar a una conversación del tenant B (404, no 403 -
   no confirmar que existe).
3. Un webhook con `external_id` del tenant B escribe en el tenant B, aunque llegue en la misma
   tanda que uno del tenant A.
4. Un token de agente IA del tenant A no puede leer ni postear en conversaciones del B.
5. `settings/channels` ignora un `tenant_id` inyectado en el request.

Estos tests son la Definición de Hecho del Sprint de multi-tenant, no un extra.
