# Spec: Botones en Messenger (quick replies)

## Objetivo
El agente ruteado a Messenger hoy recibe **501** al pedir `POST /conversations/{id}/interactive`
y tiene que caer a texto numerado. Meta sí ofrece botones en Messenger -*quick replies*
(hasta 13) y *button template* (hasta 3)-; la carencia estaba en nuestro `MessengerGateway`.

Éxito: el mismo payload que el agente manda a WhatsApp sale en Messenger como quick replies,
y la elección del cliente vuelve al agente en `message.selection.id`, igual que en WhatsApp.
El agente no cambia nada.

## Decisiones
- **Quick replies, no button template**: cubren los 3 botones y las 10 filas de lista del DTO;
  la respuesta vuelve con `quick_reply.payload` (= id de la opción); desaparecen al elegir.
- Header y footer se pegan al cuerpo (Messenger no los tiene como campos). `list_button` se omite.
- Títulos se recortan a 20 caracteres (Messenger) porque una fila de lista WhatsApp admite 24.
- Secciones se aplanan; los títulos de sección se pierden.
- La elección entrante se guarda con la forma de la Cloud API (`interactive.button_reply{id,title}`)
  para que `AgentPayload::selection()` y el hilo del inbox no necesiten saber el canal.
- Postbacks con título (button template) también se traducen; los de "Comenzar"/menú persistente no.

## Comandos
```
Test (dentro del contenedor): php artisan test tests/Feature/MessengerChannelTest.php
Lint: ./vendor/bin/pint packages/Webkul/Messenger
```
Nunca correr la suite completa contra producción.

## Estructura
```
packages/Webkul/Messenger/src/Gateways/MessengerGateway.php  → sendInteractive, quickReplies, textoDe, seleccionDe
packages/Webkul/Whatsapp/src/Gateways/Dto/OutboundInteractive.php → sin cambios (DTO común)
tests/Feature/MessengerChannelTest.php                       → bloque "Botones"
docs/agent/respuesta-bugs-14-09.md                           → corregir §4 (decía que Meta no tenía equivalente)
```

## Estilo
Comentarios en español explicando el *por qué*, como el resto del módulo; nombres en español
para lo nuevo del dominio (`seleccionDe`), inglés donde el archivo ya lo usa.

## Pruebas
Pest, `DatabaseTransactions`, `Http::fake` contra `graph.facebook.com/*`. Casos:
botones → quick replies con header/footer pegados; lista → aplanada y recortada, sin `list_button`;
capabilities anuncia `interactive`; webhook con `quick_reply.payload` → `type=interactive` y
`raw.interactive.button_reply = {id,title}`.

## Límites
- Siempre: validar con `OutboundInteractive::problema()` antes de llamar a Meta; tests del módulo tocado.
- Preguntar: cambiar el contrato `Gateway` o el DTO; tocar el prompt del agente.
- Nunca: suite completa en producción; tocar n8n.

## Criterios de éxito
1. `POST /api/v1/whatsapp/conversations/{id}/interactive` sobre una conversación `gateway=messenger` responde 200 y encola.
2. En Messenger aparecen las burbujas; al tocar una, el agente recibe `selection.id` con el id original.
3. Los 4 tests nuevos pasan; los 19 existentes siguen pasando.

## Abierto
- 11–13 opciones caben en Messenger pero no en WhatsApp: el DTO topa en 10. Se mantiene el tope común.
