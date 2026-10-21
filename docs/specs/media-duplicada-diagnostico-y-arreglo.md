# Imágenes duplicadas en `enviar_media` — diagnóstico y arreglo

> Para el equipo del agente.
> **Diagnóstico cerrado con evidencia de producción. El arreglo está del lado del CRM y ya está aplicado.**
> **No necesitan cambiar nada**, pero hay una cosa que sí conviene que revisen (§5).

---

## 1. Lo que pasaba

Las mismas cuatro imágenes, con los mismos `provider_media_id`, salieron **dos veces separadas por
19 segundos**:

```
10:08:55 → 28978823221723169, 3276357695895427, 1844276533402129, 2295302384642658
10:09:14 → 28978823221723169, 3276357695895427, 1844276533402129, 2295302384642658
```

Son **dos peticiones distintas** a `POST /conversations/{id}/media`, no un duplicado interno nuestro.

---

## 2. Qué descartamos, y con qué evidencia

| Hipótesis | Resultado |
|---|---|
| El CRM elige el mismo archivo dos veces | **No.** La selección es un `limit` sobre la consulta; cada fila es un archivo distinto. Verificado con 25 pruebas sobre la cartera real |
| El CRM crea dos mensajes por archivo | **No.** Una ráfaga de 20 que parecía sospechosa eran 20 archivos **distintos**: dos inmuebles de 10 |
| El job de envío reintentó | **No.** `retry_after` (90 s) es mayor que el timeout del worker (60 s), que es la relación correcta. Los trabajos fallidos del sistema son de SMTP y de subidas viejas |
| Dos workers compitiendo por el mismo job | **No.** Hay un solo `queue:work` |

Descartado todo eso, queda una sola explicación compatible con los datos: **el endpoint recibió dos
peticiones idénticas y obedeció las dos.** Estaba haciendo exactamente lo que se le pedía.

---

## 3. La causa, y por qué no es culpa del agente

**19 segundos es la firma de un reintento por timeout.**

El agente pide la galería. El CRM tarda en responder, porque enviar diez imágenes a la Cloud API no
es instantáneo. El cliente HTTP del agente corta por timeout y reintenta. **El primer envío ya había
salido**, pero el agente no tenía forma de saberlo: nunca recibió la respuesta.

El reintento es correcto: sin él, un fallo real de red dejaría al cliente sin fotos después de que el
agente le dijo que se las mandaba. **Lo que faltaba era que el endpoint supiera que dos pedidos
idénticos seguidos son la misma intención.** Esa es responsabilidad del servidor, no del cliente.

---

## 4. El arreglo (ya aplicado en el CRM)

`POST /conversations/{id}/media` ahora es **idempotente dentro de una ventana de 60 segundos**.

Dos pedidos con la misma **conversación + código + tipo + cantidad** dentro de ese minuto cuentan
como uno: el primero envía, el segundo responde `200` con el resultado del primero **sin enviar nada
de nuevo**.

### Lo que ven ustedes

Un reintento ahora devuelve lo mismo que el envío original, más una marca:

```jsonc
// 200 — primera petición
{ "message": "Enviado.", "enviados": 10, "codigo": "C21-0001" }

// 200 — reintento dentro de los 60 s
{ "message": "Enviado.", "enviados": 10, "codigo": "C21-0001", "repetido": true }
```

`repetido` es informativo, para sus logs. **No tienen que hacer nada distinto cuando aparece**: el
envío está hecho y el cliente recibió la galería una sola vez.

### Lo que NO se deduplica

Pedidos que son genuinamente distintos pasan siempre, incluso en el mismo segundo:

- otro inmueble (`C21-0002` en vez de `C21-0001`)
- otro tipo (`plano` en vez de `foto`)
- otra `cantidad`
- otra conversación

Verificado con 9 pruebas.

### Si el envío falla

La marca se levanta. Un `501` (el canal no soporta archivos) o un error inesperado **no** bloquean el
reintento: no salió nada, así que reintentar es lo correcto. La marca solo protege un envío que
realmente ocurrió.

La ventana es configurable (`INMO_MEDIA_IDEMPOTENCIA_SEGUNDOS`, 60 por defecto; `0` desactiva la
deduplicación).

---

## 5. Lo único que les pedimos revisar

**¿Cuál es el timeout del cliente HTTP con el que llaman a `/media`?**

Con el arreglo, un timeout corto ya no duplica imágenes. Pero sigue teniendo un costo: el agente cree
que el envío falló y puede decirle al cliente algo que no corresponde, o reintentar varias veces
seguidas sin necesidad.

Enviar diez imágenes puede tardar **varios segundos** —son diez llamadas a la Cloud API—. Un timeout
de 30 segundos o más para este endpoint evita el falso negativo. Si hoy está en 10 o 15, vale subirlo.

No es urgente ni bloqueante: el CRM ya aguanta el reintento sin duplicar.

---

## 6. Cambio relacionado, del mismo día

El tope de archivos por envío **pasó de 4 a 10** (`cantidad` por defecto 10, tope duro 10; antes
default 4 y tope 8). Su documento `tool-enviar-media-agente.md` dice «Por defecto 4, tope duro 8» —
esa línea quedó vieja.

**Nunca se duplica para llegar al número:** si el inmueble tiene 3 fotos, se mandan 3. Verificado
sobre la cartera real.

Aviso aparte: de los inmuebles cargados hoy, **solo uno tiene 10 fotos**; el resto tiene 2 o 3, y dos
no tienen ninguna (a esos el endpoint responde `404`). Subir el tope no ayuda si la carga no acompaña.
Es un asunto nuestro, pero les afecta: el agente puede prometer fotos que no existen.

---

## 7. Qué falta probar

El arreglo está verificado en sus partes —la huella distingue lo que debe, la guarda deduplica, la
marca se levanta al fallar— pero **no se reprodujo el escenario completo con un reintento real de su
cliente HTTP**. Si pueden forzar un timeout contra el endpoint, lo confirmamos juntos en cinco
minutos.
