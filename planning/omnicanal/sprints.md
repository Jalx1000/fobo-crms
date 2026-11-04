# Sprints 0–7

> 1 dev full-time. Con front y back en paralelo se comprime a ~6 semanas.
> Formato heredado del [planning de WhatsApp](../README.md): historias con criterios de aceptación.

| Sprint | Objetivo | Duración | Riesgo |
|---|---|---|---|
| [0](#sprint-0--fundación-de-datos) | Fundación de datos: tenants, cuentas, identidades | 1 sem | **Alto** - toca datos vivos |
| [1](#sprint-1--núcleo-multicanal) | Núcleo multicanal: gateway por cuenta, webhooks por canal | 1 sem | Medio |
| [2](#sprint-2--driver-messenger) | Driver Messenger | 1 sem | Medio - depende de tus tokens |
| [3](#sprint-3--drivers-instagram) | Drivers Instagram (los dos modos) | 1 sem | Bajo si el 2 salió bien |
| [4](#sprint-4--multi-tenant-y-aislamiento) | Multi-tenant y aislamiento | 1 sem | **Alto** - es seguridad |
| [5](#sprint-5--configuración--canales) | Pantalla de configuración + OAuth | 1 sem | Medio |
| [6](#sprint-6--embedded-signup) | Embedded Signup de WhatsApp | 0.5 sem | Medio - CSP + JS SDK |
| [7](#sprint-7--front-multicanal-y-cierre) | Front multicanal, agente IA agnóstico, hardening | 1.5 sem | Bajo |

**Orden deliberado:** el aislamiento multi-tenant (4) va **antes** de la pantalla de configuración
(5) y del Embedded Signup (6). Conectar cuentas de terceros antes de tener el aislamiento probado es
exponer datos de un cliente a otro.

---

## Sprint 0 - Fundación de datos

**Objetivo:** que el esquema soporte omnicanal sin que nada del WhatsApp actual deje de funcionar.

> 🔴 Bloqueado por dos decisiones: resolución de tenant ([multi-tenant.md](multi-tenant.md#decisión-abierta))
> y política de fusión ([modelo-datos.md](modelo-datos.md#decisión-abierta-fusión-de-identidades)).

### HU-01 · Migraciones aditivas - 1d `[BACK]`
`channel_tenants`, `channel_accounts`, `channel_identities` con sus modelos, contratos, proxies y
repositorios (`Webkul\Core\Eloquent\Repository`).
**AC:**
- `credentials` con cast `encrypted:array`; un `dd()` del modelo no muestra el token en claro.
- UNIQUE `(channel, external_id)` en cuentas y `(channel, account_id, external_id)` en identidades.
- `migrate` y `migrate:rollback` limpios.
- Nada existente modificado: el inbox de WhatsApp sigue igual.

### HU-02 · Comando de backfill - 1.5d `[BACK]`
`php artisan channels:backfill [--dry-run]` según [modelo-datos.md](modelo-datos.md#estrategia-de-migración).
**AC:**
- `--dry-run` imprime el plan y no escribe nada.
- Aborta si el conteo antes/después no coincide.
- Idempotente: correrlo dos veces no duplica cuentas ni identidades.

### HU-03 · Rename de tablas vivas - 1.5d `[BACK]`
`whatsapp_conversations` → `conversations`, `whatsapp_messages` → `messages`, con las columnas
nuevas y el reemplazo del UNIQUE de `wa_phone`.
**AC:**
- Probado antes sobre una **copia restaurada de producción**, no solo en local vacío.
- `down()` ejecutado de verdad, no solo escrito.
- El UNIQUE compuesto se crea en la **misma** migración que quita el de `wa_phone`.
- Checklist de despliegue: pausar la cola → migrar → reiniciar workers.

### HU-04 · `TenantResolver` e `IdentityResolver` - 1d `[BACK]`
Servicios con los dos huecos de decisión resueltos según lo que se defina.
**AC:** tests unitarios de los casos borde (cuenta desconocida, colisión, candidato ambiguo).

---

## Sprint 1 - Núcleo multicanal

**Objetivo:** que el gateway se resuelva por cuenta y entren webhooks de tres canales distintos.

### HU-05 · `Gateway` extendido - 1d `[BACK]`
Añadir al contrato: `channel()`, `reopenStrategy()`, `sendMedia()`, `downloadMediaFromUrl()`,
`fetchProfile()`, `markSeen()`. `sendText()` pasa a recibir la `ChannelAccount`.
**AC:** `CloudApiGateway` y `KommoGateway` siguen pasando sus tests tras el cambio de firma.

### HU-06 · `GatewayManager` por cuenta - 1d `[BACK]`
`active()` deja de leer `config('whatsapp.gateway')`. Resolución por `channel_account`.
**AC:** dos cuentas de WhatsApp con modos distintos (`cloud_api` y `bsp`) conviven y cada
conversación se envía por la suya.

### HU-07 · Webhooks por canal - 1.5d `[BACK]`
`/api/v1/channels/{canal}/webhook` (GET verify + POST recibir), con verificación de firma común.
La ruta actual `/api/v1/whatsapp/webhook` se mantiene como **alias**, para no romper la
configuración de Meta ya activa en producción.
**AC:**
- Firma inválida → 401, no encola.
- Resuelve tenant y cuenta **antes** de encolar; si no resuelve, aplica la política de cuenta
  desconocida decidida en el Sprint 0.
- Responde 200 en <500 ms.

### HU-08 · Ingesta genérica - 1.5d `[BACK]`
`IngestInbound(channel, accountId, payload)` reemplaza a `IngestInboundPayload`. Descarga y persiste
media, incluyendo el caso URL (FB/IG).
**AC:** una URL de adjunto caducada no rompe la ingesta; el mensaje se guarda sin archivo y queda
registrado el fallo.

---

## Sprint 2 - Driver Messenger

> 🔴 Bloqueado por Page ID + System User Token ([requisitos-meta.md](requisitos-meta.md#3-cuentas-concretas)).

### HU-09 · `MessengerGateway`: inbound - 1.5d `[BACK]`
Verify, firma, parse de `entry[].messaging[]`: texto, adjuntos, postbacks, reacciones, referrals.
**AC:** PSID mapeado a `external_contact_id`; idempotencia por `mid`.

### HU-10 · `MessengerGateway`: outbound - 1d `[BACK]`
`POST /{page_id}/messages` con el Page Token de la cuenta. Texto y media.
**AC:** el error de Meta se persiste legible en `messages.error`, no un volcado crudo.

### HU-11 · Echoes - 0.5d `[BACK]`
`message_echoes` ingestados como salientes con `is_echo = true`.
**AC:** un mensaje enviado desde la Bandeja de Meta aparece en el hilo del CRM y **no** se duplica
con el que envió el propio CRM.

### HU-12 · Ventana y `HUMAN_AGENT` - 1d `[BACK]`
`window_expires_at` y `reopen_strategy = human_agent_tag`.
**AC:** fuera de ventana el envío normal se bloquea con un mensaje claro, no con un 500.

---

## Sprint 3 - Drivers Instagram

### HU-13 · `InstagramViaFacebookGateway` - 1.5d `[BACK]`
Hereda la base de Messenger. Suma story replies, menciones y shares.
**AC:** una respuesta a story se distingue de un DM normal en el payload persistido.

### HU-14 · `InstagramDirectGateway` - 1.5d `[BACK]`
Business Login contra `graph.instagram.com`, token propio, sin página de Facebook.
**AC:** funciona con una cuenta IG **no** vinculada a ninguna página.

### HU-15 · Base compartida - 1d `[BACK]`
Extraer `MetaMessagingGateway` con lo común a los tres drivers de Meta (firma, sobre del webhook,
Send API, descarga de media por URL).
**AC:** los tres drivers pasan la misma batería de tests de contrato.

---

## Sprint 4 - Multi-tenant y aislamiento

**Objetivo:** que un cliente no pueda ver ni tocar los datos de otro. Es seguridad, no una feature.

### HU-16 · Scoping del inbox - 1.5d `[BACK]`
`tenant_id` aplicado en lista, hilo, búsqueda, envío, notas y respuestas rápidas.
**AC:** acceso cruzado responde **404**, no 403 - no confirmar que el recurso existe.

### HU-17 · Scoping de la API del agente IA - 1d `[BACK]`
El token de Sanctum resuelve el/los tenants del usuario.
**AC:** un token del tenant A no lee ni postea en conversaciones del B.

### HU-18 · Scoping de jobs y notificaciones - 1d `[BACK]`
Handoff, correos y comandos artisan reciben el tenant explícito.
**AC:** el destinatario del handoff sale siempre de los usuarios del tenant dueño.

### HU-19 · Tests de fuga - 1.5d `[QA]`
Los cinco tests obligatorios de [multi-tenant.md](multi-tenant.md#tests-obligatorios).
**AC:** verdes, y forman parte de la DoD de todo endpoint nuevo a partir de aquí.

---

## Sprint 5 - Configuración → Canales

### HU-20 · CRUD de cuentas - 1.5d `[BACK]` `[FRONT]`
Pantalla, listado con semáforo de salud, drawer de detalle, alta manual, ACL
`settings.channels.*`.
**AC:** ningún endpoint devuelve un token completo; `tenant_id` nunca se acepta del request.

### HU-21 · OAuth Messenger + Instagram - 1.5d `[BACK]`
`connect` / `callback` con `state` firmado y TTL corto, selector de páginas, suscripción de campos.
**AC:** un `state` inválido o vencido rechaza el callback; nunca se guarda la cuenta.

### HU-22 · Diagnóstico - 1d `[BACK]` `[FRONT]`
Botón "Probar conexión" + `php artisan channels:doctor`, mismas verificaciones en ambos.
**AC:** detecta los tres fallos ya vividos: token vencido, app no suscrita, y el toggle de mensajes
de IG apagado.

### HU-23 · Defaults globales en `core_config` - 0.5d `[BACK]`
Bloque `canales.general` con los campos estáticos.
**AC:** los overrides por tenant (`channel_tenants.settings`) ganan sobre el default global.

---

## Sprint 6 - Embedded Signup

### HU-24 · Popup y canje - 2d `[BACK]` `[FRONT]`
JS SDK de Meta con el Configuration ID, `POST settings/channels/embedded-signup`, registro del
número y suscripción de la app a la WABA.
**AC:**
- CSP del panel verificada **antes** de empezar: si bloquea `connect.facebook.net`, el botón
  simplemente no hace nada y no hay error visible.
- Un signup a medias no deja una `channel_account` inconsistente.

### HU-25 · Modo `bsp` generalizado - 0.5d `[BACK]`
Kommo pasa a ser un partner del modo `bsp`, no un gateway de primer nivel.
**AC:** las conversaciones de Kommo existentes siguen enviando y recibiendo.

---

## Sprint 7 - Front multicanal y cierre

### HU-26 · Inbox multicanal - 3d `[FRONT]`
Todo lo de [front.md](front.md): badges, filtros, compositor por capacidades, contador de ventana,
tipos de burbuja nuevos, sección de identidades, selector de tenant.
**AC:** cada tipo de mensaje nuevo tiene guarda contra campos nulos - el sticker sin archivo ya dejó
la vista en blanco una vez.

### HU-27 · Agente IA agnóstico de canal - 1.5d `[BACK]`
`AgentPayload` incluye canal y capacidades; el agente sabe qué puede enviar.
**AC:** el contrato del payload se versiona; el agente actual no se rompe.

### HU-28 · Menú, ACL y traducciones - 0.5d `[FRONT]`
El menú "WhatsApp" pasa a "Bandeja". ACL por canal.
**AC:** un usuario sin permiso de Instagram no ve esas conversaciones ni el filtro.

### HU-29 · Hardening - 2d `[QA]`
Tests Pest de los tres drivers, rate limit, idempotencia, reintentos, Swagger, y `channels:doctor`
documentado.
**AC:** DoD global del [README](README.md#definición-de-hecho-global) cumplida.
