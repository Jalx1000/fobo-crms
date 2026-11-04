# Mensaje al equipo del agente (CRM Imprimir)

**Asunto: Stretch Film 15,8 kg, derivar cuando no coincide, cotizaciones simultáneas y cotizaciones fuera de lista**

Hola equipo, les comparto cambios del CRM de estos días y algunas consultas para ver si les afectan.

## 1. Cambio aplicado (20/10): descripción del Stretch Film

- La descripción del producto CM_00001 decía «rollo de 4.7 kg manual y de **15.7 kg** automático». La lista de precios y la variante dicen «Automático **15,8 kg**».
- El 19/10, en la conversación #14926, el agente ofreció el botón «Automático (15,7 kg)». Al consultar el precio, el CRM respondió `sin_datos` porque 15,7 no existe en la lista, y el agente derivó una venta que sí tenía precio (560 Bs).
- Corregimos la descripción a «**15,8 kg**». Los precios, las variantes y los endpoints no cambiaron.
- **¿Les afecta?** ¿El agente guarda en caché la ficha o los botones de los productos? ¿El prompt en Railway tiene escrito «15,7 kg» en algún lado? La regla del 14/10 ya decía 15,8, pero en documentos viejos sigue figurando 15,7.

## 2. Qué hace el agente cuando no hay coincidencia

- Hoy, si lo que manda el agente a `POST /catalogo/{sku}/precio` no coincide con la lista, el CRM responde `estado: sin_datos` con el mensaje «No inventes un precio: derivá la consulta a un asesor».
- Queremos que **cualquier error o falta de coincidencia termine en derivación a un asesor**: `sin_datos`, un `ambiguo` que no se resuelve después de repreguntar, errores HTTP o tiempos de espera.
- **¿Pueden confirmar que el agente ya hace eso en todos esos casos?**

## 3. Propuesta, sin aplicar

- Que en `sin_datos` el CRM devuelva además un campo `valores_validos` con los valores cargados de cada dato que no coincidió, por ejemplo `{"Presentación": ["Manual 4,7 kg", "Automático 15,8 kg"]}`.
- Es un campo nuevo: lo de hoy sigue igual.
- **¿Les sirve, o prefieren derivar siempre sin reintentar?** Si les complica, no lo hacemos.

## 4. Cotizaciones simultáneas

- El 19/10 a las 18:04, el agente creó 4 cotizaciones casi a la vez para el pedido #1075. Dos chocaron en la base de datos y se perdieron (#7880 y #7881).
- **Ya aplicado en el CRM (21/10):** crear una cotización y mandar fichas se reintentan solos hasta 3 veces si chocan. No cambia nada del lado de ustedes.
- **Igual les pedimos:** ¿pueden hacer las llamadas a `/cotizacion` de un mismo pedido una detrás de otra, en vez de en paralelo?

## 5. Cotizaciones fuera de lo registrado

- En octubre, de 113 cotizaciones, unas 27 líneas salieron con el producto **sin variante**: «Stretch Film» (16), «Hules» (7) y «Bolsas Magia Verde» (4), sin presentación, litros ni segmento. Hubo además 9 líneas con precio 0, por ejemplo la #7884 (Stretch Film, 500 unidades).
- Una cotización así no corresponde a ninguna fila de la lista de precios: el asesor tiene que adivinar qué pidió el cliente.
- **Regla que queremos:** el agente crea la cotización **solo** cuando el CRM le devolvió un precio para esa variante (`estado: ok`). Si no, deriva a un asesor y no crea nada. Esto reemplaza a la «cotización con precio pendiente» para los casos sin variante.
- **Del lado del CRM (a confirmar con ustedes):** podemos rechazar con un 422 la cotización que llega sin variante resuelta, con el mensaje «derivá a un asesor». **¿Les rompe algo si lo activamos?**

Gracias.
