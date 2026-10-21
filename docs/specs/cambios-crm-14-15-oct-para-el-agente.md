# Cambios del CRM del 14 y 15/10 que el agente tiene que conocer

> Para el equipo del agente.
> Auditoría de todo lo que se tocó en el CRM esos dos días y que afecta, o puede afectar, al agente.
> Ordenado por impacto. Los puntos 1 a 3 cambian el comportamiento de endpoints que el agente usa y
> **se hicieron sin acordarlo antes**: están abiertos a revisión.

---

## 1. La derivación ahora va al DUEÑO DEL LEAD, no al titular del inmueble ⚠️

`POST /api/v1/inmobiliaria/conversations/{id}/handoff`

| | Antes | Ahora |
|---|---|---|
| A quién se deriva | Al asesor titular del inmueble del `codigo` | Al **dueño del lead** de la conversación |
| Sin lead | Asesor de guardia (id 4, Pedro) | Asesor por defecto (**id 6, Fernando**) |
| `codigo` | Elegía al asesor | Queda solo como referencia en la derivación |

Por qué: el CRM ahora limita a cada asesor a ver solo las conversaciones de sus leads, y solo el
asesor asignado puede tomar la derivación. Derivarle a un titular un lead ajeno le dejaba algo que no
podía ver ni nadie más podía tomar.

**Impacto en el agente:** como **todos los leads nuevos quedan a nombre de Fernando** (punto 2), hoy
casi todas las derivaciones van a Fernando, aunque el cliente pregunte por un inmueble de Eliana o
Pedro. Si el prompt dice «le paso con el asesor del inmueble» o nombra al titular, **el nombre que
promete no coincide con quien recibe la derivación**. El nombre correcto viene en la respuesta
(`handoff.asesor`); conviene usar ese y no anticiparlo.

## 2. Todo lead nuevo queda a nombre de Fernando (id 6)

Los leads que crea el agente (`solicitud`, `captacion`, `postventa`) se asignan siempre a Fernando,
salvo que alguien elija otro asesor desde el CRM. Antes quedaban a nombre del primer usuario activo.
Solo Administradores y Supervisores pueden reasignar.

## 3. `solicitud` / `captacion` / `postventa` actualizan en vez de duplicar (provisorio)

Detalle completo y preguntas en `lead-unico-por-cliente-para-el-agente.md`. En resumen: si el
contacto ya tiene un lead abierto del mismo tipo, se actualiza ese y la respuesta trae
`"actualizado": true`. Lo necesitamos acordar.

## 4. La base se restauró: hay ids de conversación y de contacto REUTILIZADOS ⚠️

El 14/10 a las 19:04 UTC alguien importó un dump viejo sobre la base. El CRM recuperó los datos
perdidos desde el registro de MySQL, pero en el medio entraron conversaciones nuevas que tomaron ids
que ya existían. Si el agente guarda estado o memoria por `conversation_id` o `person_id`:

| id | Antes del 14/10 19:04 UTC | Hoy |
|---|---|---|
| conversación **#261** | Karina (+59177350602) | **Otra persona** (+59165735052, «🎻🔥») |
| conversación **#256** | Mir Angela (+59170566748) | **No existe**: se fusionó en la **#246** |
| conversación de Karina | #261 | **#245** |
| contacto **#772** | Karina | **Otra persona** («🎻🔥») |
| contacto **#767** | Mir Angela | No existe: fusionado en el **#757** |

**Pedido:** si el agente tiene memoria por id, **descartar lo guardado de las conversaciones #245,
#246, #256 y #261** y reconstruirla desde el historial que manda el CRM. Además se perdieron y no
se restauraron (los habían borrado a mano antes): Alejandro (59176616013, lead #48), Reneé E.p
(59168844787) y Sofopolis (59170088388).

## 5. Nuevo tipo de mensaje en el historial: `template`

El CRM ya puede mandar **plantillas aprobadas por Meta**, lo único que se puede enviar con la ventana
de 24 h cerrada. Hay una aprobada:

```
seguimiento_solicitud  (UTILITY, es)
Hola {{1}}, le escribimos de Ponce de León para dar seguimiento a la consulta que nos realizó.
¿Desea que continuemos con su solicitud?
[ Sí, continuar ]  [ Ya no me interesa ]
```

- En `history` aparece con `type: "template"`, `role: "assistant"` y el texto ya completado en
  `content`. Es un valor de `type` que el agente no veía antes.
- Si el cliente toca un botón, llega como mensaje entrante `type: "button"` con el texto del botón
  («Sí, continuar» / «Ya no me interesa»), y **abre la ventana de 24 h**: el agente va a recibir el
  evento y va a contestar.
- **Pedido:** que el prompt sepa qué significa cada botón. «Sí, continuar» = retomar la búsqueda donde
  quedó. «Ya no me interesa» = cerrar con cortesía y no insistir.
- El 14/10 se mandó a mano a dos clientes: K (59176783881, conv. #249) y Moni R. (59170146583,
  conv. #248).
- Todavía no hay envío automático; cuando se defina (recontacto por «visto»), lo coordinamos.

## 6. Los mensajes del número de la pauta NO llegan ni al CRM ni al agente ⚠️

Desde el 8/10 el webhook rechaza (401) los mensajes de un número que no es el del CRM
(phone_number_id `1162182773639133`, WABA `1714811009708730`), que pertenece a otra app de Meta con
otro secreto. La pauta publicitaria parece estar conectada a ese número: los clientes que escriben
desde el anuncio **no generan conversación** y el agente nunca los ve. El CRM atiende solo
+591 78435368. Hay que decidir si la pauta pasa a ese número o si el CRM cambia de número.

## 7. Lo que NO afecta al agente (para que no se preocupen)

- Las restricciones de visibilidad por asesor (tablero, inbox) son solo para usuarios del CRM. Los
  endpoints de la API del agente no pasan por ese filtro.
- Mejoras de la interfaz mobile de leads, renombre de «Prospectos» a «Contactos», tablero por
  asesor y exclusión de administradores y supervisores de los gráficos.

---

## Resumen de pedidos al agente

1. Usar el nombre de `handoff.asesor` de la respuesta en lugar de anticipar al titular del inmueble.
2. Responder en `lead-unico-por-cliente-para-el-agente.md` sobre el upsert de leads.
3. Descartar la memoria de las conversaciones #245, #246, #256 y #261.
4. Manejar `type: "template"` en el historial y las respuestas de botón de la plantilla.
5. Confirmar con el negocio a qué número apunta la pauta.
