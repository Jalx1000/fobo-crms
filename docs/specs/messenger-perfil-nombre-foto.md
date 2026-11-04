# Spec: Nombre y foto de perfil de contactos (Messenger / WhatsApp)

Estado: **BORRADOR - pendiente de aprobación**
Rama: `imprimir-modulo-whatsapp`

## Contexto (diagnóstico)

- **Messenger:** `MessengerProfile::fetch()` llama a `GET /{PSID}?fields=first_name,last_name,profile_pic`.
  En producción Graph responde `400 code=100 error_subcode=33` para la página
  `103485512431924`. Es un bloqueo de **permisos o token en Meta**, no un error de código:
  la app necesita estar en modo *Live*, tener acceso avanzado a *Business Asset User
  Profile Access* y usar un Page Access Token válido de esa página.
  Esa parte se resuelve en Meta for Developers y **queda fuera de este spec**.
- Aun con Meta arreglado, hay dos defectos de código que hacen que no se vea el resultado:
  1. Si el perfil falla al crear la Person, esta queda como `"Messenger 232251"`.
     Cuando más tarde llega el nombre real, `ConversationResolver::backfill()` solo
     rellena `wa_name`. Como la lista (`ChatController.php:173`) prioriza `person->name`,
     el nombre provisional nunca desaparece. Solo `messenger:backfill-profiles` lo corrige.
  2. La foto (`avatar_url`) se muestra en la lista de chats (`chat.blade.php:128`),
     pero no en la cabecera de la conversación (`inbox-component.blade.php:156`)
     ni en el panel de perfil (`inbox-component.blade.php:891`): ahí siempre
     aparecen las iniciales.
- **WhatsApp Cloud API:** no da la foto de perfil del cliente (el webhook solo trae
  `contacts[0].profile.name`). No hay nada que implementar; las iniciales siguen
  siendo el comportamiento correcto.

## Supuestos

1. El nombre provisional se reconoce por su formato exacto:
   `config('whatsapp.gateways.messenger.lead_source', 'Messenger') . ' ' . últimos 6 del PSID`,
   que es el mismo criterio que usa `BackfillProfiles::renamePlaceholderPerson()`.
2. Un nombre que un humano ya editó (distinto del provisional) **nunca** se reemplaza.
3. Para WhatsApp, la Person se crea con `contact->name` o con los dígitos del teléfono.
   Si se creó con los dígitos y después llega `profile.name`, también es un nombre provisional.
4. La foto de la cabecera sale de `conversation.avatar_url`, que ya viene en la
   respuesta del endpoint `thread` (`InboxController.php:87`). El backend no cambia.
   **Confirmado:** la foto debe verse en la bandeja incrustada en la vista de
   **Person** y en la de **Lead** (`inbox.blade.php` → `v-whatsapp-inbox`).
5. Si la imagen no carga (`@error`), se vuelve a mostrar las iniciales.

## Objetivo

Como asesor, quiero ver el nombre real y la foto del cliente en toda la bandeja
(lista, cabecera y panel de perfil) en cuanto el canal los proporciona, sin
ejecutar comandos a mano.

## Alcance

| # | Cambio | Archivos |
|---|---|---|
| A | Extraer la regla "¿es un nombre provisional?" a un único lugar, reutilizado por el resolver y por el comando `BackfillProfiles` | `Services/ConversationResolver.php`, `Messenger/.../BackfillProfiles.php` (y un helper nuevo si hace falta) |
| B | En `ConversationResolver::backfill()`, cuando llega `contact->name` y la Person tiene un nombre provisional, renombrarla | `Services/ConversationResolver.php` |
| C | Cabecera y panel de perfil: `<img>` cuando haya `avatar_url` y, si no hay o falla la carga, iniciales | `Resources/views/inbox-component.blade.php` |

**Fuera de alcance:** la configuración de la app en Meta, las fotos de WhatsApp,
reintentos o backoff de las llamadas a Graph y los cambios de esquema.

## Commands

```bash
php artisan test tests/Feature/MessengerChannelTest.php
php artisan test tests/Feature/ContactProfileBackfillTest.php   # nuevo
./vendor/bin/pint packages/Webkul/Whatsapp packages/Webkul/Messenger
npm run build   # solo si cambian assets compilados; la vista blade no lo requiere
# Despliegue: reiniciar el worker de supervisord (cola whatsapp) dentro del contenedor
```

## Project Structure

```
packages/Webkul/Whatsapp/src/Services/ConversationResolver.php   → lógica de backfill
packages/Webkul/Messenger/src/Console/Commands/BackfillProfiles.php → reutiliza la regla
packages/Webkul/Whatsapp/src/Resources/views/inbox-component.blade.php → UI
tests/Feature/ContactProfileBackfillTest.php                      → tests nuevos (Pest)
```

## Code Style

Se sigue el estilo del módulo: comentarios que explican el *porqué*, early returns
y nunca perder un mensaje por un fallo accesorio.

```php
// Solo se pisa la etiqueta que generamos nosotros: un nombre corregido a mano
// por un asesor vale más que el perfil del canal.
if ($contact->name && $this->isPlaceholderName($person, $conversation)) {
    $person->update(['name' => $contact->name]);
}
```

## Testing Strategy

Pest, con tests de feature en `tests/Feature/ContactProfileBackfillTest.php`:

1. Messenger: Person `"Messenger 232251"` + mensaje con nombre → la Person pasa a llamarse con el nombre real.
2. Messenger: Person renombrada a mano → **no** se toca.
3. WhatsApp: Person creada con los dígitos + mensaje con `profile.name` → se renombra.
4. `wa_name` y `avatar_url` ya existentes no se sobrescriben (se mantiene el comportamiento actual).
5. Regresión: `MessengerChannelTest` sigue pasando.

UI: comprobación manual en el navegador (una conversación de Messenger con `avatar_url` y otra de WhatsApp sin foto).

## Boundaries

- **Always:** tests antes de hacer commit, Pint, no sobrescribir datos editados por un humano.
- **Ask first:** cambios de esquema, llamadas nuevas a Graph, tocar datos de producción.
- **Never:** hacer commit del `.env` o de tokens, renombrar Persons en bloque sin `--dry-run`.

## Success Criteria

- [ ] Una conversación de Messenger cuya Person se llama `"Messenger XXXXXX"` muestra el nombre real en la lista en cuanto llega un mensaje con perfil resuelto.
- [ ] Un nombre editado manualmente nunca cambia.
- [ ] La cabecera y el panel de perfil muestran la foto cuando `avatar_url` existe, y las iniciales en caso contrario o si la carga falla.
- [ ] Hay una única definición de "nombre provisional", compartida por el resolver y el comando.
- [ ] Todos los tests nuevos y los existentes del módulo pasan.

## Open Questions

1. **¿También WhatsApp?** (pendiente) Si la Person se creó con los dígitos del
   teléfono porque el primer mensaje fue saliente (plantilla o documento a un número
   desconocido, vía `ConversationResolver::findOrCreate($phone)` sin nombre) y luego
   el cliente responde con `profile.name`, ¿se renombra (supuesto 3) o se deja el número?

## Decisiones tomadas

- 2026-09-26 - La foto se muestra en la cabecera y el panel de perfil de la bandeja, también dentro de las vistas de Person y de Lead.
- 2026-09-26 - El renombrado se registra solo con `Log::info` (canal, person_id, nombre anterior y nuevo), sin actividad visible en la Person.
