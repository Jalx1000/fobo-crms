# Menús tocables sin tools nuevas — solo numerá las opciones

> Para el equipo del agente.
> **No hay que implementar nada del lado del agente. El cambio es una frase en el prompt.**
> Ya está activo en el CRM (`poncedeleon.sofopolis.com`), verificado con 22 comprobaciones.

Esto **no reemplaza** el campo `interactive` de
[`interactivos-whatsapp-respuesta-al-agente.md`](./interactivos-whatsapp-respuesta-al-agente.md).
Las dos vías conviven; §5 explica cuándo conviene cada una.

---

## 1. Qué cambia para ustedes

Escriban las opciones numeradas con el marcador `.-)` y el CRM las convierte en botones tocables:

```
¿Desde qué ciudad nos escribís?

1.-) Santa Cruz
2.-) Cochabamba
3.-) La Paz
```

Al cliente le llegan tres botones. El `text` sigue siendo un `text` normal en el endpoint de
siempre — no hay campo nuevo, no hay tool nueva, no hay formato de Meta que construir.

**La frase para el prompt**, aproximadamente:

> Cuando ofrezcas opciones para que el cliente elija, numerálas una por línea con el formato
> `1.-) Opción`, precedidas de la pregunta. Máximo 10 opciones, hasta 24 caracteres cada una.
> No uses ese formato para enumeraciones informativas (listados de pedidos, precios, pasos).

---

## 2. Por qué el marcador es `.-)` y no `1.` a secas

Esto se midió sobre el historial real de producción, no es una preferencia estética.

De los 4 mensajes con lista numerada que había, **2 eran menús** («¿desde qué ciudad nos escribís?
1. Santa Cruz…») y **2 eran enumeraciones informativas** («tenés 4 pedidos: 1. Pedido de 2 Promo
Varietales, total Bs 478…»).

Detectar `1.` da un **50 % de falsos positivos**. Convertir una enumeración informativa en botones
le pide al cliente que elija un pedido que nadie le ofreció, y cada ítem de esos tiene 65
caracteres contra un límite de 24 por fila.

`.-)` no aparece ni una vez en 457 mensajes reales, entrantes y salientes.

**Consecuencia práctica:** pueden seguir usando `1.`, `2.` con total libertad para enumerar cosas.
El CRM no las toca. Solo el marcador dispara la conversión.

---

## 3. Qué decide el CRM, y qué no van a poder controlar

| Decisión | Quién |
|---|---|
| Botones o lista | **CRM** — botones si entran ≤3 y ningún título pasa de 20 car.; lista si no |
| Los `id` de cada opción | **CRM** — genera `opt:1`, `opt:2`, … |
| El texto de los títulos | Ustedes, tal cual lo escriban |
| El enunciado (`body`) | Ustedes — es el texto antes de la primera opción |
| Nota al pie (`footer`) | Ustedes — el texto después de la última opción, si cabe en 60 car. |

**Los ids los genera el CRM a propósito.** En producción el agente alucinó un `ciudad_id: 12`
—un pipeline que no existe— y eso rompió el enrutamiento. Con `opt:N` generado por el CRM, un id
inválido no es representable.

Si necesitan controlar los ids porque enrutan por ellos, usen la vía del campo `interactive`
explícito (§5).

---

## 4. Cuándo degrada, y por qué no les devuelve error

Si el menú no entra en los límites de WhatsApp, **no reciben un 422**. El mensaje sale como texto
numerado legible (`1.-)` reescrito a `1.`) y el motivo queda guardado en el CRM, en
`payload.interactive_skipped`.

Es deliberado: ustedes creen que están mandando texto plano y no saben que existe esta validación.
Un error los dejaría mudos frente al cliente, que es justamente el fallo que esto viene a arreglar.

Casos en los que degrada:

| Situación | Qué pasa |
|---|---|
| Más de 10 opciones | Texto numerado |
| Alguna opción de más de 24 caracteres | Texto numerado |
| No hay texto antes de la primera opción | Texto numerado (WhatsApp exige `body`) |

Casos en los que **no hace nada** y sale como texto normal, sin anotar nada:

- Menos de 2 opciones marcadas (es una viñeta, no un menú).
- Numeración salteada (`1`, `2`, `4`) o que no arranca en 1 — sugiere una cita, una fecha o un
  precio, no una lista de opciones.

---

## 5. Las dos vías: cuál usar

| | Marcador en texto | Campo `interactive` |
|---|---|---|
| Esfuerzo del agente | Una frase de prompt | Construir el payload de Meta |
| Quién define los ids | CRM (`opt:N`) | Ustedes |
| Si excede límites | Degrada a texto | **422** con campo y límite |
| Botones vs lista | Lo decide el CRM | Lo deciden ustedes |
| Header, descripciones de fila | No soportado | Soportado |

**Recomendación:** el marcador para los menús conversacionales corrientes (elegir ciudad, confirmar,
elegir horario) y el campo explícito cuando necesiten ids propios para enrutar, un header, o
descripciones en las filas.

Si vienen los dos en la misma petición, **gana el campo `interactive`** y el `text` se ignora.

---

## 6. La respuesta del cliente les llega igual que siempre

Cuando toca un botón, reciben lo de §4 del documento anterior:

```jsonc
"message": {
  "type": "interactive",
  "text": "Santa Cruz",
  "interactive": { "button_reply": { "id": "opt:1", "title": "Santa Cruz" } }
}
```

El `id` es el que generó el CRM, así que `opt:1` corresponde a la primera opción que escribieron.

---

## 7. Gateways: funciona en los dos, con una limitación

Un mismo menú marcado llega a destino por los dos gateways:

- **WhatsApp Cloud API** — botones o lista nativos, tocables.
- **Kommo salesbot** — texto numerado, porque el salesbot no tiene botones:

  ```
  ¿Desde qué ciudad nos escribís?

  1. Santa Cruz
  2. Cochabamba
  3. La Paz
  ```

  La numeración coincide con el id: `1.` es `opt:1`.

**Limitación conocida, por Kommo:** si el cliente contesta `"2"`, les llega como
`type: "text"` con `text: "2"`, **sin** objeto `interactive` y sin `id`. No hubo botón que tocar, así
que no hay selección que reportar. Por Cloud API esto no ocurre.

Si el agente opera conversaciones de Kommo, convendría que interprete un número suelto como la
opción N del último menú que ofreció. Estamos evaluando resolverlo en el CRM para que les llegue ya
reconciliado — les avisamos si lo hacemos.

---

## 8. Configuración (por si hay que tocarla)

Todo por variables de entorno, sin deploy:

| Variable | Default | Qué hace |
|---|---|---|
| `WHATSAPP_INTERACTIVE_ENABLED` | `true` | Apaga la conversión por completo |
| `WHATSAPP_INTERACTIVE_MARKER` | `.-)` | El marcador |
| `WHATSAPP_INTERACTIVE_LIST_BUTTON` | `Ver opciones` | Label del botón que abre la lista (máx. 20) |

Si apagamos `ENABLED`, los mensajes marcados salen como texto con la numeración reescrita. Nada se
rompe.

---

## 9. Lo que falta probar

Verificamos las conversiones (22 comprobaciones: detección, falsos positivos, degradaciones, límites
del DTO, aplanado a Kommo). **No probamos todavía un menú real llegando a un teléfono y alguien
tocando un botón.** Cuando quieran, coordinamos un número y lo corremos juntos.
