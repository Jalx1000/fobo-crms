# Sprint Submódulos — Chat, Respuestas rápidas, Notas internas, Recordatorios

> Cuatro submódulos del inbox de WhatsApp. **Plantillas queda fuera** (se planifica
> aparte por su dependencia del gateway y la aprobación de Meta).
>
> Los cuatro son **agnósticos de gateway**: se construyen una vez y funcionan igual
> en Kommo o Cloud API. Nada de esto toca el envío por el proveedor.

## Decisiones / supuestos (confirmar si algo cambia)

| Tema | Decisión |
|---|---|
| Chat | **Página propia** en el menú (lista de conversaciones + thread), no solo el tab por contacto |
| Respuestas rápidas | **Globales + por usuario** (cada asesor ve las suyas y las del equipo) |
| Notas internas | Son **actividades tipo `note` de Krayin**, vinculadas al lead/contacto. Aparecen inline en el chat **y** en el timeline del lead. Nunca van al cliente ni al agente IA |
| Recordatorios | **Diferido** (se hace después). Cuando se haga, usará `schedule_from/to` de las actividades de Krayin |
| Variables en respuestas rápidas | Sustitución básica: `{{nombre}}` → nombre del contacto |

## Cambios de esquema (solo uno nuevo)

```
whatsapp_quick_replies    (nueva)
```

**Las notas NO usan tabla propia**: son actividades del módulo `Activity` de Krayin
(`type='note'`, `is_done=1`), enganchadas al `person`/`lead` de la conversación por los
pivotes `person_activities` / `lead_activities`. Ventajas: la nota aparece en el timeline
del contacto sin duplicar datos, y por no ser un `whatsapp_message` queda **naturalmente
excluida** del envío por gateway y del historial que ve el agente IA.

---

# Módulo A · Notas internas (como actividad de Krayin)  `[BAJO]`

Una nota escrita en el chat de WhatsApp crea una **actividad `type='note'`** enganchada
al `person`/`lead` de la conversación. Aparece inline en el chat y en el timeline del
contacto. No va al cliente.

### HU-N1 · Endpoint de nota → actividad — 1d `[BACK]`
- `POST admin/whatsapp/conversations/{id}/notes` `{comment}`.
- Resuelve la conversación; si no tiene `person_id` ni `lead_id`, responde 422
  ("asigná el contacto antes de dejar notas" — una actividad necesita una entidad).
- Crea la actividad vía `ActivityRepository::create([type=>'note', comment, is_done=>1,
  user_id=>auth user])` y la engancha al `person` (y `lead` si existe) por los pivotes,
  replicando lo que hace el listener `activity.create.after` de Krayin.
- Dispara los eventos `activity.create.before/after` para que se comporte como una nota nativa.
**AC:** la nota aparece en el timeline del lead/contacto igual que una creada a mano; sin envío a WhatsApp.

### HU-N2 · Notas inline en el chat — 1d `[BACK]`
- El endpoint `thread` (y el de la lista/página) **mergea** las actividades `note` del
  `person`/`lead` de la conversación dentro del stream de mensajes, ordenadas por `created_at`.
- Cada ítem-nota trae: `comment`, autor (`user`), `created_at`, marcado como `kind: 'note'`.
- Las notas **no** entran en `AgentPayload` (ya excluidas: no son `whatsapp_messages`).
**AC:** las notas se intercalan cronológicamente en el hilo; el evento al agente no las incluye.

### HU-N3 · Front: modo Mensaje / Nota — 1d `[FRONT]`
- Toggle en la barra de escritura: **Mensaje** (verde, va a WhatsApp) / **Nota** (ámbar, interna).
- Las notas se renderizan distinto (fondo ámbar, "Nota · Juan · 14:03"), a lo ancho, no como burbuja.
**AC:** en modo Nota, el texto crea la actividad y se ve en el hilo sin salir al cliente.

---

# Módulo B · Respuestas rápidas  `[BAJO]`

Snippets predefinidos para responder más rápido.

### HU-Q1 · Esquema + modelo + repo — 0.5d `[BACK]`
- `whatsapp_quick_replies`: `id`, `user_id` (nullable = global), `shortcut`, `title`, `content`, timestamps.
**AC:** modelo + repo; una respuesta global (user_id null) y una por usuario conviven.

### HU-Q2 · CRUD + pantalla de gestión — 1d `[BACK · FRONT]`
- Rutas CRUD `admin/whatsapp/quick-replies` + `index` que devuelve las disponibles al usuario (globales + propias).
- Pantalla simple de gestión (listar/crear/editar/borrar).
**AC:** un asesor crea/edita/borra sus respuestas; ve también las globales; no ve las de otros.

### HU-Q3 · Selector en la barra de escritura — 1d `[FRONT]`
- Escribir `/` abre un buscador; filtra por `shortcut`/`title`; Enter inserta el `content`.
- Sustitución de variables: `{{nombre}}` → nombre del contacto.
**AC:** `/saludo` inserta la respuesta con el nombre del contacto ya reemplazado.

---

# Módulo C · Chat (página propia)  `[MEDIO]`

Una sección "WhatsApp" en el CRM con lista de conversaciones + hilo, estilo WhatsApp Web.
Es donde viven naturalmente los otros tres.

### HU-C1 · Menú + ACL + shell de la página — 1d `[BACK · FRONT]`
- Ítem de menú `whatsapp` (`config/menu.php`) + entrada ACL.
- Ruta `admin/whatsapp` con layout de dos columnas (lista | hilo).
**AC:** aparece "WhatsApp" en el menú y abre la página con las dos columnas.

### HU-C2 · Endpoint de lista de conversaciones — 1d `[BACK]`
- `GET admin/whatsapp/conversations`: paginado, búsqueda por nombre/teléfono, filtros
  (todas / sin asignar / no leídas / mías), orden por `last_message_at`.
- Cada ítem: id, nombre, teléfono, gateway, preview del último mensaje, hora, `unread_count`, `ai_enabled`.
**AC:** la lista pagina, busca y filtra; el preview y el contador de no leídos son correctos.

### HU-C3 · Front: lista + selección + hilo — 1.5d `[FRONT]`
- Componente de lista de conversaciones (con no-leídos, avatar, hora relativa).
- Al seleccionar, carga el hilo **reutilizando el componente inbox ya existente**.
- Polling de la lista y del hilo (Reverb queda para otro sprint).
**AC:** seleccionar una conversación abre su hilo; los mensajes nuevos actualizan la lista.

### HU-C4 · Bandeja "Sin asignar" + convertir — 1d `[FRONT · BACK]`
- Filtro de conversaciones sin `person_id`; botón "Crear contacto/lead" que las vincula.
**AC:** una conversación de número desconocido es visible y convertible desde la bandeja.

---

# Módulo D · Recordatorios  `[DIFERIDO]`

Postergado a pedido. Cuando se retome, se apoya en las actividades de Krayin
(`schedule_from`/`schedule_to` + el scheduler que ya corre) en vez de una tabla propia —
así un recordatorio es una actividad agendada más, visible en el timeline del lead.

---

## Orden de implementación

1. **Notas internas** — chico, cero riesgo, útil de inmediato.
2. **Respuestas rápidas** — chico, mejora la productividad del asesor.
3. **Chat (página propia)** — el contenedor donde se integran los demás.
4. ~~Recordatorios~~ — diferido.

**Total estimado: ~1.5 semanas** (1 dev). Front y back en paralelo lo comprimen.

## Definición de Hecho (global)
- `pint` limpio, `php -l` OK.
- Nada de esto dispara envíos al gateway salvo el mensaje real (las notas nunca).
- Las notas no aparecen en el evento/historial que ve el agente IA.
- Las notas quedan registradas como actividad en el timeline del lead/contacto.
- Verificado contra la BD real en el contenedor.

## Fuera de alcance (a propósito)
- **Plantillas** (submódulo aparte).
- **Recordatorios** (diferido).
- **Reverb / tiempo real** (sigue polling).
