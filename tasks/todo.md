# Tareas — Recepción de media entrante en WhatsApp

- **Spec:** `docs/specs/media-entrante-whatsapp.md` · **Plan:** `tasks/plan.md`
- **Fase:** 3 de 4 (Specify ✅ → Plan ✅ → **Tasks** → Implement)
- **Estado:** ✅ **Completo.** T1–T11 implementadas y verificadas (28/08/2026), incluida la prueba con un teléfono real.
- Orden por dependencia, no por importancia.

Abreviatura usada en las verificaciones:

```bash
C=heaven_c21_laravel.1.imolcmadtascuk4e2xsutwci8
X()  { docker exec -w /code -u www-data -e HOME=/tmp $C "$@"; }   # artisan SIEMPRE como www-data
XR() { docker exec -w /code $C "$@"; }                            # composer, pint
RW() { docker exec $C supervisorctl restart whatsapp; }           # worker: obligatorio tras cada tarea
```

> **Regla que aplica a todas las tareas.** Una tarea no está terminada hasta que el worker se
> reinició y `X php artisan queue:failed` devuelve cero. Ya perdimos once subidas por un worker con
> código viejo, y el error solo era visible en una tabla que nadie mira.

---

## Etapa 0 — Arreglar la URL rota

- [x] **T1 — Ruta de servido de media y `transform()` con URL real** *(C6)*
  - **Acceptance:** existe `GET admin/whatsapp/media/{message}`, autenticada bajo el admin path,
    que transmite el archivo del mensaje y devuelve 404 si el mensaje no tiene archivo o no existe.
    `InboxController::transform()` deja de devolver `media_path` crudo y devuelve esa ruta.
    Sirve tanto para lo que envía el asesor como para lo que después llegue del cliente.
  - **Verify (V1):** adjuntar una imagen desde el inbox y recargar el hilo → **la imagen se ve**.
    Es el bug de H4 cerrado, y hoy está roto.
  - **Verify (V2):** `curl -s -o /dev/null -w "%{http_code}" https://c21.sofopolis.com/admin/whatsapp/media/1`
    sin sesión → 302 al login o 404. **Nunca** el binario.
  - **Files:** `Http/Controllers/InboxController.php`, `Routes/admin.php`

> **Compuerta:** no se avanza a la Etapa 1 sin V1 en verde. Es el único bug activo del lote.

---

## Etapa 1 — Poder traer el archivo

- [x] **T2 — Campos de media en `InboundMessage`** *(C1)*
  - **Acceptance:** el DTO acepta `mediaId`, `mediaMime` y `mediaFilename`, los tres nullable y con
    valor por defecto, para no romper ninguna construcción existente. Sigue siendo inmutable.
  - **Verify:** `XR php artisan tinker` construyendo el DTO con y sin los campos nuevos; los dos
    gateways siguen parseando sin error.
  - **Files:** `Gateways/Dto/InboundMessage.php`

- [x] **T3 — `downloadMedia()` en el contrato y en los dos drivers** *(C2)*
  - **Acceptance:** el contrato declara `downloadMedia(string $mediaId): ?DownloadedMedia`, con un
    DTO nuevo que trae contenido, mime y tamaño. Cloud API lo implementa **en dos pasos**
    (`GET /{media-id}` → `url`, luego `GET url` con el token) y **nunca persiste la URL**, porque
    caduca en minutos (R2). Kommo lanza `UnsupportedCapability`.
  - **Verify (V4 parcial):** llamarlo contra un `media_id` real. **No hace falta esperar un mensaje
    entrante:** las fotos del galpón ya subidas a Meta tienen ids propios y se pueden descargar de
    vuelta. El archivo recuperado debe coincidir en tamaño y mime con el original en disco.
  - **Files:** `Gateways/Contracts/Gateway.php`, `Gateways/Dto/DownloadedMedia.php`,
    `Gateways/CloudApi/CloudApiGateway.php`, `Gateways/Kommo/KommoGateway.php`

- [x] **T4 — El parser extrae el archivo del payload** *(C3)*
  - **Acceptance:** `parseWebhook()` llena `mediaId`, `mediaMime` y `mediaFilename` para `image`,
    `video`, `document`, `audio` y `sticker`. El epígrafe sigue llegando en `body`, como hoy.
    `location` y `contact` no se tocan: no son archivos.
  - **Verify (V3):** parsear payloads sintéticos copiados del formato real de Meta —uno por tipo—
    y comprobar que el DTO trae los tres campos y el epígrafe. Un payload de texto debe seguir
    dando `mediaId = null`.
  - **Files:** `Gateways/CloudApi/CloudApiGateway.php`

---

## Etapa 2 — Descarga real de punta a punta

- [x] **T5 — Job `DescargarMediaEntrante`** *(C4)*
  - **Acceptance:** 3 intentos con backoff `[10, 30, 60]`, **re-resolviendo el id en cada intento**
    (R2). Guarda en `storage/app/whatsapp/{conversation_id}/inbound/` en el disco privado y completa
    `media_path`, `media_mime` y `media_name`. Respeta los límites de D9 (25 MB general, 100 MB
    video) y, al excederlos o agotar los intentos, escribe el motivo en la columna `error` del
    mensaje — que ya existe— en vez de dejar la fila muda.
  - **Verify (V4):** ejecutar el job a mano contra un `media_id` real → archivo en disco con el mime
    correcto y fila completa. Forzar un id inválido → 3 intentos, motivo legible, y **cero** jobs
    huérfanos en `failed_jobs`.
  - **Files:** `Jobs/DescargarMediaEntrante.php`, `Config/whatsapp.php` (límites)

- [x] **T6 — El ingest despacha el job** *(C5)*
  - **Acceptance:** cuando el DTO trae `mediaId`, el mensaje se crea igual que hoy y **además** se
    encola la descarga. El ingest no hace ninguna llamada de red nueva: su tiempo de respuesta no
    cambia (R3). La deduplicación por `wa_message_id` sigue funcionando igual.
  - **Verify (V5):** ingestar un payload sintético completo → mensaje creado al instante y job
    encolado. Medir el tiempo del ingest antes y después: **no debe aumentar**.
  - **Files:** `Jobs/IngestInboundPayload.php`

> **Compuerta:** al cerrar la Etapa 2, una foto sintética tiene que verse en el inbox. Es el hito que
> justifica todo el trabajo; si no se ve, no se sigue.

---

## Etapa 3 — Contar la verdad sobre el estado

- [x] **T7 — Los tres estados visibles en el hilo** *(C7)*
  - **Acceptance:** `transform()` expone `media.estado` (`pendiente` | `listo` | `fallido`) y el
    motivo cuando falló. El hilo distingue los tres: un marcador mientras descarga, el archivo
    cuando está, y el motivo legible cuando no se pudo. **Nunca una burbuja vacía.**
  - **Verify (V6):** armar en transacción revertida un hilo con los tres casos y comprobar que se
    distinguen a simple vista, en tema claro y oscuro.
  - **Files:** `Http/Controllers/InboxController.php`, `Resources/views/inbox-component.blade.php`

- [x] **T8 — Bloque `media` para el agente** *(C8)*
  - **Acceptance:** el evento del webhook y el historial traen
    `media: { kind, disponible }`, con el epígrafe en `text`. **Nunca URL ni binario** (D4):
    `disponible` es `false` mientras la descarga esté pendiente o haya fallado, para que el agente
    no afirme tener algo que el asesor no puede abrir.
  - **Verify (V7):** generar el evento para un mensaje con imagen en los tres estados y comprobar
    que `disponible` acompaña al estado real.
  - **Files:** `Services/AgentPayload.php`

---

## Etapa 4 — Cerrar

- [x] **T9 — `capabilities()` honestas** *(C9)*
  - **Acceptance:** `receive` lista **exactamente** los tipos verificados uno por uno. Lo que no se
    probó, sale de la lista.
  - **Verify (V8):** recorrer cada tipo declarado ejercitándolo de verdad. Y revisar el inbox
    buscando controles que se hayan encendido solos: cambiar esta clase ya hizo aparecer una vez un
    botón conectado a un mock (R5).
  - **Files:** `Gateways/CloudApi/CloudApiGateway.php`

- [x] **T10 — Comando de relleno retroactivo** *(C10)*
  - **Acceptance:** `crm:descargar-media-historica` recorre los mensajes entrantes que tengan
    `payload` con un id de archivo y no tengan `media_path`, y encola la descarga. Soporta
    `--dry-run`.
  - **Verify:** el dry-run sobre la base actual debe reportar **0 mensajes**, porque hoy no hay
    ninguno. Si reporta más, algo se ingestó mientras tanto y hay que mirarlo antes de correrlo.
  - **Files:** `Console/Commands/DescargarMediaHistorica.php`, `Providers/WhatsappServiceProvider.php`

- [x] **T11 — Prueba de punta a punta con un teléfono real** ⚠️ *requiere autorización*
  - **Acceptance:** una foto con epígrafe enviada desde un WhatsApp real aparece en el inbox,
    renderizada y con su texto. Un audio de voz se reproduce.
  - **Verify (V9):** manual, con una persona real del otro lado. **No se ejecuta sin que lo
    autorices explícitamente.**
  - **Verify (V10):** `git status --porcelain packages/Webkul/{Admin,Core,Lead}` vacío.
  - **Files:** ninguno

---

## Compuertas

- **T1 antes que todo lo demás.** Es el único bug activo; si el resto se pospone, al menos eso queda
  sano.
- **No se pasa de la Etapa 2** sin ver una foto renderizada en el inbox.
- **T9 nunca antes de T7.** Declarar la capacidad antes de verificarla es precisamente el error que
  ya se cometió dos veces en esta clase.
- **T11 no se ejecuta sin autorización explícita:** involucra a una persona real.
