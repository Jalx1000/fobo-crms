# Handoff — respuesta del equipo del CRM (CENTURY 21 · Sofía)

> Para el Claude que trabaja en el servicio del agente (Python/FastAPI + LangGraph).
> Escrito por el equipo del CRM inmobiliario (Krayin / Laravel, instancia `c21.sofopolis.com`).
> Es la respuesta punto por punto a tu handoff. **Leelo entero antes de escribir tools:** hay una
> contrapropuesta que cambia la firma de las 10 tools, y tres diferencias con tu spec §5 y §9.

---

## 0. Estado real, sin adornos

- El CRM es **Krayin CRM** (monorepo modular Webkul/Concord, Laravel 10). Mismo motor que el tenant
  anterior de impresión.
- El módulo de WhatsApp **ya existe y funciona**: gateway abstracto, inbox, webhook saliente,
  endpoint de reply con manejo de la ventana de 24h. Gateway activo: **Cloud API de Meta**
  (no Kommo), así que media y ubicación son técnicamente posibles.
- `WHATSAPP_AGENT_WEBHOOK_URL` y `WHATSAPP_AGENT_TOKEN` **ya están configurados**. El CRM ya te
  puede notificar.
- ✅ **Los 10 endpoints del §6 están implementados y probados.** Entidad `inmuebles` con su CRUD y
  galería, API del catálogo, envío de media y ubicación, alta de leads en los tres flujos, y handoff
  idempotente. Cada uno verificado contra la instancia real, no solo escrito.
- ✅ **Media y ubicación funcionan.** Era lo más nuevo respecto al tenant anterior (§9.1) y ya está.
- ⚠️ **Catálogo con 2 inmuebles reales** cargados para pruebas. El volumen real lo cargan los
  asesores desde la UI, que ya existe.
- ❌ **`get_disponibilidad` y `agendar_visita` NO existen** y no están planificados para v1. Ver §2.2.

---

## 1. 🔴 Contrapropuesta que necesitamos que aceptes: `conversation_id`, no `wa_id`

Tu §2 dice, con razón, que los ids internos nunca viajan por el LLM porque los modelos débiles los
alucinan. Estamos de acuerdo. Pero después seis de tus tools llevan `wa_id` como primer parámetro, y
un `wa_id` es un teléfono:

- **En Messenger no existe.** Tu propia spec lo admite con el fallback `conv:<conversation_id>`.
- **Cambia de formato** (`591…` / `+591…` / con o sin espacios) según de dónde venga.
- **Cambia de dueño.** Un número reasignado mezcla el historial de dos personas.

**Propuesta:** todas las rutas cuelgan de `/conversations/{conversation_id}/…`. El `conversation_id`
ya te lo mandamos en cada webhook, ya lo inyectás server-side, y del lado del CRM ya resuelve a
`person_id` y `lead_id`. El `wa_id` deja de ser clave y pasa a ser un dato más del contacto.

**Se mantiene tu excepción:** el `codigo` del inmueble es público, lo ve el cliente en el anuncio, y
sí lo maneja el modelo. Es el único identificador que el LLM toca.

👉 **Confirmá esto antes de escribir las tools.** Es lo único que bloquea que arranquemos los endpoints.

---

## 2. Tres diferencias con tu spec — leelas, no copies tu contrato

### 2.1 Un solo pipeline, no tres (contradice tu §5.3)

Tu spec pide tres pipelines que no se mezclan. **C21 decidió un pipeline único**, `Inmobiliaria C21`,
con etapas propias: `Prospectos → Agenda visita → Cliente con propuesta → Reserva → Anticipo-Garantía
→ Cierre exitoso`, más `Desistimiento` y `Devolución`.

Los tres flujos se distinguen por **`lead_type`**, no por pipeline:

| Tu tool | Qué se crea |
|---|---|
| `registrar_solicitud` | lead con type `Comprador` (o `Inquilino` si la operación es alquiler) |
| `registrar_captacion` | lead con type `Propietario` |
| `registrar_postventa` | lead con type `Postventa` |

Para vos no cambia nada: seguís llamando tres endpoints distintos. Solo no asumas que hay tres
tableros separados del otro lado.

### 2.2 v1 no agenda visitas (recorta tu §6 y tu §9.4)

`get_disponibilidad` y `agendar_visita` **quedan fuera de la primera entrega**. Requieren definir
horario laboral por asesor y si Google Calendar es fuente de verdad, y eso todavía no está resuelto.

**El flujo de v1 termina en el handoff:** Sofía muestra inmuebles con fotos, registra la solicitud y
deriva al titular. **La visita la agenda el humano.** Ajustá el prompt y el grafo a eso: Sofía no
debe ofrecer franjas horarias en v1, porque no las va a poder consultar.

### 2.3 Postventa existe, pero como `lead_type`

No hay pipeline de postventa. `registrar_postventa` sigue siendo un endpoint propio; simplemente crea
el lead en el pipeline único con su type.

---

## 3. Contrato de la API

Base: `https://c21.sofopolis.com`. Auth: `Authorization: Bearer <CRM_API_KEY>` (Sanctum) en todas.
**Estado: implementado y probado.** Sin token, todas devuelven 401 (verificado).

⚠️ **Ojo con los prefijos, no son todos iguales:** lo del catálogo y lo específico del negocio
inmobiliario cuelga de `/api/v1/inmobiliaria/`; lo genérico de mensajería, de `/api/v1/whatsapp/`.

| Tool | Método y ruta |
|---|---|
| `buscar_inmuebles` | `GET /api/v1/inmobiliaria/inmuebles` |
| `get_inmueble` | `GET /api/v1/inmobiliaria/inmuebles/{codigo}` |
| `enviar_media` | `POST /api/v1/inmobiliaria/conversations/{id}/media` |
| `enviar_ubicacion` | `POST /api/v1/inmobiliaria/conversations/{id}/ubicacion` |
| `registrar_solicitud` | `POST /api/v1/inmobiliaria/conversations/{id}/solicitud` |
| `registrar_captacion` | `POST /api/v1/inmobiliaria/conversations/{id}/captacion` |
| `registrar_postventa` | `POST /api/v1/inmobiliaria/conversations/{id}/postventa` |
| `derivar_a_asesor` | `POST /api/v1/inmobiliaria/conversations/{id}/handoff` |
| `get_historial` | `GET /api/v1/whatsapp/conversations/{id}/historial` |
| `guardar_telefono` | `POST /api/v1/whatsapp/conversations/{id}/telefono` |
| (responder texto) | `POST /api/v1/whatsapp/conversations/{id}/messages` |

### 3.1 Catálogo (el `codigo` es público, lo maneja el modelo)

```http
GET /api/v1/inmobiliaria/inmuebles
    ?operacion=venta|alquiler|anticretico
    &tipo=casa|departamento|terreno|oficina|local|galpon
    &zonas[]=Zona Norte
    &precio_min=&precio_max=&moneda=USD|BOB
    &dormitorios_min=&limite=3
```
```jsonc
{ "resultados": [{
    "codigo": "C21-0002",
    "operacion": "venta", "tipo": "casa",
    "zona": "Zona Norte", "barrio": "Barrio Hamacas",
    "dormitorios": 2, "banos": 2, "parqueos": 4,
    "m2_construidos": 167, "m2_terreno": 364,
    "precio": 247000, "moneda": "USD",
    "precio_anterior": 279000,               // el "antes" del anuncio, si lo hay
    "modalidad_cambio": "tc_paralelo",       // solo_usd | tc_oficial | tc_paralelo
    "negociable": true,
    "destacado": "Casa con departamento independiente a precio de terreno",
    "caracteristicas": { "frente_m": 13 },   // varía por tipo; mostrar, no filtrar
    "tiene_fotos": true, "tiene_plano": false, "tiene_tour": false
}], "total": 1 }
```

```http
GET /api/v1/inmobiliaria/inmuebles/{codigo}
```
Lo mismo + `descripcion`, `caracteristicas` completo, `punto_encuentro`, y:
```jsonc
"asesor": { "nombre": "…", "telefono": "…" }
```

> ⚠️ **La ficha NUNCA devuelve `direccion_exacta`, `latitud`, `longitud` ni el link de Google Maps.**
> No es una regla de prompt: esos campos no existen en la respuesta. La única ruta a la ubicación es
> el endpoint de §3.2, que la lee server-side y la envía sin que el dato pase por el LLM.

### 3.2 Envío al cliente

```http
POST /api/v1/whatsapp/conversations/{id}/media      { "codigo": "C21-0002", "tipo": "foto|video|plano|tour", "cantidad": 4 }
POST /api/v1/whatsapp/conversations/{id}/location   { "codigo": "C21-0002" }
```

- **La galería vive en el CRM**, como preferías en tu §4. Vos pedís "4 fotos del C21-0002"; nosotros
  elegimos cuáles (portada primero), las mandamos y las registramos en el inbox del asesor.
- **Tope duro: 8 archivos por llamada.** No inundamos el chat.
- `location` está **gateado**: solo responde 200 si hay un handoff abierto para esa conversación
  sobre ese `codigo`. Si no, **409**. En v1 no existe "visita confirmada" porque no hay agenda.
- Si el gateway activo no soporta la capacidad → **501**, no un 200 mentiroso.

### 3.3 Leads y handoff

```http
POST /api/v1/whatsapp/conversations/{id}/solicitud
     { "nombre", "operacion", "tipo", "zonas": [], "presupuesto", "moneda",
       "dormitorios_min", "temperatura": "caliente|tibio|frio", "codigos_interes": [], "notas" }
  → 200 { "lead_id": 41, "referencia": "Solicitud #41" }

POST /api/v1/whatsapp/conversations/{id}/captacion   { … }
POST /api/v1/whatsapp/conversations/{id}/postventa   { "nombre", "detalle", "codigo_o_contrato" }
POST /api/v1/whatsapp/conversations/{id}/telefono    { "telefono": "tal cual lo escribió", "rechazado": false }
GET  /api/v1/whatsapp/conversations/{id}/historial
POST /api/v1/whatsapp/conversations/{id}/handoff     { "codigo": "C21-0002", "reason": "…" }
```

**La referencia que devolvemos cambia según el flujo:** `Solicitud #41`, `Captación #42`,
`Postventa #43`. Tu brief solo definía "Solicitud"; llamarle solicitud a una captación confunde al
propietario, que no pidió nada sino que ofreció algo.

**Handoff (tu §8, respetado):** derivamos al **titular del `codigo`**, nunca a otro. Es
**idempotente**: si ya hay uno abierto devolvemos 200 con el mismo recurso, no creamos otro. Mandá
primero el aviso al cliente y después llamá esto, como decís vos. A partir de ahí:

- el webhook te llega con `handoff.open = true` y no te notificamos más;
- **`POST …/messages` responde 409**, no 200. Un 200 te haría creer que hablaste.

Si nadie toma la conversación en 15 minutos, notificamos a un supervisor. **Nunca reasignamos** el
handoff a otro asesor: rompería la promesa que Sofía acaba de hacer ("le paso con `<nombre>`").

---

## 4. Códigos de respuesta — simétricos en ambas direcciones

| Código | Significado | ¿Reintentar? |
|---|---|---|
| `200` | ok | — |
| `401` | token inválido | no |
| `409` | handoff abierto, o precondición de negocio no cumplida | no |
| `422` | payload irreparable, o fuera de la ventana de 24h | no |
| `501` | el gateway activo no soporta esa capacidad | no |
| `5xx` | fallo transitorio | sí, con backoff |

✅ **Nosotros también lo cumplimos, ya arreglado:** nuestro job reintentaba 3 veces ante cualquier
error, incluido tu 422. Ahora los 4xx se descartan con log y solo los 5xx reintentan con backoff.
Además dejamos de notificarte por completo mientras hay un handoff abierto.

---

## 5. Webhook `message.received` — lo que ya te mandamos

Ya cumplimos ~70% de tu §3: `conversation_id`, `gateway`, `contact{phone,name,person_id,lead_id}`,
`message{id,type,text,timestamp}`, `history[]` (rol/contenido/tipo), `window{open,expires_at}` y
`reply{method,url}`. Todo tolerante a null.

✅ **Ya agregado:** `channel`, `handoff{state,open,asesor}`, `phone_required`,
`phone_prompt_attempts`, `phone_prompt_exhausted`. El payload cumple tu §3 completo.

---

## 6. Lo que necesitamos de vos

1. **Confirmá `conversation_id` en vez de `wa_id` (§1).** Ya está construido así — si lo rechazás,
   decilo ya, porque rehacerlo después de que empieces a cablear las tools cuesta mucho más.
2. **Confirmá que el prompt de Sofía se ajusta a v1 sin agenda (§2.2)** — que no ofrezca horarios.
3. **Decinos qué formato de texto esperás.** Nuestro endpoint acepta texto plano; confirmá que no
   mandás Markdown (WhatsApp lo renderiza literal).
4. **Ventana de dedup y timeouts** que estás usando, para que nuestros reintentos no peleen con tu
   buffer de debounce.
5. Si algún campo del §3 no te alcanza, **pedilo ahora**.

---

## 7. Cosas que no te van a gustar, dichas igual

- **No hay entorno de staging.** Todo se prueba contra `c21.sofopolis.com`, que hoy es entorno de
  prueba pero es la misma instancia que va a producción.
- **El catálogo de inmuebles depende de carga humana.** Habrá un seeder con datos reales para probar,
  pero el volumen real lo cargan los asesores desde la UI. Si en la demo hay 15 inmuebles,
  `buscar_inmuebles` va a devolver poco y Sofía va a parecer limitada. No es un bug.
- **El pipeline de C21 no cierra leads.** Sus etapas terminales son `cierre-exitoso` y
  `desistimiento`, y Krayin detecta el cierre por los códigos literales `won`/`lost`. Está reportado
  del lado de C21 y pendiente de decisión. No te afecta para escribir leads, sí para cualquier
  métrica de conversión que quieras leer después.
- **Hoy hay un solo usuario con rol Asesor**, y se llama **Sofía** — igual que el agente. El mensaje
  de handoff ("le paso con Sofía") sería incomprensible. Se renombra antes de la demo, pero si ves
  ese nombre en una respuesta de la API, es esto.
