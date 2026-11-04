# TODO - CRM (auditoría del agente del 12/10/2026)

> Datos: 303 conversaciones (02/10 al 12/10/2026). Las tareas del agente están en `auditoria-2026-10-12-todo-agente.md`.
> Orden sugerido: **CRM-6** → CRM-1 → CRM-2 → CRM-3 → CRM-4. CRM-6 desbloquea P1 del agente; CRM-1 y CRM-2 desbloquean P3 y P4.
>
> **Destinatarios de todos los avisos de este documento (CRM-3 y CRM-4): solo dos correos, `jalejandromogro1@gmail.com` y `dcuellar@sofopolis.com`.** Van en `.env` (`WHATSAPP_ALERT_EMAILS`) y no se calculan a partir de roles ni de usuarios.
>
> **Decisión del 12/10:** el agente no menciona precios. El precio lo da la cotización que envía el asesor.

---

## CRM-6 - Crear la cotización aunque no haya precio cargado

**Problema.** El agente llamó a `crear_cotizacion` 21 veces desde el 08/10 y **10 fueron rechazadas (422)**. Nueve fueron de **Stretch Film Manual**, que no tiene precio cargado (`filaParaCotizar` → "Esa combinación no tiene precio cargado"), en las conversaciones 5012, 5040, 5099, 5103, 5104 y 5133, entre otras. El cliente dio todos sus datos y no se creó nada.

Además, en 33 conversaciones el agente prometió la cotización y no existe ninguna (ver P1 del agente).

**Qué hacer.**
- [x] `AgenteController::cotizacion`: si la combinación existe pero no tiene precio (`precio` null o `cotiza()` false), **crear la cotización igual**, con precio 0 y una nota visible "Precio pendiente: completar antes de enviar" que incluya la `nota` de la fila. Responder 201 con `precio_pendiente: true`.
- [x] Seguir devolviendo 422 **solo** cuando faltan datos (`faltan`) o el producto no existe.
- [ ] En el listado de cotizaciones, una marca "Precio pendiente" para que el asesor las vea primero, y que "Enviar cotización" no deje enviar una con precio pendiente.
- [ ] El correo de cotización nueva ya existente sigue saliendo y dice "precio pendiente".
- [x] Tests: Stretch Film sin precio → 201 con precio pendiente; faltan datos → 422 como hoy; precio cargado → como hoy.
- [x] Al crear la cotización del agente, el pedido pasa de NO ATENIDOS a SOLICITUD RECIBIDA de su pipeline (no retrocede si ya está más adelante).
- [x] Recuperación: listar las 33 conversaciones con cotización prometida y no creada, para que los asesores las contacten.

---

## CRM-1 - Recuperar la opción elegida en menús que llegan por Kommo

**Problema.** Por Kommo, la elección de un menú interactivo llega como texto común: 357 de 1.256 mensajes del cliente eran el título exacto de una opción. `AgentPayload::selection()` lee `payload.interactive` y devuelve `null`, así que el agente no sabe qué se eligió y repite el menú (54 repeticiones en 46 conversaciones; la conversación 5107 recibió 4 veces la misma presentación).

**Qué hacer.**
- [x] Al armar el evento para un mensaje entrante de **texto**, buscar el último mensaje `interactive` saliente de la conversación. Si el texto coincide con el título de una de sus opciones (filas o botones; sin distinguir mayúsculas y comparando sin espacios en los extremos), devolver `selection = {id, title}`.
- [x] Solo contra **el último** menú enviado, para no confundir títulos que se repiten entre menús.
- [x] Tests: título exacto → selection con su id; texto libre → null; título de un menú anterior → null; payload `interactive` nativo (Cloud API) → sigue funcionando.

**Archivos.** `packages/Webkul/Whatsapp/src/Services/AgentPayload.php`

---

## CRM-2 - Agrupar ráfagas antes de avisar al agente

**Problema.** `IngestInboundPayload` despacha un `NotifyAgent` por cada mensaje. Hubo 156 ráfagas (2 o más mensajes en menos de 60 s) y el agente respondió cada mensaje por separado (conversación 3686: contestó el saludo con la presentación e ignoró la pregunta que llegó 2 s después).

**Qué hacer.**
- [x] Despachar `NotifyAgent` con un retardo configurable (`WHATSAPP_AGENT_DEBOUNCE_SECONDS`, por defecto 8).
- [x] Al ejecutarse, si el mensaje ya no es el último entrante de la conversación, terminar sin llamar al agente: el job del mensaje más nuevo lo va a cubrir.
- [x] El job que sí llama manda en `message.parts` todos los entrantes consecutivos desde la última respuesta saliente, y en `message.text` sus textos unidos con salto de línea.
- [x] Con 0 segundos se mantiene el comportamiento actual.
- [x] Tests: 3 mensajes en ráfaga → un solo POST al agente con 3 `parts`; un mensaje aislado → igual que hoy.
- [ ] Recordar que hay que reiniciar el worker (`supervisorctl restart whatsapp`) al desplegar.

**Archivos.** `Jobs/IngestInboundPayload.php`, `Jobs/NotifyAgent.php`, `Services/AgentPayload.php`, `Config/whatsapp.php`

---

## CRM-3 - Avisar cuando el agente falla

**Problema.** El 07/10 de 21:08 al 08/10 a las 08:48 el agente contestó 23 veces "ocurrió un error" y nadie se enteró durante unas 12 horas. Los fallos de `NotifyAgent::failed()` solo quedan en el log.

**Qué hacer.**
- [x] Al recibir del agente un mensaje con el texto de error, o cuando `NotifyAgent` agote sus reintentos, contar el evento.
- [x] Si hay N eventos en M minutos (por defecto 3 en 15), mandar **un** correo a `WHATSAPP_ALERT_EMAILS` (los dos de arriba) y no repetirlo durante 1 hora.
- [ ] Opcional: un chip "Agente con errores" en la bandeja mientras dure.

---

## CRM-4 - Derivaciones que nadie atiende

**Problema.** De 52 derivaciones, 28 no tienen ninguna respuesta humana registrada en el CRM. Las demás tardaron unas 18 horas de mediana. Hay 18 conversaciones que terminan con el cliente esperando, varias con pedidos concretos (5016 "Cuánto me sale 50 unidades?", 5060 "Me cotiza el manual", 4433 "100uni", 5019 "Y dirección", 4260 con su NIT).

> **Confirmar primero con los asesores** si contestan desde la app de Kommo. Si es así, esas respuestas no entran al CRM, y entonces el arreglo es ingerirlas antes de medir o alertar.

**Qué hacer.**
- [x] Si una conversación derivada pasa 30 minutos (en horario: lun–sáb 08:00–19:00) sin respuesta humana, correo al **asesor asignado** con copia a `jalejandromogro1@gmail.com` (decisión del 12/10). Fuera de horario se avisa a la apertura + 30 min.
- [ ] Filtro "Derivadas sin respuesta" en la bandeja.
- [ ] En el tablero, tiempo de respuesta separado: **agente** (entrante → 1ª respuesta de la IA) y **asesor** (derivación → 1ª respuesta humana).

---

## CRM-5 - Estados de entrega

**Problema.** Solo 13 de las 1.251 respuestas del agente figuran como `delivered`. En el modo mixto (Kommo recibe y Cloud API envía) no llegan los estados de entrega de Meta, así que un envío fallido no se ve.

- [x] Entrada `/api/v1/webhooks/cloud-api` (solo estados en modo mixto) que actualiza `status` por `wa_message_id`.
- [ ] Configurar esa URL en la app de Meta del número que envía y suscribir la app a la WABA.

---

## Datos (Comercial, no código)

- [ ] **Stretch Film**: cargar el precio de las 2 presentaciones; hoy no tiene ninguno. Es el anuncio más común (102 conversaciones). Con CRM-6 la cotización se crea igual, pero el asesor tiene que completar el precio a mano.
- [ ] **Bolsas Magia Verde**: completar las 20 de 50 combinaciones Tamaño × Canal × Zona que no tienen precio.
- [ ] Definir a qué tamaño corresponde "XXL" (lo dice el anuncio y no existe en el catálogo).
