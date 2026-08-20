# App Review — `pages_messaging` (Advanced Access)

> **Por qué**: con Standard Access la User Profile API solo responde por personas
> con un rol en la app. Los clientes reales devuelven `code 100 / subcode 33`, así
> que los contactos entran sin nombre ni foto (ver personas 239 y 240 vs. 238).
> Los mensajes sí llegan — lo que falta es el perfil.

Datos del entorno:

| | |
|---|---|
| Página conectada | `103485512431924` |
| Webhook | `https://imprimir.sofopolis.com/api/v1/webhooks/messenger` |
| Bandeja (backoffice) | `https://imprimir.sofopolis.com/admin/whatsapp` |

---

## 1. Descripción del uso

> Pegar en *"Describe cómo tu app usa este permiso"*.

Nuestra app es el CRM interno de Imprimir, una imprenta. No es un producto que se
distribuya a terceros: lo usa únicamente nuestro equipo, sobre nuestra propia
página de Facebook.

Los clientes nos escriben a la página para pedir cotizaciones de trabajos de
impresión, consultar precios, plazos y estado de sus pedidos. Usamos
`pages_messaging` para atender esas consultas desde una bandeja de entrada
unificada dentro del CRM, en lugar de responder desde la app de Facebook. Esto
nos permite que la conversación quede vinculada al contacto y a la oportunidad
de venta correspondiente.

Concretamente, usamos el permiso para tres cosas:

1. **Recibir los mensajes** que los clientes envían a nuestra página, mediante el
   webhook de mensajería. Cada entrega se valida con la firma HMAC del secreto de
   la app antes de procesarse.
2. **Responder** a esos clientes con las respuestas que escribe un asesor humano
   de nuestro equipo (texto y archivos adjuntos: presupuestos en PDF, muestras de
   diseño).
3. **Leer el nombre público y la foto de perfil** de quien nos escribe, para que
   el asesor sepa con quién está hablando y pueda vincular la conversación con la
   ficha del cliente que ya existe en el CRM. Sin esto, las conversaciones
   aparecen identificadas solo por un PSID, que no le dice nada a una persona.

No usamos el permiso para enviar mensajes masivos, promocionales ni no
solicitados. Solo respondemos a conversaciones que el cliente inicia, dentro de
la ventana estándar de 24 horas.

---

## 2. Instrucciones para reproducir

> Pegar en *"Proporciona instrucciones sobre cómo reproducir esta función"*.
> Adjuntar credenciales de un usuario de prueba del CRM por el canal seguro que
> ofrece el formulario, nunca en el texto.

```
1. Abrí la página de Facebook de Imprimir y enviale un mensaje como
   cualquier cliente, por ejemplo: "Hola, quiero cotizar 500 tarjetas".

2. Ingresá al CRM en https://imprimir.sofopolis.com/admin/whatsapp
   con el usuario de prueba que adjuntamos.

3. La conversación aparece en la lista de la izquierda, con la etiqueta
   del canal "Messenger". Se muestra el nombre y la foto de perfil de
   quien escribió: eso es lo que obtenemos con pages_messaging.

4. Hacé clic en la conversación para abrir el hilo. Se ve el mensaje
   recibido.

5. Escribí una respuesta en el campo de abajo y enviala.

6. Volvé a Messenger: la respuesta llegó a la conversación con la página.
```

---

## 3. Guion del video

Meta rechaza videos que no muestren el recorrido completo. Una sola toma
continua, sin cortes, mostrando ambos lados de la conversación.

| # | Toma | Qué tiene que verse |
|---|---|---|
| 1 | Messenger, del lado del cliente | Enviar un mensaje real a la página |
| 2 | CRM, bandeja de entrada | La conversación aparece **con nombre y foto de perfil**, etiquetada como Messenger |
| 3 | CRM, hilo abierto | El mensaje recibido, y el asesor escribiendo la respuesta |
| 4 | Messenger, del lado del cliente | La respuesta llegó |

Recomendaciones:

- **La toma 2 es la que justifica el permiso.** Que se vea claro el nombre y el
  avatar, no un PSID. Si al grabar todavía no está aprobado el Advanced Access,
  grabá con una cuenta que tenga rol en la app (así resuelve el perfil) y aclará
  esa limitación en la descripción.
- Sin cortes ni edición entre tomas: el revisor tiene que poder seguir el mismo
  mensaje de punta a punta.
- Mostrar la URL del navegador para que se vea que es nuestro CRM.
- Si el CRM está en español, agregá subtítulos en inglés.

---

## 4. Llamadas de prueba obligatorias

Meta exige actividad reciente de la API con el permiso. Ya la hay — la página
recibe y envía mensajes — pero conviene verificarlo antes de enviar:

- **App Dashboard → App Review → Requests**, sección de llamadas de prueba: tiene
  que figurar `pages_messaging` con actividad de los últimos 30 días.
- Si aparece vacío, mandá un mensaje a la página y respondelo desde el CRM: eso
  genera una recepción por webhook y un `POST /me/messages`.

---

## 5. Uso permitido

Marcar la confirmación. Lo que declaramos y cumplimos:

- Solo respondemos conversaciones **iniciadas por el cliente**.
- Solo dentro de la **ventana de 24 horas**; fuera de ella el sistema bloquea el
  envío de texto libre.
- **No** vendemos, cedemos ni compartimos los datos con terceros.
- El nombre y la foto se usan **únicamente** para identificar al cliente dentro de
  nuestro CRM.
- No hacemos envíos masivos ni promocionales por este canal.

---

## 6. Tratamiento de datos

### `processor-0` → **Sí**

Los datos de Meta salen del servidor. Del `.env`:

```
WHATSAPP_AGENT_WEBHOOK_URL=https://odontoking-langchain-copy-production.up.railway.app/...
```

El agente de IA corre en **Railway** y recibe, por cada mensaje: nombre del
contacto, `conversation_id`, texto del mensaje e historial de los últimos 15.
Todo eso es Platform Data de Meta.

| Encargado | Qué recibe | Estado |
|---|---|---|
| **Railway** (agente IA) | nombre, id de conversación, mensajes, historial | confirmado en el `.env` |
| **Proveedor del LLM** detrás del agente | lo mismo, en cada prompt | ⚠️ verificar en el repo del agente |
| **Proveedor del servidor** del CRM | todo, en reposo | el VPS bajo EasyPanel |
| **Sofopolis** | opera CRM y correo (`mail.sofopolis.com`) | solo si es entidad legal distinta de Imprimir |

Los correos de derivación (`handoff/requested.blade.php`) incluyen nombre del
contacto y los últimos mensajes, y salen por `mail.sofopolis.com`.

> ⚠️ **Sobre `MESSENGER_AI_ENABLED`**: hasta ahora solo fijaba el default de las
> conversaciones nuevas, así que un asesor podía encender la IA por conversación
> desde la bandeja — y ya había ocurrido, es lo que produjo el crash del agente
> con datos de Messenger.
>
> Ya se corrigió: ahora es un **interruptor de canal**. Con `false`, ninguna
> conversación de Messenger llega al agente (`Conversation::aiEffective()`).
>
> **Qué declarar depende de cómo quede la variable al enviar la solicitud:**
>
> - Si va a quedar en `false`, los datos de Messenger **no** salen hacia Railway
>   y no hace falta listarlo como encargado para este canal.
> - Si se activa en algún momento, **sí** salen, y hay que declararlo — además de
>   la obligación de Meta de informar las experiencias automatizadas (ver el
>   bloque final de este documento).

> ⚠️ El host se llama `odontoking-langchain-**copy**`. Odontoking es otro cliente.
> Si es un clon dedicado a Imprimir, es solo un nombre heredado. Si es una
> instancia **compartida**, hay datos de clientes de Imprimir en infraestructura
> de otro cliente. Confirmar antes de firmar la declaración.

### `responsible-1` / `responsible-2`

El responsable es quien **decide para qué se usan los datos**, no quien los opera.
Si Imprimir es dueña de la página y define el propósito:

- **Responsable** = razón social completa de Imprimir (no el nombre comercial)
- **Sofopolis** = encargado del tratamiento, va en `processor-0`

País: confirmar. Por los números `+591` del sistema, presumiblemente Bolivia.

### `requests-3` → **"No."**

Pregunta por entregas a autoridades por pedidos de seguridad nacional en los
últimos 12 meses. No incluye órdenes judiciales penales.

### `requests-4`

**No marcar casillas de políticas que no existen.** Es una declaración formal; si
no hay procesos escritos, *"Ninguna de las anteriores"* es la respuesta honesta y
no bloquea la revisión — la pregunta es descriptiva, no un requisito.

---

## 7. Acceso al sitio para revisión

| Campo | Respuesta |
|---|---|
| URL del sitio | `https://imprimir.sofopolis.com/` |
| URL de login | `https://imprimir.sofopolis.com/admin/login` |
| `fblogin-web-1` | **No** — no hay Facebook Login (sin Socialite ni config de Facebook) |
| `accesscode-web-1` | usuario y contraseña del revisor |
| `accesscode-web-2` | vacío — no es app de tienda |
| `geo-web-5` | vacío, o "sin restricción geográfica" |
| `documents-web-1` | el video del guion de la sección 3 |

### `instructions-web-2` — español

```
ACCESO
------
URL de login: https://imprimir.sofopolis.com/admin/login
Usuario y contraseña: los adjuntamos en el campo de credenciales de prueba.

La app es el CRM interno de nuestra empresa. No tiene registro público ni
Facebook Login: el acceso es con las credenciales que proporcionamos.

CÓMO PROBAR LA FUNCIÓN
----------------------
1. Enviá un mensaje a nuestra página de Facebook (id 103485512431924)
   desde tu propia cuenta, por ejemplo: "Hola, quiero cotizar 500
   tarjetas". La app solo procesa conversaciones que inicia el usuario.

2. Iniciá sesión en https://imprimir.sofopolis.com/admin/login

3. En el menú lateral, entrá a la sección de mensajería
   (https://imprimir.sofopolis.com/admin/whatsapp).

4. Tu conversación aparece en la lista de la izquierda, etiquetada como
   "Messenger". Se muestra tu nombre y tu foto de perfil: esos son los
   datos que obtenemos con pages_messaging y el motivo de esta solicitud.
   Podés usar el buscador de la lista para encontrarla por tu nombre.

5. Hacé clic en la conversación para abrir el hilo y ver tu mensaje.

6. Escribí una respuesta en el campo inferior y enviala.

7. Volvé a Messenger: la respuesta llegó a tu conversación con la página.

NOTA
----
Hoy tenemos Standard Access, así que la API de perfil solo responde por
cuentas con un rol en la app. Si tu cuenta no lo tiene, la conversación
aparecerá sin nombre ni foto: esa es exactamente la limitación que esta
solicitud busca levantar.
```

### `instructions-web-2` — inglés

```
ACCESS
------
Login URL: https://imprimir.sofopolis.com/admin/login
Credentials are provided in the test credentials field below.
This is our company's internal CRM. There is no public sign-up and no
Facebook Login; access is via the credentials we provide.

HOW TO TEST
-----------
1. Send a message to our Facebook Page (id 103485512431924) from your own
   account, e.g. "Hi, I'd like a quote for 500 business cards". The app
   only handles user-initiated conversations.
2. Log in at https://imprimir.sofopolis.com/admin/login
3. Open the messaging section: https://imprimir.sofopolis.com/admin/whatsapp
4. Your conversation appears in the left-hand list, labelled "Messenger",
   showing your name and profile picture. That is the data we obtain with
   pages_messaging and the reason for this request. You can use the search
   box to find it by your name.
5. Click the conversation to open the thread and see your message.
6. Type a reply in the composer at the bottom and send it.
7. Back in Messenger, the reply has arrived.

NOTE
----
We currently have Standard Access, so the User Profile API only resolves
for accounts with a role on the app. If your account has no role, the
thread will show no name or picture — which is precisely the limitation
this request aims to lift.
```

### ⚠️ La cuenta del revisor expone datos reales

El revisor entra a un CRM **en producción**. Con una cuenta normal ve la bandeja
completa: conversaciones de todos los clientes, con nombres, teléfonos y
contenido. Y las credenciales deben seguir activas **un año** después del envío.

Eso choca con lo declarado en la sección 6.

Mitigación: **crear un usuario de revisión con rol restringido**. El módulo de
mensajería es una clave ACL propia (`whatsapp`, ver
`packages/Webkul/Whatsapp/src/Config/acl.php`). Un rol que solo la habilite deja
al revisor sin acceso a Contactos, Oportunidades, Cotizaciones, Configuración ni
Usuarios.

Lo que **no** resuelve: la bandeja sigue mostrando los hilos de otros clientes.
`assigned_user_id` es solo un filtro de la vista (`ChatController.php:148-149`),
no un scope a nivel de datos. Endurecerlo requiere desarrollo, no configuración.

---

## ⚠️ Dos cosas a resolver ANTES de enviar

### El agente de IA

El CRM tiene un agente de IA que puede responder automáticamente. **Hoy está
apagado por defecto en Messenger** (`MESSENGER_AI_ENABLED=false`), y además está
fallando por otro motivo, así que la descripción de arriba — atención por asesor
humano — es exacta tal como está el sistema hoy.

Pero Meta exige **declarar las experiencias automatizadas** y que el usuario
sepa que habla con un bot. Dos caminos:

- **Enviar así, con el agente apagado en Messenger.** Es lo que recomiendo:
  la descripción es verdadera, el video coincide, y es la ruta más corta a la
  aprobación. Si después querés activar la IA en Messenger, se declara en su
  momento.
- **Declararlo ahora.** Hay que agregar a la descripción que parte de las
  respuestas son automatizadas, y el video tendría que mostrar el aviso al
  usuario. Alarga la revisión.

**Lo que no se puede hacer es enviar la descripción sin mencionarlo y después
activar la IA.** Eso es incumplimiento de lo declarado.

### Idioma

El formulario está en español porque tu cuenta lo está, pero la revisión de Meta
suele hacerse en inglés. Conviene enviar la descripción y las instrucciones en
inglés, o en ambos idiomas, para no arriesgar un rechazo por no entenderse.
