# Para el agente — el recordatorio de recompra

Después de cada entrega, el CRM le pregunta solo al cliente si quiere agendar el
próximo pedido. El agente tiene dos trabajos nuevos: **tomar el pedido sin volver a
pedir datos que ya tenemos**, e **informar qué contestó**.

---

## 1. Cómo te enterás de que la conversación viene de un recordatorio

El evento `message.received` trae un bloque nuevo, `recompra`. **Sólo aparece cuando
hay un recordatorio vivo**: en una conversación cualquiera viene `null` o no viene.

```json
{
  "event": "message.received",
  "contact": { "person_id": 1234, "phone": "+5493884..." },
  "recompra": {
    "esperando_respuesta": true,
    "intento": 1,
    "datos_completos": true,
    "domicilio": {
      "direccion": "Belgrano 100",
      "referencia": "Portón verde",
      "lat": -24.1858,
      "lng": -65.2995
    },
    "responder": {
      "method": "POST",
      "url": "https://.../api/v1/whatsapp/conversations/1/recompra",
      "respuestas": {
        "si":   "Quiere pedir ahora. Tomá el pedido sin volver a pedirle el domicilio.",
        "no":   "No quiere ahora. Se le vuelve a preguntar en unos días.",
        "baja": "No quiere recibir más estos recordatorios."
      }
    }
  }
}
```

### `datos_completos: true` ⇒ **no preguntes la ubicación**

Es el punto central. Este cliente ya compró y su domicilio está guardado: viene en
`domicilio`. Lo que corresponde es **confirmarlo**, no pedirlo de nuevo:

> «Perfecto. Te lo llevamos a **Belgrano 100** (portón verde), ¿sigue estando bien?»

Volver a hacer el cuestionario de alta a alguien que hace seis meses que compra es la
queja que originó todo esto.

Si `datos_completos` es `false` sí hay que pedir el domicilio: es un cliente del que
no guardamos dirección, aunque sea viejo.

El `recompra.intento` dice si es la primera vez que se le pregunta o la tercera.
Sirve para no repetir la misma frase tres veces.

---

## 2. Qué tenés que informar

Cuando el cliente responda, mandá **una** de estas tres:

```http
POST /api/v1/whatsapp/conversations/{id}/recompra
Authorization: Bearer <token>

{ "respuesta": "si" | "no" | "baja" }
```

| respuesta | cuándo | qué hace el CRM |
|---|---|---|
| `si` | quiere pedir ahora | deja de preguntar; el ciclo se reinicia con la próxima entrega |
| `no` | ahora no, quizás después | le vuelve a preguntar en 2 días, hasta 3 intentos |
| `baja` | **no quiere más estos mensajes** | no le manda ninguno más |

### 🔴 `baja` es la decisión más importante que tomás

El CRM **no puede** distinguir «ahora no» de «no me escribas más»: eso es entender
una frase, y hacerlo con una lista de palabras acierta a veces. Lo decidís vos.

Equivocarse para el lado de `baja` cuesta un cliente que deja de recibir
recordatorios. Equivocarse para el otro lado —seguir escribiéndole a alguien que
pidió que no— termina en un reporte de spam, y los reportes bajan la calidad del
número en Meta hasta que **restringen la línea por la que entra todo el negocio**.
Ante la duda, `baja`.

Frases que son `baja`: «no me manden más mensajes», «desuscribir», «basta»,
«sacame de la lista», «no quiero publicidad».

### Si se arrepiente

```http
POST /api/v1/whatsapp/conversations/{id}/recompra/reactivar
```

Sólo cuando el cliente lo pide explícitamente. Nadie del equipo puede deshacer una
baja desde el CRM, a propósito.

---

## 3. Si no contestás nada

No pasa nada malo: el silencio del cliente cuenta como `no` y se le vuelve a
preguntar en 2 días. Pero informar la respuesta es lo que hace que un `si` deje de
molestarlo y que un `baja` se respete.

---

## 4. Cuándo sale el recordatorio

Lo calcula el CRM con el **ritmo de compra de cada cliente**: el promedio de días
entre sus últimas entregas. El que compra cada 7 días recibe el recordatorio al
día 7; el que compra cada 21, al 21. Un cliente sin historial usa el valor por
defecto.

El agente no tiene que calcular nada de esto. Sólo reacciona al bloque `recompra`.

---

## 5. Texto para pegar en el prompt del agente

Esto es lo que hay que agregarle a las instrucciones del agente. Nada más.

```text
RECORDATORIO DE RECOMPRA

Algunos eventos traen un bloque `recompra`. Si NO viene, o viene en null,
ignorá todo lo que sigue: es una conversación normal.

Si viene, el cliente está contestando un mensaje nuestro que le preguntó si
quiere agendar su próximo pedido de agua.

1. SI `recompra.datos_completos` ES true: NO le pidas el domicilio.
   Ya lo tenemos en `recompra.domicilio`. Confirmalo en una línea y seguí:

     "Perfecto. Te lo llevamos a {domicilio.direccion} ({domicilio.referencia}),
      ¿sigue estando bien?"

   Recién si dice que se mudó, pedile el nuevo.
   Si `datos_completos` es false, ahí sí pedile el domicilio como siempre.

2. TOMÁ EL PEDIDO como lo hacés normalmente: cuántos botellones quiere, qué
   productos. No hay límite de cantidad.

3. INFORMÁ QUÉ CONTESTÓ, siempre, con UNA de estas tres:

   POST {recompra.responder.url}
   Authorization: Bearer <token>
   { "respuesta": "si" }     → quiere pedir ahora
   { "respuesta": "no" }     → ahora no, quizás más adelante
   { "respuesta": "baja" }   → no quiere recibir más estos recordatorios

4. CUÁNDO ES "baja": cuando pide dejar de recibir estos mensajes.
   Ejemplos: "no me manden más mensajes", "desuscribir", "basta",
   "sacame de la lista", "no quiero publicidad", "dejen de escribirme".

   NO es baja: "ahora no", "la semana que viene", "todavía me queda agua",
   "después te aviso". Eso es "no".

   Ante la duda entre "no" y "baja", elegí "baja". Seguir escribiéndole a
   alguien que pidió que no es un reporte de spam, y los reportes nos pueden
   hacer perder la línea de WhatsApp.

5. SI SE ARREPIENTE y pide volver a recibirlos:
   POST {base}/api/v1/whatsapp/conversations/{id}/recompra/reactivar
   Sólo si lo pide él. Nunca por tu cuenta.

6. `recompra.intento` dice si es la primera vez que se lo preguntamos (1) o
   la tercera. No repitas la misma frase: en el intento 2 o 3 sé más breve.
```
