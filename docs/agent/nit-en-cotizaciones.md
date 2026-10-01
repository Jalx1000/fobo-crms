# NIT: campo nuevo en Personas y Cotizaciones

**Para:** el equipo del agente (Python · LangChain)
**De:** el lado CRM
**Fecha:** 2026-09-09
**Depende de:** `docs/agent/respuesta-interactivo-handoff-y-tools.md` (punto 2)

---

## Por qué

El PASO 4 del flujo pide **razón social, NIT, correo y dirección**, y el cierre
repite: *"para emitir la cotización formal necesito razón social y NIT"*.

De esos cuatro datos, tres ya tenían dónde ir. **El NIT no tenía ningún campo en
el CRM.** Terminaba apelotonado como texto libre dentro de la descripción de la
cotización — donde nadie lo puede filtrar, exportar ni usar para facturar. Se ve
en la única cotización que el agente llegó a crear:

```
"Cotización de 5.000 bolsas doypack para café, 20x30 cm, 300 gr, sin arte, cliente Pepe."
```

En Bolivia el NIT es **el** dato para emitir factura. Si el agente lo captura y
lo deja ahí, el asesor tiene que leer la conversación entera para encontrarlo —
que es exactamente lo que el PASO 4 existía para evitar.

---

## Qué se creó

Dos atributos personalizados, los dos `text` y **no obligatorios**:

| Entidad | `code` | id | Dónde se ve |
|---|---|---|---|
| `persons` | `nit` | 86 | ficha del contacto y alta rápida |
| `quotes` | `nit` | 87 | formulario de cotización |

**No obligatorios a propósito:** la mayoría de los contactos entran por WhatsApp
sin NIT, y muchas cotizaciones internas tampoco lo llevan. Obligatorio rompería
la creación automática de contactos y el `register_cotizacion` del agente.

### Por qué la cotización guarda su propia copia

No es duplicación por descuido. Una cotización es un **documento que ya se
emitió**: si leyera el NIT de la persona en vivo, corregir un NIT hoy cambiaría
retroactivamente todas las cotizaciones del año pasado, que dejarían de coincidir
con el PDF que el cliente tiene guardado.

---

## El CRM los sincroniza solo

Un listener corre en `quote.create.after` y `quote.update.after`:

| Persona | Cotización | Qué hace el CRM |
|---|---|---|
| tiene | vacía | la cotización **hereda** el NIT de la persona |
| vacía | tiene | la persona **aprende** el NIT de la cotización |
| iguales | | nada |
| **distintos** | | **no toca ninguno de los dos** |

El último caso es deliberado: pisar el de la persona propagaría un error de tipeo
a todas las cotizaciones futuras, y pisar el de la cotización cambiaría un
documento ya emitido. Que queden distintos es visible y corregible a mano; que se
pisen en silencio, no.

> El listener está enganchado al **evento**, no al controlador del CRM. La API
> REST dispara los mismos eventos, así que `register_cotizacion` pasa por
> exactamente el mismo camino que un asesor cargando a mano. Hay un test que lo
> prueba por HTTP.

---

## Qué tiene que hacer el agente

**Mandar `nit` en el POST.** Nada más. La sincronización es del CRM.

Basta con mandarlo en **uno** de los dos lados: el listener se encarga del otro.

### Al crear/actualizar el contacto (PASO 4)

```bash
curl -sS -X POST "$BASE/api/v1/contacts/persons" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{
    "entity_type": "persons",
    "name": "Comercial Los Andes SRL",
    "emails": [{"value": "compras@losandes.bo", "label": "work"}],
    "contact_numbers": [{"value": "59177132734", "label": "work"}],
    "nit": "1234567019"
  }'
```

`contact_numbers` es obligatorio en este endpoint: sin él la creación falla.

### Al crear la cotización (`register_cotizacion`)

```bash
curl -sS -X POST "$BASE/api/v1/quotes" \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{
    "entity_type": "quotes",
    "subject": "Cotización WhatsApp — Bolsas Magia Verde",
    "person_id": 936,
    "user_id": 1,
    "expired_at": "2026-09-16",
    "sub_total": 0,
    "grand_total": 0,
    "items": [],
    "nit": "1234567019"
  }'
```

Si la persona ya tenía NIT cargado, **podés omitirlo**: la cotización lo hereda
sola.

### Lo que NO hay que hacer

- ❌ Meter el NIT en `description` como texto libre. Ese era el problema.
- ❌ Inventar o normalizar el NIT. Guardalo tal cual lo dictó el cliente: se
  escribe con y sin guiones ni complementos, y "corregirlo" lo rompe.
- ❌ Bloquear el PASO 5 si el cliente no lo da. El campo no es obligatorio;
  seguí y derivá igual.

---

## ⚠️ La respuesta de la API NO devuelve el `nit`

Verificado: el valor **se guarda bien**, pero ni `PersonResource` ni
`QuoteResource` exponen atributos personalizados. La respuesta trae solo un set
fijo de campos.

```
POST /api/v1/contacts/persons  → data: id, name, emails, contact_numbers, job_title, …
POST /api/v1/quotes            → data: id, subject, person, user, sub_total, …
GET  /api/v1/quotes/{id}       → idem
```

**En los cuatro casos `nit` viene `null`, y el dato SÍ está en la base.**

No lo tomes como que falló. Si necesitás leerlo de vuelta —hoy no deberías: lo
escribe el agente y lo lee un humano en el CRM— decímelo y armo un endpoint que
lo exponga. Parchear el recurso de Krayin no es opción: está en `vendor/`.

---

## Cómo verificarlo

1. Crear un contacto por API con `nit`.
2. Crear una cotización para ese contacto **sin** mandar `nit`.
3. Abrir la cotización en el CRM → **el campo NIT tiene que estar lleno**
   (lo heredó de la persona).
4. Al revés: contacto sin NIT, cotización **con** NIT → abrir la ficha del
   contacto en el CRM y ver que ahora lo tiene.

La verificación es **en la pantalla del CRM**, no en la respuesta de la API, por
lo del apartado anterior.

---

## Bug de Krayin corregido en el camino

Al cargar el mismo código de atributo (`nit`) en dos entidades apareció un
problema latente: `AttributeRepository::getAttributeByCode($code)` buscaba **solo
por código**, ignorando la entidad, y cacheaba por código. Con `nit` en `persons`
(86) y en `quotes` (87) devolvía siempre el 86 — así que la cotización buscaba en
sus valores el id del atributo de personas y devolvía `null` para un dato que
estaba correctamente guardado.

Ya está arreglado (la búsqueda recibe la entidad y la caché se indexa por
`entidad.código`). Lo menciono porque explica por qué esto no funcionó a la
primera, y porque el mismo problema iba a aparecer con cualquier código repetido
—"ciudad", "dirección"— en el futuro.

---

## Cambio sugerido para el system prompt

En el PASO 4, después de pedir los datos:

```
Guardá el NIT tal como te lo dicte el cliente, sin corregirlo ni quitarle
guiones. Si no lo da, seguí igual: no es obligatorio y no debe frenar la
derivación.
```
