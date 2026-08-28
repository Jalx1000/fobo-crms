  # El flujo de Sofía, mapeado contra la API del CRM

  > Para el Claude del proyecto del agente. Contrasta `Flujo Agente IA Century21` (los tres guiones
  > de conversación de C21) contra lo que la API realmente hace.
  >
  > Todo lo de acá está verificado ejecutándolo contra la instancia, no leído de una spec.

  ---

  ## 0. La buena noticia: el flujo NO agenda visitas

  Veníamos advirtiendo que `get_disponibilidad` y `agendar_visita` no existen en v1 y que eso podía
  romper la conversación. **Leído el flujo, no la rompe.** Miralo con cuidado:

  > **Agente:** ¿te gustaría que podamos agendar una visita? ¿qué día te queda cómodo?
  > **Cliente:** el sábado en la mañana después de las 10:00
  > **Agente:** Perfecto, **un asesor se comunicará a la brevedad contigo para poder coordinar la hora**.

  Sofía **no ofrece franjas ni confirma un horario**: pregunta la preferencia y deriva. Eso es
  exactamente lo que v1 soporta. No hace falta calendario.

  **Lo único que faltaba** era dónde guardar ese "sábado después de las 10". Se agregó el campo
  `visita_preferencia` en `registrar_solicitud`. Sin él, el dato se perdía y el asesor volvía a
  preguntar lo mismo que el cliente ya había contestado — el peor final posible para una conversación
  que venía bien.

  ---

  ## 1. Menú de operación → `operacion`

  | Opción del menú | Valor a mandar |
  |---|---|
  | 🏠 Comprar de inmueble | `venta` |
  | 🔑 Alquiler de inmueble | `alquiler` |
  | 🤝 Buscar un anticrético | `anticretico` |
  | Hablar con un asesor | `POST .../handoff` sin `codigo` |

  **"Hablar con un asesor" estaba roto y se arregló.** En el primer menú no hay inmueble ni lead
  todavía, así que el handoff no tenía a quién derivar y devolvía **409** — el agente ofrecía algo que
  no podía cumplir. Ahora hay un asesor de guardia configurado (hoy: Sofia). Verificado: HTTP 200.

  ---

  ## 2. Menú de tipo → `tipo`

  El menú del flujo y la lista que aceptaba la API **no coincidían**, y una búsqueda con un tipo no
  reconocido devuelve `422` justo después de que el cliente eligió. Corregido:

  | Opción del menú | Valor a mandar | Estado |
  |---|---|---|
  | 🏠 Casa | `casa` | ya existía |
  | 🌳 Quinta | `quinta` | **agregado** |
  | 🏢 Departamento | `departamento` | ya existía |
  | 📦 Galpón | `galpon` | ya existía |
  | 🌱 Lote | `lote` | **agregado** (se llamaba `terreno`) |
  | 🏘️ Otra propiedad | `otro` | **agregado** |

  También se aceptan `oficina` y `local`, que no están en el menú pero la oficina vende: entran por
  "Otra propiedad".

  ---

  ## 3. Las cinco preguntas de calificación → filtros

  El flujo pregunta cosas distintas según el tipo. La API no tenía la mitad de esos filtros; se
  agregaron.

  ### Casa (guion 1)

  | Pregunta del flujo | Parámetro | Estado |
  |---|---|---|
  | ¿En qué zona? | `zonas[]` | ya existía |
  | ¿Presupuesto aproximado? | `precio_max` + `moneda` | ya existía |
  | ¿Cuántos dormitorios? | `dormitorios_min` | ya existía |
  | ¿Necesitás parqueo o garaje? | `parqueos_min` | **agregado** |
  | ¿Característica indispensable? | — | va en `notas` del lead |

  ### Lote (guion 2)

  | Pregunta del flujo | Parámetro | Estado |
  |---|---|---|
  | ¿Zona o ubicación? | `zonas[]` | ya existía |
  | ¿Presupuesto? | `precio_max` + `moneda` | ya existía |
  | **¿Qué tamaño de lote?** | `m2_terreno_min` | **agregado** |
  | ¿Para vivienda, inversión, negocio? | — | va en `notas` |
  | ¿Característica específica? | — | va en `notas` |

  > **Por qué importaba:** un lote no tiene dormitorios. Con `dormitorios_min` como único filtro de
  > tamaño, el guion 2 era literalmente imposible de ejecutar. Verificado ahora: `tipo=lote` +
  > `m2_terreno_min=300` + `moneda=BOB` + `precio_max=200000` devuelve el lote de 410 m²; pidiendo
  > 500 m² devuelve 0.

  También se agregó `m2_construidos_min`.

  ---

  ## 4. La zona: lo que el flujo pide y la API todavía no resuelve bien

  En el guion 2 el cliente dice **"zona norte, puede ser entre 6to y 9no anillo"**. Los anillos son
  *la* forma de ubicar en Santa Cruz, y hoy:

  - No existe un campo `anillo` en el catálogo.
  - `zonas[]` hace una búsqueda por coincidencia de texto sobre `zona` y `barrio`. Mandar
    `zonas[]=6to anillo` **no va a encontrar nada**, porque las fichas dicen "Zona Norte" y
    "Barrio El Tatu".

  **Recomendación para el prompt, hoy:** mandá la zona amplia (`zonas[]=Zona Norte`) y guardá el
  detalle del anillo en `notas`, para que el asesor lo tenga. No mandes el anillo como zona.

  **Pendiente del lado del CRM:** si los anillos son un criterio de búsqueda frecuente —y por el guion
  parece que sí— hay que agregarlos como campo estructurado. No lo hice todavía porque cambia el
  formulario de carga y requiere que C21 decida cómo se registran (¿un anillo? ¿un rango?).

  ---

  ## 5. Cómo presentar el inmueble

  El flujo muestra la ficha completa con emojis y viñetas, no un resumen. Eso sale de:

  - `titulo` — el encabezado en mayúsculas del anuncio.
  - `descripcion` — el cuerpo. Usá `GET /inmuebles/{codigo}`, que la trae completa; la búsqueda solo
    devuelve `destacado` (una línea).
  - `caracteristicas` — el desglose por tipo. **Objeto libre, claves variables**: un lote trae
    `frente_m`, un galpón trae `altura_galpon_m` y `m2_oficinas`. Iterá lo que venga.
  - `precio` + `moneda` + `modalidad_cambio`.

  **La línea de precio del flujo es literalmente la que hay que replicar:**
  `Precio: $us 247.000 en dólares o al cambio paralelo`
  Ese "al cambio paralelo" es `modalidad_cambio: "tc_paralelo"`. El otro inmueble dice "al T/C oficial
  BCB" (`tc_oficial`). **Es plata distinta y hay que decirlo siempre.**

  Texto plano: emojis sí, Markdown no (WhatsApp lo renderiza literal).

  ---

  ## 6. Tres cosas del flujo que conviene revisar del lado del prompt

  No son problemas de la API. Son huecos del guion.

  ### 6.1 El cliente pregunta "¿hay otras opciones?" y el agente lo ignora

  > **Cliente:** Si, me interesa esa casa, **¿hay otras opciones?**
  > **Agente:** Excelente elección, ¿te gustaría que podamos agendar una visita?

  El agente atropella la pregunta. La API devuelve hasta 10 resultados (3 por defecto): pedir más es
  una llamada más. Vale la pena que el prompt contemple el "mostrame otras".

  ### 6.2 No hay resumen de confirmación antes de registrar el lead

  El contrato original decía que `registrar_solicitud` se llama **una sola vez, tras el "sí" del
  resumen**. En el guion no hay resumen: se salta de "me interesa" a agendar. **Definí en qué punto
  exacto se dispara `registrar_solicitud`**, porque el endpoint no deduplica: dos llamadas crean dos
  leads y el asesor ve la misma persona duplicada.

  Sugerencia: dispararlo junto con el handoff, cuando ya se recolectó la preferencia de visita.

  ### 6.3 El guion nunca manda fotos ni ubicación

  Ninguno de los dos ejemplos usa `enviar_media`. Está construido y funciona, pero si el flujo real no
  lo contempla, decilo — condiciona la prioridad de cargar galerías, que hoy es trabajo manual de los
  asesores y no está hecho.

  Recordá que **la ubicación exacta solo sale después del handoff**, así que en este flujo entraría
  recién al final, si es que entra.

  ### 6.4 "Mensaje cuando no está interesado"

  > Puedo dejar en registro tu búsqueda para que un asesor te contacte

  Eso son **dos llamadas**: `registrar_solicitud` con `temperatura: "frio"`, y después el handoff.
  Si solo hacés la primera, nadie lo contacta y el mensaje miente.

  ---

  ## 7. Ejemplo completo del guion 1, traducido a llamadas

  ```jsonc
  // 1. tras las cinco preguntas
  GET /api/v1/inmobiliaria/inmuebles
      ?operacion=venta&tipo=casa&zonas[]=Zona Norte
      &precio_max=250000&moneda=USD&dormitorios_min=3&parqueos_min=1
  // → 110748 · 3 dorm · 4 parqueos · 247000 USD   (verificado)

  // 2. para presentar la ficha completa
  GET /api/v1/inmobiliaria/inmuebles/110748

  // 3. tras el "sí" y la preferencia de día
  POST /api/v1/inmobiliaria/conversations/{id}/solicitud
  {
    "nombre": "…",
    "operacion": "venta", "tipo": "casa",
    "zonas": ["Zona Norte"],
    "presupuesto": 250000, "moneda": "USD",
    "dormitorios_min": 3,
    "temperatura": "caliente",
    "codigos_interes": ["110748"],
    "visita_preferencia": "Sábado por la mañana, después de las 10:00",
    "notas": "Necesita garaje"
  }
  // → { "lead_id": 41, "referencia": "Solicitud #41" }

  // 4. avisar al cliente PRIMERO, después:
  POST /api/v1/inmobiliaria/conversations/{id}/handoff
  { "codigo": "110748", "reason": "Quiere visitar, sábado am" }
  // → { "handoff": { "asesor": "Sofia" } }   ← usá ESE nombre en el aviso
  ```

  Desde el paso 4, cualquier intento de escribir devuelve **409**.

  ---

  ## 8. Lo que sigue faltando

  | # | Qué | De quién depende |
  |---|---|---|
  | 1 | Confirmar `conversation_id` en vez de `wa_id` | **Vos.** Ya está construido así. |
  | 2 | Definir cuándo dispara `registrar_solicitud` (§6.2) | **Vos.** |
  | 3 | Anillos como criterio de búsqueda (§4) | C21 + CRM |
  | 4 | Cargar galerías de los inmuebles | C21 (manual, desde el CRM) |
  | 5 | `get_disponibilidad` / `agendar_visita` | Fase 2, **no se hace ahora** |
