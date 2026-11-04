# Omnicanal - WhatsApp + Messenger + Instagram

> **Estado:** planificación. Nada de esto está construido todavía.
> **Punto de partida:** el módulo `Webkul\Whatsapp` en producción (rama `imprimir-modulo-whatsapp`)
> y el scaffold vacío `Webkul\Messenger`.

Feature: convertir el inbox de WhatsApp en una **bandeja omnicanal** que recibe y envía por
WhatsApp Cloud API, Facebook Messenger e Instagram Direct, con **varias cuentas conectadas a la vez**
y **aislamiento por cliente (multi-tenant)**.

## Documentos

| Documento | Contenido |
|---|---|
| [requisitos-meta.md](requisitos-meta.md) | **Lo que hace falta de tu parte.** Apps, permisos, tokens, App Review. Bloqueante |
| [modelo-datos.md](modelo-datos.md) | Esquema nuevo y estrategia de migración de las tablas vivas |
| [configuracion-canales.md](configuracion-canales.md) | El apartado de configuración: modos de conexión, OAuth, pantalla |
| [multi-tenant.md](multi-tenant.md) | Aislamiento de datos entre clientes. Decisiones abiertas |
| [front.md](front.md) | Inbox multicanal: lista, hilo, compositor por capacidades |
| [sprints.md](sprints.md) | Sprints 0–7 con historias y criterios de aceptación |

---

## Qué cambia respecto a lo que ya existe

El módulo actual acertó en la abstracción - el contrato `Gateway`, los DTOs canónicos y el
`GatewayManager` ya aíslan al núcleo del proveedor. Lo que hay que romper son **tres supuestos
WhatsApp-only** incrustados debajo:

| Supuesto actual | Dónde vive | Cambio |
|---|---|---|
| Un solo proveedor activo por instalación | `config('whatsapp.gateway')`, `GatewayManager::active()` | Tabla `channel_accounts`; el gateway se resuelve **por cuenta** |
| La conversación se identifica por teléfono | `whatsapp_conversations.wa_phone` UNIQUE | Clave `(channel, account_id, external_contact_id)` |
| El contacto se resuelve por `Person.contact_numbers` | `ConversationResolver::resolvePerson()` | Tabla `channel_identities` + fallback al teléfono |

> ⚠️ Esto **deroga** la restricción declarada en
> [`../arquitectura.md`](../arquitectura.md#restricción-de-despliegue-define-el-diseño)
> ("una instalación = un proveedor activo, sin multi-tenant"). Aquel documento describe el diseño
> vigente hasta hoy; este lo reemplaza a partir del Sprint 0 de omnicanal.

---

## Decisiones tomadas

| # | Decisión | Elección | Consecuencia |
|---|---|---|---|
| 1 | Alcance de cuentas | **Multi-tenant.** Se conectan también cuentas de clientes | +1 sprint. Tech Provider ante Meta. Aislamiento de datos obligatorio |
| 2 | Modos WhatsApp | **Los tres**: Cloud API directo, BSP/partner (Kommo), Embedded Signup | El selector de conexión ofrece las tres rutas |
| 3 | Instagram | **Ambos caminos, elección explícita** por parte del usuario - sin autodetección | Dos drivers y dos botones distintos en la UI de conexión |
| 4 | Dónde vive la configuración | **Pantalla dedicada** `Configuración → Canales`, no `core_config` | `SystemConfig` de Krayin solo soporta campos estáticos; no hay OAuth ni CRUD |
| 5 | Tenant | **Tabla propia** `channel_tenants`, no los `groups` de usuarios de Krayin | Los grupos son de personas, no de propiedad de datos |
| 6 | Tiempo real | **Polling** en v1; broadcasting a v2 | Menos superficie de fallo mientras se estabilizan tres canales |
| 7 | Nombre de las tablas | `whatsapp_*` → `conversations` / `messages` (rename con backfill) | Commit aparte, con rollback probado |

### Pendientes de decidir (bloquean el Sprint 0)

1. **Resolución de tenant en el webhook** - qué hacer con una cuenta desconocida y con una
   colisión de `external_id` entre tenants. Ver [multi-tenant.md](multi-tenant.md#decisión-abierta).
2. **Política de fusión de contactos** entre canales sin teléfono (IG/FB) y `Person` existentes.
   Ver [modelo-datos.md](modelo-datos.md#decisión-abierta-fusión-de-identidades).
3. Fuente del lead: una sola "Redes sociales" o separada por canal (afecta los tableros).
4. Si el agente IA contesta en los tres canales o solo en WhatsApp al inicio.

---

## Arquitectura

```
   WhatsApp Cloud API        Messenger (Página FB)       Instagram Direct
          │                          │                          │
          │  mismo sobre de webhook: { object, entry[] }         │
          │  misma firma: X-Hub-Signature-256 (App Secret)       │
          ▼                          ▼                          ▼
┌──────────────────────── LARAVEL (hub, fuente de verdad) ────────────────────────┐
│                                                                                  │
│  POST /api/v1/channels/{canal}/webhook                                           │
│    → verifica firma → resuelve TENANT + CUENTA por external_id                   │
│    → 200 en <500ms → encola (cola "channels")                                    │
│         │                                                                        │
│         ▼                                                                        │
│  IngestInbound(channel, accountId, payload)                                      │
│    → driver->parseWebhook() → DTOs canónicos                                     │
│    → ConversationResolver: (channel, account, external_contact_id)               │
│    → IdentityResolver: channel_identities → Person → Lead                        │
│    → descarga y persiste media (las URLs de FB/IG CADUCAN)                       │
│    → ¿ai_enabled? ── sí ──► webhook saliente al agente (n8n u otro)              │
│                                                                                  │
│  Envío:  SendMessage(conversation) → GatewayManager::for($conversation)           │
│          → driver del canal + credenciales de SU cuenta → API de Meta            │
│                                                                                  │
│  Configuración: Configuración → Canales (CRUD de channel_accounts + OAuth)       │
└──────────────────────────────────────────────────────────────────────────────────┘
          ▲ polling
   Inbox omnicanal (Blade + Vue)
```

### Lo que ya sirve tal cual

- Contrato `Gateway` y DTOs (`InboundMessage`, `ContactIdentity`, `Capabilities`, `SendResult`…)
- Patrón webhook → firma → cola → 200 rápido
- Idempotencia por id de mensaje del proveedor
- `Handoff` (derivación a asesor) y la API del agente IA - solo hay que volverlos agnósticos de canal
- Todo el inbox Vue: hilo, perfil, notas, respuestas rápidas, visor de imágenes

### Diferencias por canal que el código debe absorber

| | WhatsApp Cloud | Messenger | Instagram |
|---|---|---|---|
| Identidad del contacto | teléfono E.164 | PSID (por página) | IGSID (por cuenta) |
| Envío | `POST /{phone_number_id}/messages` | `POST /{page_id}/messages` | `POST /{ig_id}/messages` |
| Credencial de envío | System User Token / token del BSP | Page Access Token | Page Token o token IG directo |
| Ventana libre | 24 h → reabre con **plantilla HSM** | 24 h → reabre con tag **`HUMAN_AGENT`** | 24 h → reabre con tag **`HUMAN_AGENT`** |
| Media entrante | `media_id` → `GET /{media_id}` | **URL firmada que caduca** | **URL firmada que caduca** |
| Eco de salientes | callbacks de estado | `message_echoes` | `message_echoes` |
| Extras | reacciones, ubicación, contactos | postbacks, referrals, handover | story reply / mention, share, reacciones |

> La caducidad de las URLs de adjuntos de FB/IG obliga a **descargar y persistir en la ingesta**.
> Guardar la URL y resolverla al pintar el hilo produce imágenes rotas a los pocos días.

---

## Alcance

### Dentro (v1)

- Recepción y envío en los tres canales, con N cuentas activas simultáneas
- Multi-tenant con aislamiento de datos verificado por tests
- Apartado de configuración con conexión por OAuth y diagnóstico
- Identidad unificada: un `Person` con teléfono WA + PSID + IGSID
- Tipos: texto, imagen, audio, video, documento, sticker, ubicación, reacción, reply,
  story reply/mention (IG), postback (FB)
- Ventana de 24 h por canal, con su estrategia de reapertura
- Ingesta de *echoes*
- Agente IA y derivación a asesor operando en los tres canales
- ACL por tenant y por canal

### Fuera (v1) - anotado, no construido

- Gestión de plantillas HSM desde el CRM (hoy se disparan por Kommo/n8n) → v2
- Handover Protocol de Meta (`pass_thread_control`) - solo si convive otra app en la misma página
- Comentarios de feed de FB/IG y menciones públicas (otra superficie de webhooks)
- Telegram, WhatsApp Business App (no-API), correo, web-chat
- Broadcasting en tiempo real (queda polling)
- Analítica y facturación por conversación de Meta

---

## Definición de Hecho (global)

Hereda la del [README de planning](../README.md) y agrega:

- `./vendor/bin/pint` limpio
- Tests Pest verdes, **incluyendo al menos un test de fuga entre tenants por cada endpoint nuevo**
- Ningún token ni credencial en el repo, en logs ni en respuestas JSON (siempre enmascarados)
- Toda migración que toque datos vivos tiene su `down()` probado en una copia de producción
- Endpoints nuevos anotados en Swagger
