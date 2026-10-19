# Spec: endpoint magro de sucursales para el agente (Sofía)

Estado: **aprobado** (16/10/2026). Origen: pedido del equipo del agente IA ("Endpoint de
sucursales magro para el agente").

## Objective

Sofía arma el mensaje de "dónde recoger" con dirección, horarios, enlace de mapa y teléfono
de la sucursal. Hoy consume `GET /api/v1/settings/warehouses` (paquete `krayin/rest-api`),
que devuelve metadata interna y **no trae esos datos de forma usable**: en producción la
dirección, los horarios y el enlace de mapa están mezclados en un solo texto libre
(`warehouses.contact_address.address`), en distinto orden según la sucursal.

Construimos **un solo endpoint de lectura**, `GET /api/v1/kohlberg/sucursales?ciudad=`, que
lee las sucursales tal como están cargadas y devuelve solo los 5 campos que usa el agente.

**Fuera de alcance (decisión del usuario):** nada cambia en el CRM a nivel UI/UX. Ni campos
nuevos, ni formularios, ni migraciones, ni comandos que reescriban datos. El equipo sigue
cargando las sucursales como hoy.

### Por qué (evidencia de producción)

Historial de la sucursal Cochabamba (`warehouse_activities`):

| Fecha | Dirección cargada |
|---|---|
| hasta 12/08 | Calle A. Urquidi … Nro. 2275 |
| 12/08 → 22/09 | Calle mayor rocha Nro 364 … |
| **22/09 → 16/10 13:50** | **sin calle** (solo horarios + mapa) |
| 16/10 13:55 | `"C\r\nalle Mayor Rocha No 460 …"` (salto de línea roto) |

Durante casi un mes la sucursal no tuvo calle cargada: el agente no tenía nada real que
mandar y la inventó (caso 59170720708). La regla 6 le da al agente una señal explícita
(`direccion: null`) para no inventar.

### Contrato

```
GET /api/v1/kohlberg/sucursales?ciudad=Cochabamba
Authorization: Bearer <token sanctum>

200
{
  "sucursales": [
    {
      "ciudad": "Cochabamba",
      "direccion": "Calle Mayor Rocha No 460 entre Jose Marti y Tarapaca.",
      "horarios": "Lunes a Vienes de 9:00hrs a 17:00hrs · Sábado de 9:00hrs a 13:00hrs",
      "mapa_url": "https://maps.app.goo.gl/S9ACh6uam7nXdmgn6",
      "telefono": "+591 67400062"
    }
  ]
}
```

Los textos salen **tal como los escribió el equipo** (incluidas sus erratas, ej. "Vienes"):
el endpoint separa y limpia formato, no reescribe contenido.

### Reglas

1. **Solo 5 campos** por sucursal: `ciudad`, `direccion`, `horarios`, `mapa_url`, `telefono`.
   Sin ids, timestamps ni objetos anidados.
2. **Una sucursal es una ciudad del CRM.** Cada sucursal se empareja con su pipeline
   (pipeline = ciudad) comparando nombres normalizados: sin mayúsculas, tildes ni espacios
   sobrantes ("Potosi" ↔ "Potosí"). Se prueba contra `warehouses.name` y contra
   `contact_address.city`. **Las sucursales que no emparejan con ningún pipeline no se
   devuelven nunca**: así queda fuera "Todas" (id 10), que es un comodín del catálogo de
   productos (`ciudad_producto_sucursal`) y no un lugar físico, sin hardcodear su id.
3. **Títulos normalizados:** `ciudad` en la respuesta es **el nombre del pipeline**
   ("Potosí", "Santa Cruz"), el mismo que el agente ve en el lead. La base no cambia.
4. **`?ciudad=` es opcional pero recomendado.** Con el parámetro, devuelve solo la(s)
   sucursal(es) de esa ciudad (misma normalización: `potosi`, `Potosí` y ` POTOSÍ `
   resuelven igual). Sin el parámetro, o vacío, devuelve todas las sucursales emparejadas.
5. **Ciudad sin sucursal → `{"sucursales": []}`**, con 200. Nunca 404: para el agente "no
   hay sucursal" es un resultado válido, no un error. ("Sin ciudad" y ciudades inexistentes
   caen acá.)
6. **Una sucursal con campos vacíos no se omite**: se devuelve con ese campo en `null`.
   **Contrato para el agente:** si `direccion` es `null`, no manda dirección y dice que un
   asesor coordina el retiro.
7. **Separación del texto libre** (`contact_address.address`), línea por línea, ignorando
   líneas vacías:
   - **`mapa_url`**: la primera URL `https` de Google Maps (`maps.app.goo.gl`, `goo.gl/maps`,
     `google.com/maps`, `maps.google.com`), sin tocar sus parámetros. Si no hay, `null`.
     Cualquier URL (de Maps o no) se quita del resto del texto.
   - **Encabezado** "Horarios de atención" (con o sin `:`) se descarta.
   - **`horarios`**: las líneas que nombran un día (lunes…domingo, lun, sáb…) o una hora
     (`9:00`, `9:00hrs`, `7 a. m.`, `3:00pm`). Una línea con solo días seguida de una con
     solo horas se unen con un espacio ("Lunes a viernes" + "7 a. m.–3 p. m." →
     "Lunes a viernes 7 a. m.–3 p. m."). Los tramos se unen con ` · `. Si no hay, `null`.
   - **`direccion`**: todo lo que queda, unido con un espacio. Si no queda nada, `null`.
8. **`telefono`**: el primer valor no vacío de `contact_numbers`, con prefijo `+591 ` si no
   lo trae; `null` si no hay.
9. **Solo lectura**: el endpoint no escribe nada en la base.
10. El endpoint actual `/api/v1/settings/warehouses` **no cambia**.

### Decisiones (usuario, 16/10/2026)

- Nada de UI/UX en el CRM: solo el endpoint.
- `?ciudad=` opcional, recomendado (regla 4).
- Títulos normalizados al nombre del pipeline (regla 3).
- Sucursal incompleta se devuelve con `null`, no se omite (regla 6).
- Calidad de datos (Oruro sin horarios, Cochabamba con "C / alle", la línea "NUESTRAS
  OFICINAS EN LA CIUDAD DE COCHABAMBA." que va a salir dentro de `direccion`): **la revisa
  el usuario** en el CRM. El endpoint no corrige contenido.

### Supuestos

1. El endpoint vive junto a los demás endpoints de catálogo del agente
   (`packages/Webkul/Doctor/src/Routes/api.php`, mismo grupo que
   `productos/por-ciudad-sucursal`), con `throttle:agent-api` + `auth:sanctum`.
2. Separar el texto en cada consulta es barato (8 filas) y no necesita caché.

## Tech Stack

Laravel 10, módulos Concord en `packages/Webkul/`, Sanctum, Pest. Sin dependencias nuevas,
sin migraciones.

## Commands

```bash
# El contenedor es una tarea de swarm: su nombre lleva sufijo
C=$(docker ps --format '{{.Names}}' | grep heaven_kolberg_laravel | head -1)
# Tests de la feature (contenedor: la DB solo resuelve ahí)
docker exec -w /code $C php artisan test tests/Feature/SucursalesAgenteTest.php tests/Unit/SucursalTextoParserTest.php
# Suite completa (en verde desde 26/09: un fallo nuevo es propio)
docker exec -w /code $C php artisan test
# Estilo: SOLO sobre los archivos tocados, nunca sobre directorios
./vendor/bin/pint <archivo1> <archivo2> ...
```

## Project Structure

```
packages/Webkul/Warehouse/src/
  Services/SucursalTextoParser.php          → separa address en direccion/horarios/mapa_url (regla 7)
  Repositories/WarehouseRepository.php      → método sucursalesParaAgente(?string $ciudad)
packages/Webkul/Doctor/src/
  Http/Controllers/Api/SucursalController.php → lee ?ciudad= y responde; @OA Swagger
  Routes/api.php                               → GET api/v1/kohlberg/sucursales
tests/Unit/SucursalTextoParserTest.php        → los 7 textos reales como fixtures
tests/Feature/SucursalesAgenteTest.php        → endpoint completo
```

## Code Style

Igual que `ProductoController` / `CitySyncService`: controlador delgado, consulta en el
repositorio, comentarios en español explicando el porqué, alineación de `=>` de Pint.

```php
public function index(Request $request): JsonResponse
{
    // Opcional: sin ciudad devuelve todas las sucursales emparejadas con un pipeline.
    $ciudad = trim((string) $request->query('ciudad', '')) ?: null;

    return response()->json([
        'sucursales' => $this->warehouseRepository->sucursalesParaAgente($ciudad),
    ]);
}
```

## Testing Strategy

- **Unit (`SucursalTextoParserTest`)**: el parser es una clase pura (texto → 3 campos), sin
  DB. Fixtures = los 7 textos reales de producción copiados al test (16/10/2026), cada uno
  con su resultado esperado; más casos borde: texto vacío, solo URL, URL que no es de Maps,
  sin horarios (Oruro), dirección antes de horarios (Sucre).
- **Feature (`SucursalesAgenteTest`)**: `DatabaseTransactions` (la suite corre contra
  producción; nada de `RefreshDatabase`). Crea sus propias sucursales y pipelines con
  nombres únicos y nunca afirma totales de toda la tabla. Casos:
  - 401 sin token.
  - Exactamente las 5 claves, sin ids ni timestamps.
  - Coincide sin tildes/mayúsculas; coincide por `contact_address.city`.
  - `ciudad` = nombre del pipeline.
  - Sin `ciudad` → incluye las del test; nunca una sucursal sin pipeline.
  - Ciudad inexistente → `{"sucursales": []}`.
  - Sucursal sin dirección → `direccion: null`; teléfono sin prefijo → `+591 …`.
  - El endpoint no modifica `warehouses` ni `attribute_values`.

## Boundaries

- **Always:** tests dentro de transacción; Pint solo sobre archivos tocados; emparejar por
  nombre normalizado, nunca por id fijo.
- **Ask first:** cualquier cambio a `/api/v1/settings/warehouses`, a `vendor/` o a la base.
- **Never:** tocar vistas, formularios, atributos o migraciones del CRM; editar
  `vendor/krayin/rest-api`; inventar, completar o corregir el contenido de las direcciones.

## Success Criteria

1. `GET /api/v1/kohlberg/sucursales?ciudad=Cochabamba` devuelve 1 sucursal con los 5 campos
   y datos reales del CRM.
2. `?ciudad=Sin ciudad` y `?ciudad=Narnia` devuelven `{"sucursales": []}`.
3. `?ciudad=potosi` devuelve la sucursal con `"ciudad": "Potosí"`.
4. Sin `?ciudad=` devuelve las 7 sucursales; ninguna respuesta incluye "Todas".
5. Una sucursal sin dirección aparece con `"direccion": null`.
6. Las 7 sucursales reales salen con `mapa_url` real y sin URL dentro de `direccion` ni
   `horarios`.
7. `git diff` no toca vistas, migraciones ni `vendor/`.
8. Suite completa en verde.

## Open Questions

Ninguna.
