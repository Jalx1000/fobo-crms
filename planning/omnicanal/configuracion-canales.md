# Apartado de configuración — Canales

> Pantalla `Configuración → Canales`. Es el primer entregable con cara visible del proyecto.

---

## Por qué no vive en `core_config`

El `SystemConfig` de Krayin solo soporta campos estáticos:
`text`, `password`, `select`, `boolean`, `color`, `image`, `editor`
(ver `packages/Webkul/Admin/src/Config/core_config.php`).

No hay botón de acción, no hay flujo OAuth, no hay CRUD de N filas. Conectar una página de Facebook
requiere las tres cosas. Por eso: **pantalla dedicada**.

En `core_config` se quedan solo los defaults globales, que sí son campos estáticos:

```
canales.general → auto_create_person, auto_create_lead, default_country_code,
                  ai.enabled, handoff.notify, ventana.aviso_horas
```

---

## Aclaración de terminología

"WhatsApp Business Platform" es el **paraguas** de Meta, e **incluye** a Cloud API. La disyuntiva
real no es *Cloud API vs Business Platform*, sino:

- **Conexión directa** — App y WABA propias
- **A través de un BSP/partner** — Kommo, 360dialog, Twilio… el partner intermedia
- **Embedded Signup** — el CRM es Tech Provider y cada cliente conecta *su* WABA

El código actual ya tiene los dos primeros: los gateways `cloud_api` y `kommo`.

---

## Modos de conexión

Se guarda por cuenta en `channel_accounts.connection_mode`. El `GatewayManager` resuelve el driver
a partir de ese valor, **no** de una config global.

| Canal | `connection_mode` | Qué pide la UI | Estado |
|---|---|---|---|
| WhatsApp | `cloud_api` | `phone_number_id`, `waba_id`, System User Token, `verify_token` | ✅ existe |
| WhatsApp | `embedded_signup` | Botón "Conectar con Facebook" (popup JS SDK) → autoregistra WABA, número y suscripción | 🔨 nuevo |
| WhatsApp | `bsp` | Selector de partner + sus credenciales | ✅ Kommo, generalizar |
| Messenger | `facebook_login` | Botón "Conectar con Facebook" → selector de páginas → guarda Page Token | 🔨 nuevo |
| Messenger | `manual` | `page_id` + Page Access Token pegado a mano (solo desarrollo) | 🔨 nuevo |
| Instagram | `instagram_via_facebook` | Cuenta IG vinculada a una página; se descubre desde `me/accounts` | 🔨 nuevo |
| Instagram | `instagram_direct` | Business Login contra `graph.instagram.com`, sin página de Facebook | 🔨 nuevo |

**Instagram: sin autodetección.** Decisión tomada — dos botones separados, el usuario elige el
método explícitamente. La UI no adivina cuál corresponde.

Consecuencia inmediata: se puede tener WhatsApp por Cloud API directo **y** por Kommo en paralelo
durante una migración de proveedor. Hoy es imposible, `config('whatsapp.gateway')` es único.

---

## Los dos mecanismos de conexión

Conviven en la misma pantalla, y no son intercambiables:

**Redirect OAuth server-side** — para Messenger e Instagram.
El token nunca toca el navegador, no hace falta JS SDK, y es amable con la CSP del panel.

```
[Conectar] → GET settings/channels/connect/messenger
           → redirect a Meta con state firmado (TTL corto)
           → usuario autoriza
           → GET settings/channels/callback/messenger?code=…&state=…
           → verifica state → canjea code por user token de larga duración
           → GET /me/accounts?fields=id,name,access_token,instagram_business_account
           → muestra selector de páginas → guarda channel_account
           → POST /{page_id}/subscribed_apps con los subscribed_fields
```

**Popup del JS SDK de Meta** — obligatorio para el Embedded Signup de WhatsApp.
No hay alternativa server-side. El popup devuelve un `code` al JS, que se postea al backend.

```
[Conectar WABA] → FB.login() con el Configuration ID de Embedded Signup
                → el popup devuelve code
                → POST settings/channels/embedded-signup { code, waba_id, phone_number_id }
                → backend canjea, registra el número, suscribe la app a la WABA
```

> ⚠️ Esto obliga a cargar `connect.facebook.net` en el admin. **Verificar la CSP del panel antes
> del Sprint 6**, o el popup no abre y el síntoma es un botón que no hace nada.

---

## Pantalla

```
┌─ Configuración › Canales ─────────────────────────────────────────────┐
│  Tenant: [Heaven Bolivia ▾]              [+ Conectar canal ▾]          │
│                                           ├ WhatsApp                   │
│                                           ├ Facebook Messenger         │
│                                           └ Instagram                  │
├───────────────────────────────────────────────────────────────────────┤
│ ⓦ  WhatsApp · Heaven Bolivia            Cloud API directo             │
│    +591 700-00000 · phone_id 1093…218                                 │
│    ● Conectado   Webhook ✓   Token: permanente      [Probar] [⋯]      │
├───────────────────────────────────────────────────────────────────────┤
│ ⓕ  Messenger · Heaven Impresiones       Facebook Login                │
│    page_id 1234… · 4 campos suscritos                                 │
│    ▲ Token caduca en 6 días          [Renovar] [Probar] [⋯]           │
├───────────────────────────────────────────────────────────────────────┤
│ ⓘ  Instagram · @heaven_bo               Vía página de Facebook        │
│    ✕ Sin webhook — falta activar "Acceso a mensajes" en la app de IG  │
│                                      [Ver cómo] [Resuscribir] [⋯]     │
└───────────────────────────────────────────────────────────────────────┘
```

Al pulsar "Conectar canal → Instagram", el diálogo ofrece las dos rutas sin preseleccionar:

```
Conectar Instagram
  ⓕ  Con Facebook       → cuenta IG vinculada a una página.
                           Reutiliza el token de la página.
  ⓘ  Con Instagram      → Business Login directo.
      Directo              No requiere página de Facebook.
```

### Drawer de detalle

| Sección | Contenido |
|---|---|
| **Conexión** | Modo, IDs externos, tenant dueño, botones Reconectar / Desconectar |
| **Webhook** | URL con botón copiar, verify token, estado de verificación, campos suscritos con checkboxes, botón "Resuscribir" |
| **Credenciales** | Enmascaradas (`EAAG…7f2a`). Editables solo en modo `manual` |
| **Diagnóstico** | `[Probar conexión]` → token válido → cuenta accesible → app suscrita → último inbound recibido |

El estado de salud se muestra como semáforo en la lista y se persiste en `channel_accounts.health`,
para no depender de llamar a Meta cada vez que se abre la pantalla.

---

## Backend

```
GET    settings/channels                        lista (filtrada por tenant)
GET    settings/channels/{id}
POST   settings/channels                        alta manual
PUT    settings/channels/{id}
DELETE settings/channels/{id}
POST   settings/channels/{id}/test              diagnóstico
POST   settings/channels/{id}/resubscribe       re-suscribe campos del webhook
GET    settings/channels/connect/{channel}      redirect a Meta con state firmado
GET    settings/channels/callback/{channel}     canjea code, lista cuentas, guarda
POST   settings/channels/embedded-signup        recibe el code del popup de WhatsApp
```

### Seguridad — no negociable

- `credentials` con cast `encrypted:array`. El App Secret **no** va a la BD: es de la App.
- `state` firmado con TTL corto en el OAuth, **validado en el callback**. Sin esto, cualquiera
  con el enlace puede inyectar una cuenta ajena en el CRM.
- ACL nuevo `settings.channels.{index,create,edit,delete}`. Solo Administrador por defecto.
- Ningún endpoint devuelve un token completo. Ni siquiera al admin, ni siquiera en el drawer.
- El `tenant_id` **nunca** se acepta desde el request: se deriva de la sesión.
- Rate limit en `connect` y `callback`.

---

## Comando de apoyo

```
php artisan channels:doctor [--account=ID] [--tenant=slug]
```

Reemplaza a `whatsapp:webhook-info` y `whatsapp:kommo-check`, ahora por cuenta y ejecutando
exactamente las mismas verificaciones que el botón "Probar conexión" de la UI.

Razón de existir: cuando algo se rompa en producción hay que poder diagnosticarlo por SSH sin abrir
el navegador — que es como se diagnosticaron el `WHATSAPP_GATEWAY=meta` y el par WABA/phone
desalineado.
