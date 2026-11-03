# Alta del número +591 78243653 en WhatsApp Cloud API

Fecha: 2026-10-23
Cuenta: Vinos Kohlberg · WhatsApp Cloud API

Reemplaza al +591 62875485, que quedó inutilizable por dos motivos: su WABA tiene
el pago restringido (error 131042, ver `meta-error-131042.md`) y además se perdió la
línea con la operadora.

---

## Identificadores

| | Vieja | Nueva |
|---|---|---|
| Business Manager | `939523667552918` | `939523667552918` (el mismo) |
| WABA | `1714811009708730`, moneda **AED** | `1090988900211297`, moneda **USD** |
| Número | `1162182773639133` (+591 62875485) | `1380272741842617` (+591 78243653) |
| App | Kohlberg Chatbot `1266203425616995` | la misma |
| Usuario de sistema | Kohlberg-produccion `122113038471490183` | el mismo |

> El número nuevo tiene que estar en una WABA **nueva**. Si se agrega a la vieja,
> hereda el bloqueo de pago, porque la restricción es de la WABA y no del número.

---

## Por qué el número "no aparecía disponible"

Hubo dos problemas, uno detrás del otro.

1. **El usuario de sistema no tenía asignada la WABA nueva.** El token no tiene el
   permiso `business_management`, así que no puede listar las WABA del portafolio:
   `me/assigned_whatsapp_business_accounts` devuelve `[]`, y
   `{business}/owned_whatsapp_business_accounts` responde `#200`. Una vez asignado
   el activo, el número se pudo consultar con su ID.
2. **El número estaba verificado pero no registrado.** Validar el código (OTP) solo
   demuestra que el número es tuyo. Para usarlo en la Cloud API hay que hacer además
   el `POST /register`. Así estaba antes del alta:

   ```
   status:                    PENDING
   platform_type:             NOT_APPLICABLE
   code_verification_status:  VERIFIED
   name_status:               PENDING_REVIEW   ("Kohlberg")
   is_pin_enabled:            false
   health_status:             BLOCKED · 141000 "The phone number you are trying to
                              send messages from is not linked to your WhatsApp account."
   subscribed_apps (WABA):    []
   ```

---

## Pasos del alta

Todas las llamadas usan `https://graph.facebook.com/v21.0` y llevan
`Authorization: Bearer $WHATSAPP_TOKEN`.

### 0. En Business Manager (a mano)

- Crear la WABA nueva en USD con método de pago, y agregar el número validando el
  código por SMS o llamada.
- Configuración del negocio → Usuarios del sistema → **Kohlberg-produccion** →
  Asignar activos → la WABA nueva con **control total**.

### 1. Registrar el número en la Cloud API

```
POST /1380272741842617/register
{"messaging_product": "whatsapp", "pin": "<PIN de 6 dígitos>"}
```

El PIN queda como **verificación en dos pasos** del número y Meta lo vuelve a pedir
en cada re-registro. **No se guarda en el repo**: va al gestor de contraseñas.

### 2. Suscribir la app a la WABA, para que lleguen los webhooks

```
POST /1090988900211297/subscribed_apps
GET  /1090988900211297/subscribed_apps   → debe listar "Kohlberg Chatbot"
```

### 3. Perfil del negocio

```
POST /1380272741842617/whatsapp_business_profile
{"messaging_product":"whatsapp","description":"…","websites":["https://kohlberg.com.bo/"],"vertical":"ALCOHOL"}
```

La foto de perfil se sube a mano desde WhatsApp Manager.

### 4. Plantillas

Se recrean en la WABA nueva, copiadas de la vieja: `consulta_pendiente` y
`retomar_conversacion`. `hello_world` viene incluida. Cada una vuelve a pasar por la
revisión de Meta.

```
POST /1090988900211297/message_templates
```

Los pasos 1 a 4 los hace el script `migrar_numero.py`, que en esta sesión estaba en
el scratchpad:

```
python3 -I migrar_numero.py --waba 1090988900211297                       # solo consulta
python3 -I migrar_numero.py --waba 1090988900211297 --phone 1380272741842617 \
        --pin <PIN> --aplicar registrar,suscribir,perfil,plantillas
```

### 5. Comprobación

```
GET /1380272741842617?fields=status,platform_type,name_status,health_status
```

El número queda listo cuando `status=CONNECTED`, `platform_type=CLOUD_API` y
`health_status.can_send_message=AVAILABLE`. Mientras `name_status` siga en
`PENDING_REVIEW`, Meta limita los envíos.

### 6. Pasar el CRM al número nuevo

En `.env`:

```
WHATSAPP_PHONE_NUMBER_ID=1380272741842617
WHATSAPP_WABA_ID=1090988900211297
```

Después: `php artisan config:clear` (en el contenedor, con `-u www-data`) y enviar un
mensaje de prueba.

---

## Estado

| Paso | Estado |
|---|---|
| 0. WABA nueva y asignación al usuario de sistema | ✅ hecho (23/10) |
| 1. Registro | ❌ falló (23/10), ver abajo |
| 2. Suscripción de la app | ✅ hecho (23/10): "Kohlberg Chatbot" suscrita |
| 3. Perfil | ✅ hecho (23/10). Falta la foto, que va a mano |
| 4. Plantillas | ✅ creadas (23/10), en revisión de Meta: `consulta_pendiente` (`1524490196106902`, UTILITY) y `retomar_conversacion` (`1442130518011487`, MARKETING) |
| 5. Comprobación | ⏳ |
| 6. `.env` | ⏳ |

### Fallo del registro (23/10)

```
(#10) Application does not have permission for this action
details: Request failing authentication and authorization for account
         59178243653@s.whatsapp.net: request denied from WWW with message:
         Error validating Graph API Permission Checks ... UserError
```

- **Qué se descartó.** El activo está bien asignado: en las dos WABA,
  `assigned_users` muestra a Kohlberg-produccion con `MANAGE`. Además, con el mismo
  token funcionó todo lo de gestión sobre la WABA nueva (suscripción, perfil y
  plantillas).
- **Causa probable.** El token `WHATSAPP_TOKEN` se generó el **30/09/2026**, antes de
  que existiera la WABA nueva. Los permisos de mensajería (`whatsapp_business_messaging`)
  de un token de usuario de sistema no cubren activos que se asignaron después de
  generarlo, y `/register` pasa por ese control.
- **Cómo se arregla.** Configuración del negocio → Usuarios del sistema →
  Kohlberg-produccion → **Generar token nuevo**: app "Kohlberg Chatbot", sin
  vencimiento, permisos `whatsapp_business_management` y `whatsapp_business_messaging`.
  `business_management` no hace falta. La app no lo tiene habilitado, y sin él solo se
  pierde la posibilidad de listar las WABA del portafolio. Después se repite solo el paso `registrar` con el
  mismo PIN y se cambia `WHATSAPP_TOKEN` en el `.env` junto con los otros dos valores.
  El token nuevo también sirve para la WABA vieja: generarlo no invalida el anterior.

**Actualización (23/10, 20:40 UTC).** Se generó el token nuevo (emitido a las 20:36
UTC) y `/register` dio **el mismo error**, así que **el token no era la causa**.
Sí quedó comprobado que el token nuevo funciona con el número viejo.

Comparación con la WABA y el número viejos, que sí están registrados:

| | Vieja / 62875485 | Nueva / 78243653 |
|---|---|---|
| WABA `status` | ACTIVE | ACTIVE |
| `ownership_type` | CLIENT_OWNED | CLIENT_OWNED |
| Usuario de sistema | MANAGE | MANAGE |
| App suscrita | sí | sí |
| `is_on_biz_app` (coexistencia con la app) | — | false |
| **`name_status`** | **APPROVED** | **PENDING_REVIEW** |

La única diferencia que se ve es que el **nombre visible todavía no está aprobado**.
Es la causa más probable que queda, aunque no está confirmada. Pasos siguientes:

1. Esperar a que Meta apruebe "Kohlberg" (normalmente de 24 a 48 h) y repetir
   `/register` con el mismo PIN.
2. Mientras tanto, revisar en WhatsApp Manager → Números de teléfono si el número
   ofrece un botón "Conectar" o "Terminar configuración", y si la WABA nueva tiene
   método de pago cargado.
3. Si con el nombre aprobado sigue fallando, abrir un ticket en Meta Business Support
   con el `fbtrace_id` del error (`A28ZYpANQ_lGlKpIKJKaI3v`).

**Actualización (26/10).** Meta aprobó el nombre (`name_status=APPROVED`) y
`/register` volvió a dar el **mismo `#10`** (fbtrace `ATRmlydVyq3MEfnKnCBZx-H`). **El
nombre tampoco era la causa.** Además, `request_code` responde 136024 ("número ya
verificado"), así que la verificación por código está vigente.

Lo que todavía no se pudo revisar desde la API, y queda como próximos candidatos:

- **El rol del usuario de sistema.** Tiene que ser *Administrador*, no *Empleado*. El
  número viejo pudo haberse registrado con otra credencial, así que este token nunca
  hizo un `/register` que haya funcionado.
- **La app asignada al usuario de sistema.** Tiene que tener control total en
  Configuración del negocio → Usuarios del sistema → Kohlberg-produccion → Activos →
  Apps.
- **Registrarlo por la interfaz de Meta.** En developers.facebook.com → app Kohlberg
  Chatbot → WhatsApp → Configuración de la API, elegir el número y completar el alta,
  o hacerlo desde WhatsApp Manager. Ese camino usa permisos internos de Meta.
- Si no funciona nada de esto, abrir un ticket con los fbtrace de arriba.

Límite de Meta: 10 intentos de `/register` por número cada 72 h. No reintentar por API
hasta cambiar algo de la lista anterior.
