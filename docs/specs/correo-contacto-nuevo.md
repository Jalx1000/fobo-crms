# Spec: Correo cuando se registra un contacto nuevo

Estado: **BORRADOR — pendiente de aprobación**
Rama: `imprimir-modulo-whatsapp`

## Contexto (verificado 2026-10-01)

- El evento `contacts.person.create.after` **solo** se dispara al crear un contacto a mano
  (`PersonController::store`) y desde el formulario web (`WebFormController`). **No** se dispara en:
  `ConversationResolver::createPerson()` (WhatsApp/Messenger/Kommo), `LeadRepository` (contacto creado
  junto con la oportunidad, 2 lugares) ni `ShareMeDataWebhookController`.
- **Correo encolado roto:** `QUEUE_CONNECTION=redis` y el único worker (supervisord, programa `whatsapp`)
  atiende **solo la cola `whatsapp`**. Todo `Mail::queue()` va a `default` y nunca sale. Hoy hay **6
  trabajos atascados en `queues:default`**. Esto incluye la acción "Enviar correo al contacto" de
  Automatizaciones de Krayin.
- SMTP: `mail.sofopolis.com:465` SSL; hoy autentica y envía como `jmogro@sofopolis.com`.
- "Asesores de Bolivia" = usuarios activos con al menos un responsable de producto en la ciudad
  `config('cotizacion.cobertura_nacional')` = `Bolivia` (tabla `producto_responsables`, `Responsables::esNacional()`).

## Reglas de destinatarios (decisión del usuario, 2026-10-01)

| # | Destinatario | Cuándo | Correo |
|---|---|---|---|
| D1 | **El cliente** | Si el contacto tiene email | Bienvenida |
| D2 | **Asesor asignado** (`persons.user_id`) | Si tiene asesor con email | Aviso interno "nuevo contacto asignado" |
| D3 | **Asesores de la ciudad del contacto**: usuarios activos con «Atiende» (`producto_responsables`) en esa ciudad, con cualquier producto | Si **no tiene asesor** y **sí ciudad** | Aviso interno "nuevo contacto en {ciudad}" |
| D4 | **Asesores de Bolivia** (cobertura nacional) | Si **no tiene ciudad**, o si D3 no encontró a nadie (el local va primero, igual que el round robin) | Aviso interno "nuevo contacto sin asesor" |

Los destinatarios internos se deduplican: un asesor que es a la vez D2 y D3 recibe **un** solo correo.

**Origen:** todos los caminos de creación (decisión del usuario).

## Supuestos

1. **Un único punto de entrada:** un listener en `contacts.person.create.after`. Los caminos que hoy no
   disparan el evento pasan a dispararlo. Efecto colateral aceptado: las Automatizaciones "Persons → Created"
   también correrán para esos contactos.
2. **Cola `whatsapp`** (la única con worker), vía `config('whatsapp.queue')`. El webhook nunca espera al SMTP.
3. **Inmediato** (decisión del usuario): se encola sin demora. Consecuencia aceptada: los contactos de
   WhatsApp/Messenger casi siempre nacen sin ciudad ni asesor, así que su aviso va a los asesores de Bolivia.
4. **Correo al cliente con plantilla Blade propia**, no con Automatizaciones: estas dependen de la cola
   `default`, que está rota (ver Contexto).
5. **Idempotencia:** un contacto genera sus correos **una sola vez**, aunque el evento se dispare dos veces.
   Se marca con una clave de caché por `person_id` para no cambiar el esquema.
6. Si el envío SMTP falla, se registra en el log y el job se reintenta (3 intentos). Nunca rompe la creación del contacto.

## Alcance

| # | Cambio | Archivos |
|---|---|---|
| C1 | Disparar `contacts.person.create.after` en los 4 caminos que hoy no lo hacen | `ConversationResolver`, `LeadRepository`, `ShareMeDataWebhookController` |
| C2 | Listener → job `AvisarContactoNuevo` (cola `whatsapp`, inmediato, idempotente) | `Admin/Listeners/ContactoNuevo.php`, `Admin/Jobs/AvisarContactoNuevo.php`, `EventServiceProvider` |
| C3 | Resolver destinatarios D1/D2/D3 + deduplicar | dentro del job (o `Services/DestinatariosContactoNuevo.php`) |
| C4 | Dos Mailables + vistas Blade (bienvenida al cliente y aviso interno con un enlace a la ficha) | `Admin/Mail/`, `Resources/views/emails/contacto-nuevo/` |

**Fuera de alcance:** reparar la cola `default` y los 6 trabajos atascados (es un tema aparte, con su
propia decisión: procesarlos o descartarlos), correos al actualizar un contacto y correos por WhatsApp.

## Commands

```bash
php artisan test tests/Feature/Contact/CorreoContactoNuevoTest.php   # nuevo, con Mail::fake / Queue::fake
./vendor/bin/pint <archivo>
# Deploy: php artisan config:clear && supervisorctl restart whatsapp
```

## Testing Strategy (Pest)

- Con ciudad y asesor → aviso solo al asesor, más bienvenida al cliente si tiene email.
- Sin ciudad → aviso a los asesores de Bolivia, y al asesor asignado si lo hay (deduplicado).
- Sin email del cliente → no hay bienvenida, y no es un error.
- Con ciudad y sin asesor → asesores con «Atiende» en esa ciudad; si no hay ninguno → los de Bolivia.
- Evento disparado dos veces → un solo envío.
- Cada camino de creación (manual, WhatsApp, oportunidad, ShareMeData) dispara el evento.
- El job va a la cola `whatsapp`, sin demora.

**BD:** por decisión del usuario los tests corren contra la base del contenedor (la de producción), con
`DatabaseTransactions` (todo se deshace), `Mail::fake` y `Http::fake`. **Solo este archivo de tests**:
`ProductMediaTest` usa `RefreshDatabase` y la suite completa **borraría la base de producción**.

## Boundaries

- **Always:** colas `whatsapp`; `Mail::fake` en tests; no romper la creación del contacto.
- **Ask first:** cambiar el remitente en `.env`; procesar o borrar los 6 trabajos de `queues:default`.
- **Never:** guardar contraseñas SMTP en el repo, la spec o los logs; enviar correos reales desde los tests.

## Success Criteria

- [ ] Crear un contacto a mano con ciudad y asesor → el asesor recibe el aviso y el cliente la bienvenida.
- [ ] Un contacto nuevo por WhatsApp sin ciudad → les llega el aviso a los asesores de Bolivia.
- [ ] Un contacto con ciudad y sin asesor → les llega a los asesores que atienden esa ciudad.
- [ ] Ningún contacto genera correos duplicados.
- [ ] Ningún correo nuevo queda atascado en `queues:default`.

## Decisiones tomadas (2026-10-01)

- Remitente: `imprimir@sofopolis.com` (cambio en `.env`, fuera del repo; rotar la contraseña pegada en el chat).
- Con ciudad y sin asesor: van los asesores que atienden esa ciudad (sección «Atiende» del usuario); si no hay, los de Bolivia.
- Envío inmediato, sin demora.
- Tests contra la BD del contenedor, con transacciones y solo este archivo.
