# Endpoint de sucursales para el agente (Sofía)

Para: equipo técnico del agente IA · De: equipo del CRM Kohlberg · 16/10/2026
Responde al pedido "Endpoint de sucursales magro para el agente".

**Estado:** activo en producción (`https://kohlberg.sofopolis.com`).

## Resumen

- Nuevo endpoint `GET /api/v1/kohlberg/sucursales?ciudad=<ciudad>` con solo 5 campos:
  `ciudad`, `direccion`, `horarios`, `mapa_url`, `telefono`.
- Reemplaza el uso de `GET /api/v1/settings/warehouses` **para el mensaje de retiro**. Ese
  endpoint sigue existiendo y no cambió.
- Los datos son los que el equipo de Kohlberg carga en el CRM. El endpoint no inventa ni
  completa nada: si un dato no está cargado, llega `null`.

## Request

```
GET https://kohlberg.sofopolis.com/api/v1/kohlberg/sucursales?ciudad=Cochabamba
Authorization: Bearer <token Sanctum del agente>
Accept: application/json
```

| | |
|---|---|
| Auth | El mismo token Sanctum que ya usan (`auth:sanctum`) |
| Límite | Bucket `agent-api`: 600 req/min por token (el mismo de los demás endpoints del agente) |
| `ciudad` | Opcional, **recomendado siempre**. No distingue mayúsculas, tildes ni espacios: `potosi`, `Potosí` y ` POTOSÍ ` dan lo mismo. Sin el parámetro devuelve todas las sucursales |

Qué mandar en `ciudad`: el nombre de la ciudad del pedido (pipeline del lead): Tarija,
Santa Cruz, Potosí, Oruro, La Paz, Cochabamba, Sucre.

## Response

Siempre `200` con la forma `{"sucursales": [...]}`.

```json
{
  "sucursales": [
    {
      "ciudad": "Cochabamba",
      "direccion": "NUESTRAS OFICINAS EN LA CIUDAD DE COCHABAMBA. Calle Mayor Rocha No 460 entre Jose Marti y Tarapaca.",
      "horarios": "Lunes a Vienes de 9:00hrs a 17:00hrs · Sábado de 9:00hrs a 13:00hrs",
      "mapa_url": "https://maps.app.goo.gl/S9ACh6uam7nXdmgn6",
      "telefono": "+591 67400062"
    }
  ]
}
```

Ciudad sin sucursal (`Sin ciudad`, una ciudad que no existe):

```json
{ "sucursales": [] }
```

### Campos

| Campo | Tipo | Notas |
|---|---|---|
| `ciudad` | string | Nombre de la ciudad tal como está en el CRM ("Potosí", con tilde). Nunca null |
| `direccion` | string \| null | Texto tal como lo cargó el equipo, en una línea |
| `horarios` | string \| null | Texto listo para mostrar. Los tramos van separados por ` · ` |
| `mapa_url` | string \| null | Solo enlaces `https` de Google Maps (`maps.app.goo.gl`, `goo.gl/maps`, `google.com/maps`, `maps.google.com`) |
| `telefono` | string \| null | Formato `+591 XXXXXXXX` |

Una sucursal incompleta **no se omite**: llega con el campo faltante en `null`.

### Errores

| Código | Cuándo | Qué hace el agente |
|---|---|---|
| `401` | Token ausente o inválido | No manda dirección; asesor coordina |
| `429` | Superó el límite por minuto | No manda dirección; asesor coordina |
| `5xx` / timeout | Falla del CRM | No manda dirección; asesor coordina |

## Reglas para el agente (lo importante)

Estas reglas son las que evitan el caso 59170720708: durante casi un mes (22/09 → 16/10)
la sucursal Cochabamba no tuvo calle cargada y el agente mandó una dirección inventada.

| Situación | Qué hace Sofía |
|---|---|
| `sucursales` vacío | No manda dirección. Dice que un asesor coordina el retiro |
| `direccion` es `null` | Igual: no manda dirección, un asesor coordina |
| `horarios` es `null` | Manda la dirección sin la línea de horarios. **No supone horarios** |
| `mapa_url` es `null` | Manda la dirección sin enlace. **No arma un enlace de Maps a partir de la dirección** |
| `telefono` es `null` | Omite la línea del teléfono |
| Más de una sucursal | Las muestra todas o pregunta cuál le queda mejor (hoy hay una por ciudad) |
| El lead no tiene ciudad ("Sin ciudad") | Pregunta primero en qué ciudad retira; después consulta |
| Error HTTP o timeout | No manda dirección, un asesor coordina. **No usa otra fuente** (ni `/settings/warehouses`, ni memoria, ni datos de conversaciones anteriores) |

Regla general: **todo dato de sucursal que llega al cliente sale de esta respuesta, en el
mismo turno**. Si no está en la respuesta, no se dice.

### Texto sugerido para el prompt

```
Para dar datos de retiro, llama a GET /api/v1/kohlberg/sucursales con la ciudad del pedido.
Usa SOLO los campos de esa respuesta, copiados tal cual. Nunca inventes, completes ni
corrijas una dirección, un horario, un enlace o un teléfono.
- Si "sucursales" está vacío, si "direccion" es null o si la llamada falla: no des
  dirección; di que un asesor le escribe para coordinar el retiro.
- Si "horarios", "mapa_url" o "telefono" son null, omite esa línea.
```

### Plantilla sugerida del mensaje

Cada línea se incluye solo si su campo no es `null`:

```
📍 Retiro en {ciudad}
{direccion}
🕘 {horarios}
🗺️ {mapa_url}
📞 {telefono}
```

## Datos actuales (16/10/2026)

| Ciudad | Dirección | Horarios | Mapa | Teléfono |
|---|---|---|---|---|
| Cochabamba | ✓ | ✓ | ✓ | ✓ |
| La Paz | ✓ | ✓ | ✓ | ✓ |
| Oruro | ✓ | **null** | ✓ | ✓ |
| Potosí | ✓ | ✓ | ✓ | ✓ |
| Santa Cruz | ✓ | ✓ | ✓ | ✓ |
| Sucre | ✓ | ✓ | ✓ | ✓ |
| Tarija | ✓ | ✓ | ✓ | ✓ |

Que tengan en cuenta:

- Los textos salen como los escribió el equipo, con sus erratas (Cochabamba dice "Vienes" y
  su dirección empieza con "NUESTRAS OFICINAS EN LA CIUDAD DE COCHABAMBA."). Se corrigen en el
  CRM, no en el agente.
- **Sin caché**: un cambio en el CRM se ve en la siguiente llamada. No guarden la respuesta
  entre conversaciones; consulten en cada turno que la necesite.
- La sucursal "Todas" del CRM no se devuelve nunca (no es un lugar físico).

## Cómo probar

```bash
TOKEN=...   # el token del agente
BASE=https://kohlberg.sofopolis.com/api/v1/kohlberg/sucursales

H=(-H "Authorization: Bearer $TOKEN" -H "Accept: application/json")

curl -s "${H[@]}" "$BASE?ciudad=Cochabamba"     # completa
curl -s "${H[@]}" "$BASE?ciudad=Oruro"          # "horarios": null
curl -s "${H[@]}" "$BASE?ciudad=Sin%20ciudad"   # {"sucursales":[]}
curl -s "${H[@]}" "$BASE?ciudad=potosi"         # "ciudad": "Potosí"
curl -s -H "Accept: application/json" "$BASE"   # 401 sin token
```

Casos a cubrir del lado del agente: Cochabamba (completa), Oruro (`horarios: null`),
`Sin ciudad` (vacío) y un token inválido (401).

## Checklist del equipo del agente

- [ ] Cambiar la herramienta de sucursales a `GET /api/v1/kohlberg/sucursales?ciudad=`.
- [ ] Mandar siempre `ciudad` con la ciudad del pedido.
- [ ] Implementar la tabla de reglas de arriba (vacío / `null` / error).
- [ ] Quitar del prompt cualquier dirección, horario o enlace fijo de sucursales.
- [ ] Probar los 4 casos de "Cómo probar".
- [ ] Confirmar al equipo del CRM cuándo queda en producción del lado del agente.

## Referencia

- Swagger: operación `getSucursalesAgente`, tag "Sucursales".
- Spec: `tasks/spec-sucursales-agente.md`.
