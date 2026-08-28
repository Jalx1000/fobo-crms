# Lo que el CRM le impone a la conversación de Sofía

> Para el Claude del proyecto del agente. Complementa `handoff-crm-al-agente.md`, que describe el
> contrato HTTP; esto describe **cómo se traduce ese contrato en comportamiento conversacional**.
>
> Todo lo de acá está verificado contra la instancia real (`c21.sofopolis.com`), no contra la spec.
> Donde diga "devuelve 409" es porque se probó y devolvió 409.

---

## 0. La regla que resume todo

**El CRM no confía en el prompt.** Lo que Sofía no debe revelar, no se lo mandamos; lo que no debe
hacer, lo rechazamos con un código HTTP. Si el prompt se rompe, se jailbreakea o el modelo alucina,
las garantías siguen en pie.

La consecuencia práctica para vos: **hay cosas que el prompt cree poder hacer y la API va a negar**.
Si el prompt y la API discrepan, gana la API, y el cliente ve a Sofía contradiciéndose. Por eso
importa que el prompt conozca estos límites de antemano.

---

## 1. Fase: el cliente pregunta por inmuebles

### Lo que Sofía puede ofrecer

`GET /api/v1/inmobiliaria/inmuebles` devuelve **solo inmuebles en estado `disponible`**.

Un inmueble reservado, vendido, suspendido o en borrador **no existe** para el agente. No es que
venga marcado: no viene. Consecuencia: si el cliente pregunta "¿y la casa de Hamacas que vi en
Facebook?" y esa casa se reservó ayer, Sofía va a decir que no la encuentra. Eso es correcto y
deliberado — la oficina prefiere no ofrecer lo que no puede cerrar — pero el prompt debería manejar
ese "no la encuentro" con gracia, porque va a pasar.

### Cuántos resultados

- Por defecto **3**. Máximo **10** aunque pidas más.
- Es deliberado: el handoff pedía "2-3 concretos", y una lista larga por WhatsApp no se lee.

### Precios: NO HAY CONVERSIÓN DE MONEDA. Nunca.

Esto es lo más importante de esta sección y lo más fácil de arruinar.

- Cada inmueble se guarda y se devuelve **en su moneda** (`precio` + `moneda`). El CRM no convierte,
  no estima, y no existe un tipo de cambio configurado que alguien pueda usar.
- **Sofía tampoco debe convertir.** El mercado boliviano es bimonetario y oficial y paralelo divergen
  mucho: un "son unos 4.800.000 Bs" calculado por el modelo es una cifra inventada sobre el precio de
  una casa. El prompt tiene que prohibirlo explícitamente.
- Cuando el cliente da un rango sin aclarar moneda, el filtro se aplica **solo a las fichas de la
  moneda pedida**; las de la otra moneda **igual se devuelven, sin filtrar por precio**. O sea: si
  pediste "hasta 300.000 USD", puede venir una ficha de 1.700.000 BOB. No es un bug — es para que el
  cliente vea que existe y decida él. Sofía debe mostrarla con su precio literal.

### El campo `modalidad_cambio` es información comercial, no metadato

Viene por inmueble y vale `solo_usd`, `tc_oficial` o `tc_paralelo`. Sale de los anuncios reales:
uno dice "en DÓLARES O AL T/C OFICIAL BCB", otro "al cambio paralelo". **Es plata real y distinta.**
Si Sofía omite esto, el cliente llega a la visita con una expectativa equivocada de precio.

### `destacado`: la línea que Sofía debe usar

Cada ficha trae `destacado`, que es texto real del anuncio ("Galpón de 500 m² con piso de alto
tráfico y 6,5 m de altura"). **Usala tal cual.** No la reemplaces por un adjetivo genérico. Si viene
`null`, el CRM ya cayó a la primera oración de la descripción — o sea que si llega null es porque el
inmueble no tiene ni descripción, y ahí sí el prompt tiene que arreglárselas.

### `caracteristicas` es un objeto libre y varía por tipo

Un galpón trae `altura_galpon_m`, `m2_oficinas`, `m2_parqueo_showroom`. Una casa trae `frente_m`,
`dormitorios_casa`, `dormitorios_departamento`. **No asumas claves fijas**: iterá lo que venga.
Son los datos que venden un inmueble comercial y no están en ningún campo estándar.

### Ojo con `dormitorios` en propiedades compuestas

Una de las fichas reales es "casa + departamento independiente": `dormitorios: 3` es el total
(2 de la casa + 1 del depto), y el desglose está en `caracteristicas`. Si el cliente pide 3
dormitorios y Sofía le ofrece esto sin aclarar que uno está en un departamento aparte, la visita
va a ser una decepción.

---

## 2. Fase: mostrar fotos

`POST /api/v1/inmobiliaria/conversations/{id}/media` con `{codigo, tipo, cantidad}`.

- `tipo`: `foto` | `video` | `plano` | `tour`.
- **Máximo 8 archivos por llamada**, aunque pidas 50. Por defecto 4.
- El CRM elige cuáles: **la portada primero**, después el orden que definió el asesor. Sofía no
  elige fotos ni conoce sus URLs.
- **`404` si el inmueble no tiene ese tipo cargado** — con mensaje explícito ("no tiene fotos
  cargadas"). Esto va a pasar seguido al principio: el catálogo se está cargando a mano.

> ⚠️ **El prompt tiene que manejar el 404 ANTES de prometer.** Si Sofía dice "te mando las fotos" y
> después recibe un 404, ya prometió algo que no puede cumplir. La ficha trae `tiene_fotos`,
> `tiene_plano` y `tiene_tour` justamente para que consultes antes de ofrecer.

Un `tour` no es un archivo sino un enlace: sale como mensaje de texto con la URL.

---

## 3. Fase: la ubicación — el gate más estricto

**`GET /inmuebles/{codigo}` NUNCA devuelve la dirección exacta, ni el link de Google Maps, ni las
coordenadas.** No están en la respuesta. No hay parámetro que las traiga. Sofía no puede filtrarlas
ni aunque quiera, porque nunca las tiene.

La única vía es `POST .../conversations/{id}/ubicacion {codigo}`, y **está gateado**:

| Situación | Respuesta |
|---|---|
| Sin handoff abierto | **409** |
| Con handoff abierto pero sobre OTRO inmueble | **409** |
| Con handoff abierto sobre ESE inmueble | **200**, se envía |
| Sin coordenadas cargadas | **404** |

Traducido a comportamiento: **primero se deriva al asesor, después se manda la ubicación.** En v1 no
hay agenda, así que "visita confirmada" equivale a "el titular ya tomó la conversación".

Lo que Sofía sí puede decir siempre: `zona`, `barrio` y `referencia`. Son públicos y alcanzan para
ubicar ("Zona Norte, sobre Av. Banzer a la altura del Km 11").

---

## 4. Fase: registrar el lead

Tres endpoints, un solo pipeline (`Inmobiliaria C21`), distinguidos por tipo de lead:

| Endpoint | Tipo | Referencia que devolvemos |
|---|---|---|
| `/solicitud` | Comprador (o Inquilino si `operacion=alquiler`) | `Solicitud #41` |
| `/captacion` | Propietario | `Captación #42` |
| `/postventa` | Postventa | `Postventa #43` |

- **La referencia cambia según el flujo.** Repetirle "Solicitud #42" a un propietario que ofrece su
  casa no tiene sentido: él no solicitó nada. Usá la que devuelve la API, no una plantilla fija.
- **Una sola vez, tras el "sí" del resumen.** El endpoint no deduplica: dos llamadas crean dos leads.
- `codigos_interes` acepta varios códigos en una sola solicitud — si preguntó por tres inmuebles, van
  los tres, no tres leads.
- `temperatura` (`caliente`/`tibio`/`frio`) es la única alerta que recibe el equipo. Es señal, no
  decoración.
- La persona se crea sola a partir de la conversación. No pidas datos que ya tenemos.

---

## 5. Fase: derivar al asesor — y el orden importa

`POST .../conversations/{id}/handoff {codigo, reason}`.

1. **Primero avisá al cliente**, después llamá al endpoint. Una vez derivada, tu siguiente intento de
   escribir recibe **409**.
2. Es **idempotente**: si ya hay uno abierto devuelve el mismo (`nuevo: false`) y **no cambia el
   titular**. Verificado: dos llamadas → un solo handoff en la base.
3. Se deriva **siempre al titular del `codigo`**. Nunca a otro, ni siquiera si está ausente. La
   respuesta trae `handoff.asesor` con el nombre — usá ese nombre en el aviso al cliente, no uno
   inventado.
4. Si no mandás `codigo`, se cae al dueño del lead de la conversación. Si tampoco hay: **409**, y
   Sofía no debería haber prometido una derivación.

Desde ese momento: dejamos de notificarte por webhook, y el webhook trae `handoff.open = true`.

---

## 6. Lo que Sofía NO puede hacer en v1

Esto es lo que más riesgo tiene de romper la conversación, porque el prompt heredado del tenant
anterior podría asumir lo contrario:

- ❌ **No hay agenda.** `get_disponibilidad` y `agendar_visita` **no existen**. Sofía no debe ofrecer
  franjas horarias, ni decir "te agendo el martes a las 10". El flujo termina en el handoff: la
  visita la coordina el asesor humano.
- ❌ **No convertir monedas** (§1).
- ❌ **No dar la dirección exacta** antes del handoff (§3).
- ❌ **No prometer fotos sin verificar `tiene_fotos`** (§2).
- ❌ **No preguntar por ciudad.** Una sola plaza. No hay ruteo geográfico.

---

## 7. Códigos de respuesta y qué significan conversacionalmente

| Código | Qué pasó | Qué debería hacer Sofía |
|---|---|---|
| `401` | Token inválido | Nada. Es un problema de configuración, no de la charla. |
| `404` | El código no existe, o no hay ese tipo de archivo | Decírselo al cliente con naturalidad. Es información, no error. |
| `409` | Handoff abierto, o precondición no cumplida | **Callarse.** Si es sobre ubicación, derivar primero. |
| `422` | Payload irreparable **o fuera de la ventana de 24 h** | No reintentar. El mensaje se descarta. |
| `501` | El canal activo no soporta eso | Hoy no debería pasar (Cloud API soporta todo). |
| `5xx` | Fallo transitorio | Reintentar con backoff. |

**El `422` es ambiguo a propósito en tu contrato original y conviene que lo separes:** un 422 en
`/messages` casi siempre significa "se cerró la ventana de 24 h", no "mandaste basura".

---

## 8. Captura de teléfono (solo canales sin número)

El webhook trae `phone_required`, `phone_prompt_attempts` y `phone_prompt_exhausted`.
Los contadores **los lleva el CRM**, no vos: sobreviven a un reinicio de tu servicio.

- `phone_required: true` significa **"falta el número"**, no "pedilo ahora". Cuándo pedirlo lo decide
  Sofía, y solo cuando la conversación calificó.
- Mandá el número **tal cual lo escribió el cliente**; el CRM normaliza. `422` = inválido, se puede
  pedir una vez más.
- Un rechazo se registra con `{"rechazado": true}` y **agota** los pedidos. No insistas.
- Tope de 3.

---

## 9. Realidades del entorno que van a afectar la demo

Dichas para que no las confundas con bugs:

- **El catálogo tiene 2 inmuebles.** Reales, pero 2. Muchas búsquedas van a volver vacías o con un
  solo resultado, y Sofía va a parecer limitada. No es la API.
- **Ninguno tiene fotos cargadas todavía.** `tiene_fotos: false` hasta que un asesor suba archivos
  desde el CRM. `enviar_media` va a devolver 404.
- **Hay un solo asesor**, y se llama **Sofía** — igual que el agente. Todos los handoffs hoy van a
  decir "le paso con Sofía". C21 decidió dejarlo así por ahora.
- **El código del inmueble no siempre tiene el mismo formato.** Uno es `C21-0001` (generado por el
  CRM) y el otro es `110748` (el del anuncio ya publicado). Ambos son válidos. No asumas un patrón,
  y aceptá lo que el cliente te diga.
- **No hay entorno de staging.** Lo que pruebes toca la misma instancia.

---

## 10. Lo que necesito de vos para cerrar esto

1. **Pasame la guía de flujo conversacional** (`Flujo Agente IA Century21`). Quiero contrastar cada
   etapa contra estas restricciones y marcarte dónde el flujo pide algo que la API va a negar —
   especialmente si el flujo contempla agendar visitas, que en v1 no existe.
2. **Confirmá `conversation_id` en vez de `wa_id`.** Ya está construido así.
3. **Decime si el prompt actual convierte monedas o promete horarios.** Son los dos puntos donde una
   conversación se rompe de forma visible para el cliente.
