# App Review — Business Asset User Profile Access (Messenger)

> **App:** `917602557819401` ("Imprimir") — la que tiene suscrita la página de Messenger `155103561021423`.
> **No** es la app de WhatsApp (`2294798067962463`), aunque las dos se llamen "Imprimir".
> **Redactado:** 2026-10-08.

---

## 1. Descripción del uso

Pegar en *"Proporciona una descripción detallada del modo en que la app usa el permiso…"*.
Conviene enviarlo **en inglés**: los revisores de Meta trabajan en inglés y se rechaza menos.

```
Imprimir is a Bolivian manufacturer of packaging and printing products (stretch film, eco-friendly bags, caps). Our sales team uses an internal CRM to answer the customers who message our Facebook Page through Messenger.

How we use Business Asset User Profile Access:
When a customer sends a message to our Page, the Messenger webhook only gives us their Page-scoped ID (PSID), which is meaningless to a salesperson. The first time a customer writes, our CRM calls the Graph API once (GET /{PSID}?fields=first_name,last_name,profile_pic) to read the name and profile picture of that customer, who has just interacted with our business asset (our Page).

Where the data is shown:
- In the CRM inbox, the conversation list and the conversation header show the customer's name and profile picture instead of a numeric ID.
- The name is used to create the customer's contact record and sales opportunity in our CRM, so the assigned salesperson knows who they are talking to and can follow up on their quote or order.

Why it is necessary:
Our advisors handle many conversations at once across Messenger and WhatsApp. Without the name and picture they cannot tell customers apart, link a conversation to the right customer record, or address the customer by name. This directly improves response quality and speed for the people writing to us.

Data handling:
- We only read the profile of users who have messaged our own Page.
- The data is stored only in our private CRM, visible only to our authenticated staff.
- We never sell or share it with third parties and never use it for advertising or to build profiles outside this customer-service experience.
- The profile is fetched once per new contact, not repeatedly.
```

### Versión en español (solo referencia, no se envía)

Imprimir es un fabricante boliviano de empaques e impresión (stretch film, bolsas ecológicas, tapas).
Nuestro equipo de ventas usa un CRM interno para responder a los clientes que escriben a nuestra página
de Facebook por Messenger.

- El webhook de Messenger solo trae el ID de página del usuario (PSID), que no le sirve al vendedor. La
  primera vez que un cliente escribe, el CRM hace **una sola** llamada a la Graph API
  (`GET /{PSID}?fields=first_name,last_name,profile_pic`) para leer su nombre y foto de perfil.
- Esos datos se muestran en la bandeja del CRM (lista y encabezado de la conversación) y se usan para
  crear la ficha de contacto y la oportunidad de venta del cliente.
- Es necesario porque los asesores atienden muchas conversaciones a la vez entre Messenger y WhatsApp:
  sin nombre ni foto no pueden distinguir clientes, vincular la conversación a su ficha ni llamarlos por
  su nombre.
- Solo se lee el perfil de quienes escriben a nuestra página. Los datos quedan en nuestro CRM privado,
  visibles solo para el personal autenticado. Nunca se venden ni se comparten con terceros, ni se usan
  para publicidad.

---

## 2. Grabación de pantalla (guion, 1–2 minutos)

Grabar con **una cuenta de Facebook que no sea administradora** de la app ni de la página, para que se vea
que funciona con un cliente real.

| # | Pantalla | Qué mostrar | Subtítulo sugerido (inglés) |
|---|---|---|---|
| 1 | Celular u otro navegador | Abrir Messenger y escribir a la página **Imprimir**: "Hola, quiero cotizar stretch film" | *Customer writes to our Page on Messenger* |
| 2 | CRM | Entrar en `https://imprimir.sofopolis.com/admin` → **WhatsApp → Conversaciones** | *Our internal CRM inbox* |
| 3 | Lista de conversaciones | La conversación nueva aparece con **nombre y foto de perfil** y la etiqueta **Messenger**. **Hacer zoom acá** | *Name and profile picture shown instead of a numeric ID* |
| 4 | Conversación abierta | El nombre en el encabezado del chat | *The advisor knows who they are talking to* |
| 5 | Ficha | Abrir el **contacto / la oportunidad** creados con ese nombre | *Contact record created with the customer's name* |
| 6 | Respuesta | Contestar desde el CRM y mostrar en el celular que llegó | *The advisor replies from the CRM* |

> **Si con la cuenta de prueba el nombre y la foto NO aparecen**, es justamente lo que este permiso
> resuelve: sin acceso avanzado, Meta solo devuelve el perfil de quienes tienen rol en la app. En ese caso,
> grabar con una cuenta agregada como **Tester** en *Roles de la app* y aclararlo en las notas de la
> solicitud. (La prueba de Javier del 2026-10-08 mostró nombre y foto, probablemente por tener rol en la app.)

---

## 3. Casilla final

Marcar: *"Si se aprueba, confirmo que cualquier información que reciba a través de Business Asset User
Profile Access se usará de acuerdo con el uso permitido."* — el texto de la sección 1 ya cumple ese uso.

---

## 4. Checklist antes de enviar

- [ ] Solicitud hecha desde la app **917602557819401** (no la de WhatsApp).
- [ ] **URL de la política de privacidad** cargada en *Configuración → Básica* de esa app; la política
      menciona que se guardan el nombre y la foto de quienes escriben a la página.
- [ ] Negocio **verificado** en el Business Manager.
- [ ] Pedir en la misma revisión **`pages_messaging`** con acceso avanzado, si todavía no lo tiene: sin él
      tampoco se puede responder a clientes reales.
- [ ] Video subido (MP4), con subtítulos en inglés.

---

## 5. Instrucciones de acceso para el revisor (formulario general de App Review)

### instructions-web-2 — Cómo ingresar y probar

> Antes de enviar: crear en el CRM un usuario **solo para Meta** (Configuración → Usuarios), con acceso
> a la bandeja de WhatsApp/Messenger y **sin** permisos de administrador, y completar abajo su email y
> contraseña. Desactivarlo cuando termine la revisión.

```
Our app is an internal web CRM (no installation needed). It is used by our sales team to answer customers who message our Facebook Page "Imprimir" on Messenger.

1. Log in to the CRM
   URL: https://imprimir.sofopolis.com/admin
   Email: <REVIEWER_EMAIL>
   Password: <REVIEWER_PASSWORD>
   (The interface is in Spanish. The menu item for the inbox is "WhatsApp" > "Conversaciones"; it contains both WhatsApp and Messenger conversations.)

2. Send a test message as a customer
   From any Facebook account, open Messenger and send a message to our Page "Imprimir" (Page ID 155103561021423), for example: "Hello, I would like a quote".

3. See how the app uses Business Asset User Profile Access
   In the CRM, open "WhatsApp" > "Conversaciones". Within a few seconds the new conversation appears at the top of the list, labeled "Messenger", showing the customer's NAME and PROFILE PICTURE (read once with GET /{PSID}?fields=first_name,last_name,profile_pic). Open it: the name is shown in the conversation header, and a contact record and a sales opportunity are created with that name.

4. Reply
   Type a reply in the conversation and send it. It is delivered to the customer on Messenger (pages_messaging), within the 24-hour messaging window.

The screen recording attached to the permission request shows these same steps.
```

### fblogin-web-1 — Is Facebook Login integrated on this platform?

**No.** El CRM no usa Facebook Login: la página se conecta una sola vez con un Page Access Token cargado
por el administrador (`php artisan messenger:connect-page`). Los asesores entran con usuario y
contraseña propios del CRM.

### accesscode-web-1 — Códigos de acceso / credenciales de prueba (opcional)

```
No payment or membership is required. Use the test credentials provided in the access instructions:
URL: https://imprimir.sofopolis.com/admin
Email: <REVIEWER_EMAIL>
Password: <REVIEWER_PASSWORD>
These credentials will remain active for one year after submission.
```

> Si se completa, el usuario de prueba tiene que seguir activo **un año**.

### accesscode-web-2 — Códigos de regalo (opcional)

**Dejar vacío.** No es una app de tienda ni requiere pago para descargarla.

### geo-web-5 — Restricciones geográficas (opcional)

```
There are no geographic restrictions. The CRM can be accessed from any country. Our customers are mainly in Bolivia and the interface is in Spanish, but neither access nor any feature is geo-blocked.
```

### documents-web-1 — Documentación complementaria (opcional)

Subir:
- [ ] El **video** de la sección 2 (MP4).
- [ ] 2 o 3 **capturas**: la pantalla de login, la lista de conversaciones con nombre y foto de un
      cliente de Messenger, y la conversación abierta con el nombre en el encabezado.

---

## 6. pages_messaging — "Prueba y reproduce la funcionalidad de tu integración"

### Preparación (antes de enviar)

1. **Cuenta real de Facebook para el revisor.** Una cuenta de persona real (no un "usuario de prueba"
   de *Roles de la app*: esos no pueden recibir mensajes de bots). Puede ser una cuenta nueva creada
   solo para esto, con email propio.
   - Quitarle la **verificación en dos pasos** y entrar una vez desde una computadora, para que el
     login del revisor no quede trabado por un control de seguridad.
2. **Darle rol de Tester:** developers.facebook.com → app **917602557819401** → *Roles de la app* →
   *Roles* → *Agregar personas* → **Tester** → la cuenta acepta la invitación.
3. **Usuario del CRM para el revisor** (ver sección 5): sin permisos de administrador y **fuera de la
   matriz «Atiende»**, para que no reciba clientes reales. Activo un año.
4. Completar abajo los `<…>`.

### Texto para el campo (en inglés)

```
Our app is an internal web CRM that our sales team uses to answer the customers who message our Facebook Page "Imprimir" (Page ID 155103561021423) on Messenger. Customers write to the Page; the messages arrive in the CRM through the Messenger webhook (pages_manage_metadata) and our advisors reply from the CRM (pages_messaging, messaging_type RESPONSE, only within the 24-hour window).

TEST ACCOUNTS
1) Facebook account (real account with the Tester role in this app) — use it to write to our Page as a customer:
   Email: <FB_TESTER_EMAIL>
   Password: <FB_TESTER_PASSWORD>

2) CRM account (to see and answer the conversation as our advisor):
   URL: https://imprimir.sofopolis.com/admin
   Email: <REVIEWER_EMAIL>
   Password: <REVIEWER_PASSWORD>

STEPS TO REPRODUCE
1. Log in to Facebook / Messenger with account (1) and send a message to the Page "Imprimir", e.g. "Hello, I would like a quote".
2. Our assistant replies automatically within a few seconds on Messenger.
3. Log in to the CRM with account (2) and open "WhatsApp" > "Conversaciones" (the interface is in Spanish; this inbox holds both WhatsApp and Messenger conversations).
4. The new conversation appears at the top, labeled "Messenger", with the customer's name and profile picture (Business Asset User Profile Access). Open it to see the full thread.
5. Type a reply in the message box at the bottom and press send. The reply is delivered to account (1) on Messenger.

Notes:
- Replies are only sent in response to a message from the customer, within the 24-hour window. We do not send promotional or unsolicited messages.
- The test accounts will remain active for at least one year.
```

---

## Referencias técnicas (para quien revise el CRM)

| Qué | Dónde |
|---|---|
| Lectura del perfil (`first_name,last_name,profile_pic`) | `packages/Webkul/Messenger/src/Services/MessengerProfile.php` |
| Copia local de la foto (las URL de Meta vencen) | `packages/Webkul/Messenger/src/Services/MediaLibrary.php` |
| Recuperar perfiles de contactos que entraron sin nombre | `php artisan messenger:backfill-profiles --dry-run` |
| Secreto con el que se verifican los webhooks | `.env` → `MESSENGER_APP_SECRET` (debe ser el de la app 917602557819401) |
