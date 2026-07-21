# Alcance — Enviar plantillas cuando la ventana de 24h está cerrada

**Módulo:** `packages/Webkul/Whatsapp`
**Estado:** propuesta, sin implementar
**Fecha:** 2026-07-21

---

## 1. Problema

WhatsApp solo permite texto libre mientras la **ventana de 24 horas** siga abierta, es decir
mientras no hayan pasado 24h desde el último mensaje del cliente. Fuera de esa ventana, lo
único que se puede enviar es una **plantilla aprobada por Meta (HSM)**.

Hoy el inbox detecta la ventana cerrada y bloquea el composer, mostrando este cartel
(`inbox-component.blade.php:261`):

> Ventana de 24h cerrada. WhatsApp no permite texto libre hasta que el cliente vuelva a
> escribir (o usar una plantilla aprobada).

El cartel **menciona la salida pero no la ofrece**: el agente queda sin ninguna acción posible
y la conversación se corta. Ese es el hueco que cierra este trabajo.

---

## 2. Objetivo

Que, con la ventana cerrada, el agente pueda **elegir una plantilla aprobada, completar sus
variables y enviarla** desde el mismo inbox, reabriendo la conversación.

---

## 3. Qué ya existe (verificado)

No hay que construir desde cero. Ya está en su lugar:

| Pieza | Dónde | Estado |
|---|---|---|
| Cálculo de la ventana | `Gateways/Dto/MessagingWindow.php` | ✅ `applies` / `open` / `expires_at` / `seconds_left` |
| La ventana llega al front | `InboxController::thread()` | ✅ se refresca en cada poll |
| El front reacciona a la ventana | `inbox-component.blade.php` (badge, cartel, aviso lateral) | ✅ ya bloquea el composer |
| Envío de plantillas | `Gateway::sendTemplate()` + `CloudApiGateway` | ⚠️ funciona, pero solo con plantillas **preconfiguradas** |
| Mensaje `type=template` en la bandeja | `InboxController::transform()` + front | ✅ se renderiza con la etiqueta "Plantilla" |
| Permiso de la API | token con scope `whatsapp_business_management` | ✅ ya puede leer el catálogo |

**Lo que falta es leer el catálogo de Meta y poder enviar una plantilla arbitraria.**

---

## 4. Alcance

### Dentro

- Leer del catálogo de Meta las plantillas **aprobadas**.
- Selector de plantilla en el inbox, visible **cuando la ventana está cerrada**.
- Vista previa del texto y un campo por cada variable (`{{1}}`, `{{2}}`, …).
- Envío, registro del mensaje en la conversación y render en la bandeja.
- Validación de la cantidad de variables **antes** de llamar a Meta.

### Fuera (por ahora)

- Plantillas con **header de imagen/video/documento** o con **botones dinámicos**: solo se
  soportan las de **cuerpo de texto con variables**. Las que tengan header de texto o botones
  fijos (como los `Si`/`No` del recordatorio) funcionan igual, porque esos componentes no
  requieren parámetros.
- **Crear, editar o borrar** plantillas desde el CRM. Se administran en WhatsApp Manager.
- **Kommo**: cada plantilla suya es un salesbot distinto, no puede enviar una arbitraria.
  Esta funcionalidad es exclusiva del gateway Cloud API.

### Decisiones tomadas

- **Quién puede enviarlas:** cualquier agente del inbox, sin permiso especial.
- **Sin persistencia en base de datos:** el catálogo se cachea unos minutos. Meta es la fuente
  de verdad; guardar una copia solo agrega el problema de mantenerla sincronizada.

---

## 5. Diseño

### 5.1 Catálogo

`GET https://graph.facebook.com/{version}/{WABA_ID}/message_templates`

Devuelve, por plantilla: `name`, `language`, `status`, `category`, `id` y `components`
(`HEADER` / `BODY` / `FOOTER` / `BUTTONS`).

Un servicio nuevo se encarga de:

1. Consultar y **filtrar por `status = APPROVED`** — las pendientes o rechazadas no se pueden
   enviar y no deben aparecer.
2. Extraer del componente `BODY` el texto y **contar las variables** parseando los `{{n}}`.
   Meta también guarda ejemplos en `example.body_text`, que sirven de placeholder en el
   formulario.
3. **Cachear** el resultado. El catálogo cambia muy poco y no tiene sentido pegarle a Meta en
   cada apertura del inbox.

Se agrega un comando de consola para listar y refrescar el catálogo, de modo de poder
diagnosticar sin depender de la UI.

### 5.2 Envío de una plantilla arbitraria

Hoy `sendTemplate($conversation, $templateKey, $variables)` resuelve el key contra una
**config estática** — así funciona el recordatorio de citas, que está en producción.

Se extiende para aceptar además una plantilla **ad-hoc** (nombre + idioma reales), **sin tocar
el camino por configuración**, que debe seguir funcionando igual.

Antes de enviar se valida que la cantidad de variables recibidas coincida con la que pide la
plantilla: si no coinciden, **Meta rechaza el mensaje entero**, y es mejor frenarlo con un
error claro que dejar un mensaje fallido sin explicación.

### 5.3 Front

Cuando `window.applies && !window.open`, el cartel rojo actual suma un **botón "Enviar
plantilla"** que abre un selector con:

- La lista de plantillas aprobadas (nombre + vista previa del cuerpo).
- Un campo por variable, precargado con el ejemplo de Meta.
- Vista previa del texto final con las variables reemplazadas.
- Botón de envío, deshabilitado hasta que estén todas completas.

El mensaje enviado se agrega a la conversación como `type=template`, que la bandeja **ya sabe
renderizar**.

### 5.4 Reglas que se mantienen

- **Guard del agente**: si la IA está atendiendo, el humano no puede enviar (igual que hoy
  para texto y archivos).
- El selector solo aparece si el gateway saliente declara la capacidad `template`.

---

## 6. Fases

| # | Entregable | Verificable por |
|---|---|---|
| 1 | Servicio de catálogo + cache + comando de consola | El comando lista las plantillas aprobadas reales |
| 2 | Envío de plantilla ad-hoc + validación de variables | Envío de prueba con `wamid` real de Meta |
| 3 | Endpoint que expone el catálogo al inbox | Respuesta JSON con plantillas y sus variables |
| 4 | Selector en el front sobre el cartel de ventana cerrada | Envío end-to-end desde la UI |

---

## 7. Dependencia bloqueante

**Falta el WABA-ID de la cuenta que contiene el número desde el que enviamos**
(`phone_number_id` = `117998067938203`, +591 62000223).

Se verificó que **no se puede autodescubrir**: se probó el campo `whatsapp_business_account`
del número, `/me`, `debug_token` (sus `granular_scopes` vienen sin `target_ids`) y los edges
de cuentas del negocio. Ninguno lo expone con este token.

El WABA-ID entregado inicialmente (`1693110855026610`) **corresponde a otra cuenta**: contiene
el número +591 64402908 y una sola plantilla (`hello_world`). No sirve para este número.

Se obtiene en WhatsApp Manager, entrando al número y viendo a qué cuenta pertenece. Va como
`WHATSAPP_WABA_ID` en el `.env`.

> Sin ese dato se puede construir todo salvo la lectura del catálogo (Fase 1), que es
> justamente lo que alimenta a las demás.

---

## 8. Criterios de aceptación

1. Con la ventana **abierta**, el inbox se comporta **exactamente como hoy** (sin selector).
2. Con la ventana **cerrada**, aparece la opción de enviar plantilla.
3. Solo se listan plantillas **aprobadas** de la WABA del número configurado.
4. Enviar sin completar todas las variables **no llega a llamar a Meta**.
5. El mensaje enviado queda registrado en la conversación y se ve en la bandeja.
6. El **recordatorio de citas programado sigue funcionando igual** (no se toca su camino).
7. Con la IA activa, el agente humano **no puede** enviar plantillas.

---

## 9. Riesgo conocido

Las plantillas de categoría **MARKETING tienen costo por mensaje** y son las que más molestan
al cliente. Se decidió no restringir por permisos, así que conviene **mostrar la categoría**
de cada plantilla en el selector para que el agente sepa qué está mandando.

---

## 10. Nota abierta (fuera de este alcance)

El `phone_number_id` configurado cambió durante el desarrollo: las pruebas iniciales salieron
desde **+591 76894324** y hoy el sistema envía desde **+591 62000223**. Conviene confirmar
cuál es el número que los pacientes reconocen antes de seguir, porque las plantillas viven en
la WABA del número y un cambio los deja apuntando a otro catálogo.
