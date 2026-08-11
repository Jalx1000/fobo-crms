# Requisitos de tu parte — Meta, cuentas y accesos

> **Esto es bloqueante.** Sin los puntos marcados 🔴 no se puede probar Messenger ni Instagram, y
> el Embedded Signup de WhatsApp no funciona en absoluto.
> Ordenado por **tiempo de espera**: lo de arriba tarda semanas, lo de abajo minutos.

---

## 1. App de Meta

Como la decisión fue **multi-tenant** (conectar también cuentas de clientes), el CRM pasa a ser
**Tech Provider** ante Meta. Eso sube bastante la vara respecto a una integración de uso propio.

| # | Requisito | Por qué | Estado |
|---|---|---|---|
| 1.1 | 🔴 **App de Meta tipo Business** — App ID + App Secret | Un solo App Secret verifica la firma de los tres canales | ☐ |
| 1.2 | 🔴 App en **modo Live** (no Development) | En Development el popup de Embedded Signup no funciona para terceros | ☐ |
| 1.3 | 🔴 **Business Verification** completada en Business Manager | Requisito duro para Advanced Access y para Tech Provider | ☐ |
| 1.4 | 🔴 **Configuration ID / Solution ID** de Embedded Signup | Es el parámetro que recibe el popup del JS SDK | ☐ |
| 1.5 | ¿WhatsApp vive en **la misma App** que usaremos para FB/IG? | Si están separadas cambia el diseño del secreto de webhook: un secreto por App | ☐ |

> **Si la App de WhatsApp actual es distinta de la que se usará para FB/IG**, avísalo antes del
> Sprint 1: `channel_accounts` necesita entonces guardar a qué App pertenece cada cuenta, y la
> verificación de firma deja de ser única.

## 2. Permisos y App Review

🔴 Todos requieren **Advanced Access**, que se pide por App Review con un video de demostración.

| Canal | Permisos |
|---|---|
| WhatsApp | `whatsapp_business_management`, `whatsapp_business_messaging`, `business_management` |
| Messenger | `pages_messaging`, `pages_manage_metadata`, `pages_show_list`, `business_management` |
| Instagram (vía FB) | `instagram_basic`, `instagram_manage_messages`, más los de Messenger |
| Instagram (directo) | permisos de Instagram Business Login (`instagram_business_basic`, `instagram_business_manage_messages`) |

Además:

- 🔴 **Feature "Human Agent"** — es lo único que permite responder fuera de la ventana de 24 h en
  Messenger e Instagram. Se solicita aparte de los permisos.

> **Mientras tanto sí se puede desarrollar**: con Standard Access la integración funciona con
> usuarios que sean **admin, developer o tester de la App**. Es suficiente para todos los sprints;
> solo la salida a producción con clientes reales necesita Advanced Access.
> Recomendación: **iniciar el App Review en paralelo al Sprint 0**, porque la revisión tarda.

## 3. Cuentas concretas

| # | Requisito | Detalle | Estado |
|---|---|---|---|
| 3.1 | 🔴 Página(s) de Facebook | Nombre + Page ID, y rol de admin para el dev (o el token) | ☐ |
| 3.2 | 🔴 **System User Token** de larga duración con acceso a esas páginas | Un token de usuario normal caduca en 60 días y tumba la integración sin aviso | ☐ |
| 3.3 | 🔴 Cuenta(s) de Instagram **profesional** (Business o Creator) | Personal no sirve, la API de mensajería no existe ahí | ☐ |
| 3.4 | 🔴 En la app de IG: **Configuración → Privacidad → Mensajes → "Permitir acceso a los mensajes"** activado | Sin este toggle **los webhooks de IG nunca llegan**, y no hay ningún error que lo indique | ☐ |
| 3.5 | Para IG vía Facebook: la cuenta IG vinculada a la página | Solo aplica al modo `instagram_via_facebook` | ☐ |
| 3.6 | 🔴 Cuenta FB y cuenta IG **de prueba** que NO sean admin de la App | Es la única forma de probar de verdad la ventana de 24 h y el flujo de un cliente | ☐ |
| 3.7 | WABA + `phone_number_id` de WhatsApp | Ya en producción. Ver memoria del par WABA/phone: desalinearlos da error `132001` | ✅ |

## 4. Infraestructura

| # | Requisito | Detalle | Estado |
|---|---|---|---|
| 4.1 | Dominio HTTPS público confirmado para los webhooks | El de EasyPanel. Meta no acepta HTTP ni certificados autofirmados | ☐ |
| 4.2 | Worker de la cola reiniciado tras cada despliegue | La cola `whatsapp` pasa a llamarse `channels`. Los workers no recogen cambios de `.env` sin reinicio | ☐ |
| 4.3 | Revisar la **CSP del panel admin** contra el JS SDK de Meta | El popup de Embedded Signup exige cargar `connect.facebook.net`. Si la CSP lo bloquea, no hay popup | ☐ |
| 4.4 | Espacio en disco / storage para media | FB e IG traen más adjuntos que WhatsApp y hay que persistirlos porque sus URLs caducan | ☐ |

## 5. Decisiones de negocio

| # | Pregunta | Impacto si no se decide |
|---|---|---|
| 5.1 | ¿Los mensajes de FB/IG crean **lead automático** como hoy WhatsApp (`auto_create_lead`)? | Sugerencia: crear `Person` sí, lead no — IG y FB traen bastante más ruido que WhatsApp |
| 5.2 | ¿El **agente IA** contesta en los tres canales o solo WhatsApp al inicio? | Define si `AgentPayload` necesita el canal desde el Sprint 1 o desde el 5 |
| 5.3 | Fuente del lead: ¿una sola "Redes sociales" o `WhatsApp` / `Messenger` / `Instagram` separadas? | Afecta directamente tus tableros y los filtros de prospectos |
| 5.4 | 🔴 **Resolución de tenant** ante cuenta desconocida y ante colisión | Bloquea el Sprint 0. Ver [multi-tenant.md](multi-tenant.md#decisión-abierta) |
| 5.5 | 🔴 **Política de fusión de contactos** entre canales | Bloquea el Sprint 0. Ver [modelo-datos.md](modelo-datos.md#decisión-abierta-fusión-de-identidades) |

---

## Checklist de arranque

Lo mínimo para que el Sprint 2 (driver de Messenger) no se quede parado:

```
☐ App ID + App Secret
☐ App Review iniciado (aunque no aprobado)
☐ Page ID + System User Token con acceso a la página
☐ Cuenta IG profesional con "Permitir acceso a los mensajes" ON
☐ Un usuario FB y uno IG de prueba, no admin
☐ Dominio HTTPS del webhook confirmado
☐ Respuestas a 5.4 y 5.5
```
