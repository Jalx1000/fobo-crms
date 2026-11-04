# Front - Inbox omnicanal

> Base: `packages/Webkul/Whatsapp/src/Resources/views/inbox-component.blade.php`
> (Vue 3 inline registrado como `v-whatsapp-inbox`, ~1875 líneas).
> **No se reescribe.** Se vuelve multicanal de forma incremental.

---

## Layout

```
┌──────────────────────────────────────────────────────────────────────┐
│  Bandeja        [Todos ▾] [WA] [FB] [IG]   🔍 buscar   ⚙  ← filtros   │
├───────────────┬──────────────────────────────┬───────────────────────┤
│ CONVERSACIONES│  HILO                        │  PERFIL (ya existe)   │
│               │                              │                       │
│ ⬤ Ana Pérez   │  ┌ badge canal + cuenta ┐    │  Tabs: Datos / Notas  │
│   [IG] 2m  •3 │  │ Instagram · @heaven  │    │  Leads / Productos    │
│   "hola, el…" │  └──────────────────────┘    │                       │
│               │                              │  ⚡ Identidades:      │
│ ⬤ Juan L.     │   ← respuesta a story        │    📱 +591 7xxx       │
│   [WA] 1h     │   [globos de mensaje]        │    ⓕ Juan L. (PSID)   │
│   Sin asesor  │                              │    ⓘ @juanl (IGSID)   │
│               │  ⏱ Ventana: 3 h 12 m         │  [Fusionar contacto]  │
│ ⬤ Marcos      │  ┌──────────────────────┐    │                       │
│   [FB] ayer   │  │ escribe…  📎 😊 ⚡    │    │                       │
│   ventana ⨯   │  └──────────────────────┘    │                       │
└───────────────┴──────────────────────────────┴───────────────────────┘
```

---

## Cambios por componente

| Componente | Cambio |
|---|---|
| **Lista de conversaciones** | Badge de canal (icono + color), filtro por canal y por cuenta, avatar desde `profile_pic_url` de Meta cuando exista |
| **Cabecera del hilo** | Muestra canal + cuenta receptora ("Instagram · @heaven_bo"), no solo el teléfono. Con varias cuentas conectadas, saber *por cuál* entró el mensaje es esencial |
| **Compositor** | Se renderiza **desde `capabilities()`** de la cuenta: si el canal no soporta documento, el clip no lo ofrece. El patrón ya existe en el DTO `Capabilities`; falta consumirlo **por conversación** en vez de global |
| **Ventana 24 h** | Contador regresivo. Al expirar: WhatsApp → "Enviar plantilla" (deshabilitado en v1); FB/IG → aviso "fuera de ventana, solo con etiqueta de agente humano" |
| **Burbujas** | Tipos nuevos: respuesta a story de IG (con miniatura), mención, reacción (emoji bajo la burbuja), postback de FB, y *echo* marcado como "enviado desde Meta" |
| **Sidebar de perfil** | Sección nueva **Identidades** con las cuentas vinculadas del contacto y la acción de fusionar |
| **Selector de tenant** | Solo visible para usuarios con acceso a más de uno. Cambia el contexto de toda la pantalla |
| **Tiempo real** | Se mantiene **polling** en v1. Broadcasting queda para v2: menos superficie de fallo mientras se estabilizan tres canales a la vez |

---

## Detalles que importan

**Identidad visual por canal.** El badge no puede ser solo texto: con tres canales mezclados en una
lista, el asesor necesita distinguirlos de un vistazo. Icono + color, consistentes en modo claro y
oscuro (el inbox ya soporta `dark:`).

**Estado de la ventana en la lista, no solo en el hilo.** Una conversación con la ventana vencida
requiere una acción distinta. Verlo solo al abrirla hace perder tiempo.

**El compositor es la única fuente de verdad de lo que se puede enviar.** Si `capabilities()` no
declara `document`, el botón no aparece - no aparece y falla. Esa es la regla que ya documenta el
DTO `Capabilities` ("una capacidad listada siempre debe funcionar") y hay que sostenerla al
multiplicar canales.

**Los adjuntos se sirven siempre desde `media_path`**, nunca desde `attachment_url`. Las URLs de
FB/IG caducan; usar la URL original produce imágenes rotas a los pocos días, sin error visible.

**Cuidado con el render nulo.** Ya pasó una vez: un sticker sin archivo dejó la vista en blanco
porque el template desreferenciaba `msg.media.url` con `media` en `null` y Vue abortaba el render
completo. Con tres canales y más tipos de mensaje (reacciones, postbacks, menciones), cada tipo
nuevo necesita su guarda.

---

## Reutilización

Se conserva sin cambios: hilo, perfil con tabs, notas, respuestas rápidas, visor de imágenes con
zoom, etapas de lead, creación rápida de lead, switch de agente IA y todo el flujo de derivación a
asesor. El trabajo de front es **aditivo**, no una reescritura.
