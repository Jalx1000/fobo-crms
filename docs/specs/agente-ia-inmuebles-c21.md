# Spec: Catálogo de inmuebles + API para el agente de IA "Sofía" (CENTURY 21)

- **Estado:** 🟢 Fase 1 (Specify). D1–D14 cerradas · **D15 abierta** (el pipeline no cierra leads).
- **Fase:** 1 de 4 (Specify → Plan → Tasks → Implement)
- **Instancia:** `https://c21.sofopolis.com` · Krayin 2.1.5 · BD `kohlberg` (prefijo `kl_`)
- **Insumo:** handoff del equipo del agente (servicio Python/FastAPI + LangGraph)
- **Depende de:** `docs/specs/setup-inmobiliaria-c21.md` (pipelines y atributos de lead, ya aplicado)

---

## Supuestos que estoy haciendo

Corregime ahora o procedo con estos:

1. **El catálogo de inmuebles es propiedad del CRM** (decisión tomada). No hay portal ni MLS externo
   como fuente de verdad; los asesores cargan los inmuebles desde la UI del CRM.
2. **El asesor titular es un `users` de Krayin.** No se crea una entidad "asesor" aparte.
3. **Una sola plaza.** Sin ruteo por ciudad, sin multi-tenancy (§9.2 del handoff).
4. **El gateway activo es `cloud_api`.** Media y ubicación son técnicamente posibles; con `kommo`
   no lo serían (`KommoGateway::capabilities()` declara `send: ['text']`).
5. **Las fotos se sirven desde el CRM** (`storage/`), no desde URLs de terceros. La Cloud API acepta
   tanto un `link` público como un `media_id` subido previamente.
6. **El trabajo se entrega en una branch derivada de `imprimir-modulo-whatsapp`**, no directo a `main`.

---

## Objetivo

Que Sofía pueda sostener, punta a punta, esta conversación:

> buscar inmuebles → mostrar 2-3 con datos reales → mandar fotos → mandar ubicación (post-visita)
> → registrar la solicitud del comprador → derivar al asesor titular

**Fuera de alcance de v1 (decisión tomada):** `get_disponibilidad` y `agendar_visita`. La visita la
agenda el humano después del handoff. Ver "Fase 2" al final.

**Usuarios:**
- **Asesores C21** — cargan inmuebles y sus galerías desde la UI; reciben leads y handoffs.
- **Agente Sofía** — lee catálogo y escribe leads/handoff por la API, solo por HTTP.
- **Cliente final** — recibe texto, fotos y ubicación por WhatsApp.

---

## Hallazgos del reconocimiento

| # | Hallazgo | Impacto |
|---|---|---|
| H1 | **No existe entidad "inmueble"** en el CRM. `Webkul\Inmobiliaria` solo configura pipelines y atributos de `leads`. | 🔴 11 de las 13 tools del §6 no tienen a qué apuntar. Es el grueso del trabajo, no el contrato HTTP. |
| H2 | El contrato Gateway solo declara `sendText()` (`Gateways/Contracts/Gateway.php:82`). `CloudApiGateway::capabilities()` → `send: ['text','reply','emoji']`. | 🔴 Enviar media y ubicación requiere extender el **contrato**, no solo el driver. Es lo más nuevo respecto al tenant anterior (§9.1). |
| H3 | `NotifyAgent` usa `->throw()` con `tries = 3` (`Jobs/NotifyAgent.php:24,51`): un **422 del agente se reintenta 3 veces**. | 🟡 Contradice el §3 ("no reintentes el 422"). Fix acotado: descartar 4xx, reintentar 5xx. |
| H4 | `Conversation.ai_enabled` es un booleano de UI, no una máquina de estados. No hay titular asignado ni rechazo de escrituras posteriores. | 🟡 `handoff` (§8) hay que modelarlo aparte: estado + titular + idempotencia + 409. |
| H5 | El webhook ya cumple ~70% del §3: `conversation_id`, `gateway`, `contact{phone,name,person_id,lead_id}`, `message{id,type,text}`, `history[]`, `window`, `reply.url` (`Services/AgentPayload.php:22`). | ✅ Faltan solo `channel`, `handoff{state,open}` y los 3 campos de captura de teléfono. |
| H6 | Krayin ya tiene `activities` con `schedule_from`/`schedule_to`/`user_id` (`Activity.php:33`) y ownership de leads por `user_id`. | ✅ La agenda de Fase 2 se apoya en tablas existentes; no hace falta un calendario nuevo. |
| H7 | Las tools del §6 van keyed por `wa_id`, que **no existe en Messenger** — el propio handoff admite el fallback `conv:<conversation_id>`. | 🔴 Contradice el principio del §2 (ids server-side). Ver D1. |
| H8 | `APP_ENV=local` con `APP_DEBUG=true` sirviendo producción; suite de tests destructiva sobre la BD real. | 🟡 Deuda preexistente ya documentada en `docs/inmobiliaria/limitaciones.md`. No la toco acá, pero condiciona cómo se verifica. |

---

## Decisiones

### D1 — Todo se identifica por `conversation_id`, no por `wa_id` *(contrapropuesta al §6)*

El handoff establece en el §2 que los ids internos se inyectan server-side y el LLM nunca los ve; y
sin embargo seis tools llevan `wa_id` como primer parámetro. Un `wa_id` es un teléfono: en Messenger
no existe, y en WhatsApp puede cambiar de dueño o venir con formato distinto (`591…` vs `+591…`).

**Decisión.** Las rutas del agente cuelgan de `/conversations/{conversation_id}/…`. El
`conversation_id` ya viaja en el webhook, ya lo inyectan server-side, y ya resuelve a `person_id` y
`lead_id` vía `ConversationResolver`. El `wa_id` deja de ser clave y pasa a ser un dato del contacto.

**Excepción confirmada:** el `codigo` del inmueble sigue siendo público y sí lo maneja el modelo.

### D2 — Entidad propia `inmuebles`, no `products`

Un `product` de Krayin modela SKU, precio único y cantidad. Un inmueble necesita operación, moneda,
estado de disponibilidad, geolocalización, punto de encuentro, asesor titular y una galería tipada.
Forzarlo sobre `products` haría que cada tool tenga que traducir, y la traducción es donde se rompen
las cosas. Tabla propia dentro de `Webkul\Inmobiliaria`, con migración (a diferencia del catálogo de
datos maestros, que se aplica por comando).

### D3 — Esquema

```
inmuebles
  id
  codigo            varchar(32)  UNIQUE      -- público, el que el cliente ve. Generado (C21-0001), editable (D9)
  operacion         enum(venta, alquiler, anticretico)
  tipo              enum(casa, departamento, terreno, oficina, local, galpon)
  estado            enum(borrador, disponible, reservado, vendido, alquilado, suspendido)
  titulo            varchar(160)
  destacado         varchar(200) NULL        -- "una línea real de la ficha" (§6); fallback: 1ª oración de descripcion
  descripcion       text NULL
  zona              varchar(120)             INDEX  -- "Zona Norte" — siempre público
  barrio            varchar(120) NULL        INDEX  -- "Barrio Hamacas", "Av. Banzer km 11" — público
  referencia        varchar(200) NULL               -- siempre público
  direccion_exacta  varchar(255) NULL               -- GATEADO (D6)
  google_maps_url   varchar(500) NULL               -- GATEADO (D6) — como lo carga el asesor
  latitud           decimal(10,7) NULL              -- GATEADO (D6) — resuelto del shortlink
  longitud          decimal(10,7) NULL              -- GATEADO (D6)
  punto_encuentro   varchar(255) NULL
  precio            decimal(15,2)
  precio_anterior   decimal(15,2) NULL               -- el "🚨NUEVO PRECIO🚨" del anuncio (D13)
  moneda            char(3)                          -- USD | BOB, nunca se convierte (D4)
  modalidad_cambio  enum(solo_usd, tc_oficial, tc_paralelo) NULL  -- condición comercial (D13)
  negociable        boolean default 0
  dormitorios       tinyint unsigned NULL
  banos             tinyint unsigned NULL
  parqueos          tinyint unsigned NULL
  m2_construidos    decimal(10,2) NULL
  m2_terreno        decimal(10,2) NULL
  caracteristicas   json NULL                        -- específico del tipo, se muestra pero no se filtra (D13)
  user_id           FK users  RESTRICT               -- ASESOR TITULAR
  publicado_at      datetime NULL
  timestamps + softDeletes

  INDEX (estado, operacion, tipo)
  INDEX (zona)
  INDEX (user_id)

inmueble_medias
  id
  inmueble_id       FK inmuebles  CASCADE
  tipo              enum(foto, video, plano, tour)
  path              varchar(255)          -- storage/ (foto|video|plano)
  url               varchar(500) NULL     -- tour externo (Matterport, etc.)
  caption           varchar(200) NULL
  orden             smallint unsigned default 0
  es_portada        boolean default 0
  provider_media_id varchar(120) NULL     -- media_id de la Cloud API (D10)
  provider_expira_at datetime NULL        -- ~30 días; se re-sube al vencer
  timestamps

  INDEX (inmueble_id, tipo, orden)
```

**`user_id` es `RESTRICT`, no `CASCADE`:** si un asesor se va de la oficina, sus inmuebles no pueden
desaparecer — hay que reasignarlos explícitamente. Borrarlos en silencio sería el peor bug posible
para Sofía, que quedaría ofreciendo inmuebles fantasma o dejaría de ofrecer los reales.

### D4 — Qué significa "disponible" y cómo se compara precio entre monedas

Cerrado. Vive en `packages/Webkul/Inmobiliaria/src/Config/inmuebles.php`.

- **`estados_buscables = ['disponible']`.** Sofía solo ofrece lo que se puede cerrar. Descartado
  incluir `reservado`: capturaría al interesado de la reserva caída, pero obligaría al agente a
  matizar y su prompt se lo prohíbe.
- **`comparacion_precio = 'ambas'`, sin tipo de cambio.** El rango de precio filtra solo las fichas
  de la moneda pedida; las de la otra moneda se devuelven igual y Sofía muestra precio + moneda
  literal. No se declara un tipo de cambio fijo: oficial y paralelo divergen lo suficiente como para
  que el número mienta sobre el precio de una casa.

### D5 — Media: el CRM resuelve la galería *(coincide con la preferencia del §4)*

El agente NO conoce URLs de fotos. Llama `POST /conversations/{id}/media {codigo, tipo, cantidad}` y
el CRM decide qué mandar (portada primero, después por `orden`, tope `cantidad`), lo encola en la cola
`whatsapp` y lo persiste como mensajes salientes con `sender = 'ia'` — así la galería aparece en el
inbox del asesor igual que cualquier otro mensaje.

Requiere extender `Gateways\Contracts\Gateway` con `sendMedia()` y `sendLocation()`, y declararlas en
`Capabilities`. Un gateway que no las soporte (Kommo) devuelve `501` en vez de fallar en silencio.

### D6 — La dirección exacta está gateada por dato, no por confianza

`GET /inmuebles/{codigo}` **nunca** devuelve `direccion_exacta`, `latitud` ni `longitud`. La única
ruta a esos campos es `POST /conversations/{id}/location {codigo}`, que los lee server-side y manda un
mensaje de ubicación al cliente — sin que el dato pase jamás por el LLM.

La condición de "visita confirmada" en v1 (sin agenda) es: **existe un handoff abierto para esa
conversación sobre ese `codigo`**. Si no, `409`.

### D7 — Handoff idempotente con titular

Tabla `whatsapp_handoffs` (`conversation_id`, `user_id` titular, `inmueble_id` NULL, `reason`,
`state`, `opened_at`, `closed_at`). `POST /conversations/{id}/handoff` es idempotente: si ya hay uno
abierto devuelve `200` con el mismo recurso, no crea otro. Mientras esté abierto:

- el webhook sale con `handoff: {state, open: true}` y el agente se calla (§3);
- `POST /conversations/{id}/messages` responde **`409`**, no `200` — un `200` haría que el agente
  crea que habló;
- `NotifyAgent` no dispara.

### D8 — Códigos de respuesta, en ambas direcciones

| Código | Significado | Quién reintenta |
|---|---|---|
| `200` | ok | — |
| `401` | token inválido | nadie |
| `409` | handoff abierto / precondición de negocio no cumplida | nadie |
| `422` | payload irreparable, o fuera de la ventana de 24h | nadie |
| `501` | el gateway activo no soporta esa capacidad | nadie |
| `5xx` | fallo transitorio | sí, con backoff |

Simétrico: **nosotros también** dejamos de reintentar los 4xx del agente (H3).

### D9 — `codigo` generado pero editable

El CRM propone `C21-0001` al crear; el asesor lo puede pisar si el anuncio ya se publicó con otro
código. Único siempre. Cubre los dos casos que importan: inmueble nuevo cargado en el CRM, e inmueble
que ya circula con el código del sistema corporativo — y el cliente le dice a Sofía el que ve en el
anuncio, no el nuestro.

### D10 — Media por `media_id` de Meta, subido al cargar, no al enviar

La foto se sube a la Cloud API **cuando el asesor la carga en el CRM**, y el `media_id` se guarda en
`inmueble_medias`. El envío es una referencia, no una descarga.

**Por qué no link/URL firmada como camino principal:** con un link, Meta descarga la imagen *en el
momento del envío*. Un CRM lento, un contenedor recién reiniciado o una firma que venció entre la
decisión de Sofía y la descarga de Meta = **la foto no llega**, y el cliente queda leyendo un mensaje
que habla de fotos inexistentes. Es el peor modo de fallo en medio de una conversación.

La URL firmada (15 min) queda como **fallback** cuando la subida falló o el id venció sin revalidar:
mejor una foto por link que ninguna. Costo asumido: caché de `media_id` + job de revalidación.

### D11 — Handoff siempre al titular; el supervisor se notifica, no reasigna

Sin fallback a otro asesor: derivar a alguien más rompería la promesa que Sofía acaba de hacerle al
cliente ("le paso con `<nombre>`"). Si nadie toma la conversación en 15 min (configurable), se
notifica al supervisor. **Notifica, no reasigna.**

### D12 — Un solo pipeline: `Inmobiliaria C21`, y el flujo va en `lead_type`

**Contradice el §5.3 del handoff**, que pide tres pipelines que no se mezclan. El equipo de C21
reafirmó un pipeline único con etapas ya definidas por ellos (`kl_lead_pipelines.id = 1`, default).
Procedo con eso.

Los tres flujos se distinguen por **`lead_type`**, que ya existe:

| Tool | `lead_type` |
|---|---|
| `registrar_solicitud` | `Comprador` (id 3) — o `Inquilino` (4) si la operación es alquiler |
| `registrar_captacion` | `Propietario` (id 5) |
| `registrar_postventa` | `Postventa` — ⚠️ **hay que crearlo**, no existe |

Descartado distinguir por etapa inicial: el pipeline tiene una sola entrada (`Prospectos`), y agregar
entradas paralelas mezclaría dos ejes en el kanban (de dónde viene vs en qué punto está).

Los pipelines 2, 3 y 4 que creó el setup anterior quedan sin uso. **La instancia no tiene ni un lead**,
así que `crm:rollback-inmobiliaria` los borra limpio cuando se decida.

### D13 — Campos que salieron de las fichas reales, no del brief

Dos anuncios reales de C21 contradijeron el esquema inicial. Agregados:

- **`caracteristicas` (JSON).** Lo buscable va en columnas indexadas; lo específico del tipo va acá y
  se muestra sin filtrarse. Un galpón trae `altura_m`, `m2_galpon`, `m2_oficinas`, `m2_parqueo`; una
  casa trae `frente_m`. Ninguno de esos campos aparece en el handoff, y sin ellos no se vende un
  galpón industrial.
- **`modalidad_cambio`.** Un anuncio dice "en DÓLARES O AL T/C OFICIAL BCB", el otro "al cambio
  paralelo". Es una condición comercial **por inmueble**, y la diferencia es plata real. Refuerza D4:
  no solo no convertimos — la modalidad es un dato que Sofía debe mostrar literal.
- **`precio_anterior`.** El "🚨NUEVO PRECIO🚨 (antes $us 279.000)" es el gancho del anuncio.
- **`negociable`**, **`barrio`**, **`google_maps_url`.**

La ubicación llega como shortlink (`maps.app.goo.gl`): se guarda tal cual y se resuelve a lat/long
siguiendo el redirect al guardar. Ambos campos son dirección exacta y quedan gateados por D6.

### D14 — Asesor titular y supervisor

- **Titular:** usuario con rol `Asesor` (`role_id = 2`). Hoy hay uno: `Sofia` (id 4).
- **Supervisor (D11):** rol `Administrador` (`role_id = 1`), hoy 4 usuarios.
- `inmuebles.user_id` se valida contra rol Asesor en el formulario, no contra cualquier usuario.

⚠️ **Colisión de nombres:** la asesora se llama Sofía y el agente también. El mensaje de handoff
("le paso con Sofía") sería incomprensible para el cliente. Renombrar una de las dos antes de la demo.

### D15 — Pendiente: el pipeline no cierra leads *(sin resolver)*

Krayin detecta cierre por `code === 'won'` / `'lost'` literal. Las etapas terminales del pipeline
`Inmobiliaria C21` son `cierre-exitoso` y `desistimiento`: **ningún lead se cierra nunca**,
`closed_at` queda null y los reportes de conversión quedan vacíos.

Observado también, sin resolver: todas las etapas tienen `probability = 100` (incluida `Prospectos`),
`rotten_days = 500` desactiva la alerta de estancamiento, y tres códigos arrastran typos del slugify
(`agenda-visita-`, `anticipo---garanta`, `devolucin`).

**No lo toco sin autorización.** Sin leads cargados el arreglo es gratis; con leads, ya no.

---

## Contrato de la API v1

Todo bajo `api/v1/inmobiliaria` y `api/v1/whatsapp`, `auth:sanctum`, Bearer.

| Tool del agente | Endpoint | Notas |
|---|---|---|
| `buscar_inmuebles` | `GET /api/v1/inmobiliaria/inmuebles` | filtros: `operacion, tipo, zonas[], precio_min, precio_max, moneda, dormitorios_min, limite`. Solo disponibles (D4). Sin campos gateados. |
| `get_inmueble` | `GET /api/v1/inmobiliaria/inmuebles/{codigo}` | + asesor titular (nombre, teléfono) + punto de encuentro. Sin dirección exacta (D6). |
| `enviar_media` | `POST /api/v1/whatsapp/conversations/{id}/media` | `{codigo, tipo, cantidad}` |
| `enviar_ubicacion` | `POST /api/v1/whatsapp/conversations/{id}/location` | `{codigo}` — gateado por handoff (D6) |
| `registrar_solicitud` | `POST /api/v1/whatsapp/conversations/{id}/solicitud` | crea lead en pipeline **Compradores**, con `temperatura` y `codigos_interes[]`. Devuelve `Solicitud #<lead_id>` |
| `registrar_captacion` | `POST /api/v1/whatsapp/conversations/{id}/captacion` | pipeline **Captación** |
| `registrar_postventa` | `POST /api/v1/whatsapp/conversations/{id}/postventa` | pipeline **Postventa** ⚠️ no existe en el catálogo actual — ver riesgos |
| `get_historial` | `GET /api/v1/whatsapp/conversations/{id}/historial` | leads previos + inmuebles consultados |
| `guardar_telefono` | `POST /api/v1/whatsapp/conversations/{id}/telefono` | normaliza con `Services/PhoneNumber`; `422` si es inválido |
| `derivar_a_asesor` | `POST /api/v1/whatsapp/conversations/{id}/handoff` | `{codigo, reason}` → titular del `codigo` (D7) |

Y el webhook saliente completa el §3 con `channel`, `handoff{state,open}`, `phone_required`,
`phone_prompt_attempts`, `phone_prompt_exhausted`.

---

## Riesgos abiertos

| # | Riesgo | Mitigación |
|---|---|---|
| R1 | `registrar_postventa` no tiene `lead_type` al cual apuntar. | Crear el type `Postventa` (D12). Cambio de una línea en `Config/catalogo.php` + re-correr el setup, que es idempotente. |
| R2 | Sin catálogo cargado, ningún endpoint es demostrable. | ✅ **Resuelto.** CRUD en la UI para el día a día + un seeder con ~15 inmuebles ficticios (con galería) para probar el flujo punta a punta ya mismo. Sin importador CSV en v1. |
| R3 | El CRUD de inmuebles en la UI (DataGrid + formulario + uploader de galería) es tanto trabajo como la API misma. | Está dentro del alcance de v1: sin UI, los asesores no pueden cargar nada. |
| R4 | La suite de tests es destructiva sobre la BD real (H8). | ⬇️ **Degradado.** La instancia es entorno de prueba (confirmado por el equipo), así que el `truncate()` no destruye datos de cliente. `phpunit.xml` se arregla dentro del trabajo, no como prerequisito bloqueante. Vuelve a ser 🔴 el día que esta instancia reciba datos reales. |
| R6 | 🔴 **El contenedor de MySQL tiene 500 MB de límite y vive al 96-97% estando ocioso.** Un `mysqldump` completo lo mata (OOM, exit 137); se cayó dos veces durante esta implementación y una tercera vez 21 h antes, sin intervención. | Swarm lo relevó solo cada vez y no hubo pérdida de datos, pero **no se puede hacer backup completo de esta instancia** — el procedimiento del README de `docs/inmobiliaria/` no es ejecutable hoy. Subir el límite del contenedor antes de cargar catálogo real o de exponer la API al agente. |
| R5 | Fotos servidas por `link` público exponen la galería a cualquiera que adivine la URL. | ✅ **Resuelto (D10):** `media_id` como camino principal — nada de `storage/` queda expuesto. El fallback es URL firmada a 15 min, no link permanente. |

---

## Fase 2 (fuera de esta spec)

`get_disponibilidad` y `agendar_visita` sobre `activities`. Requiere antes definir horario laboral por
asesor y si Google Calendar es fuente de verdad o solo espejo.
