# Plan: endpoint magro de sucursales para el agente

Spec: `tasks/spec-sucursales-agente.md` (aprobado el 16/10/2026).

**Estado (16/10/2026):** T1–T4 hechas. T2 y T3 se hicieron juntas (a pedido del usuario).
33 tests propios en verde; respuesta real de las 7 sucursales verificada contra producción.
Suite completa: 397 ok, 11 skipped, 3 fallos **ajenos** a este cambio: 2 de
`DashboardTiempoRespuestaCardTest` (trabajo del tablero sin commitear, previo a esta feature) y
1 de `DoctorCalendarMonthViewTest`, que pasa al correrlo solo (colisión de datos en la base viva).
Pendiente: OK del usuario y aviso al equipo del agente.

## Lo que ya existe y se reutiliza

| Pieza | Dónde | Para qué |
|---|---|---|
| Grupo `throttle:agent-api` + `auth:sanctum` | `packages/Webkul/Doctor/src/Routes/api.php` | Mismo bucket y auth que `productos/por-ciudad-sucursal`, que el agente ya usa |
| `WarehouseRepository` | `packages/Webkul/Warehouse/src/Repositories` | Acceso a datos (patrón repositorio del proyecto) |
| `lead_pipelines` (pipeline = ciudad) | DB | Fuente del nombre canónico de la ciudad |
| `Str::ascii()` + `mb_strtolower` | Laravel | Normalizar nombres ("Potosí" → "potosi") |
| Patrón de test con `DatabaseTransactions` | `tests/Feature/ProductosByCiudadSucursalTest.php` | Aislar tests contra la base de producción |

## Orden y dependencias

```
T1 parser (puro, sin DB) ──┐
                           ├─→ T3 controlador + ruta ─→ T4 verificación contra producción
T2 repositorio ────────────┘
```

T1 y T2 son independientes entre sí; T3 necesita las dos.

## Riesgos

| Riesgo | Mitigación |
|---|---|
| El texto libre cambia de forma (como hoy en Cochabamba) y el parser clasifica mal una línea | Tests con los 7 textos reales; ante la duda una línea va a `direccion` (no se pierde información) y el log de T4 muestra el resultado de las 7 |
| Una dirección con números se confunde con un horario ("Calle 15 de Abril #275") | La detección de hora exige formato de hora (`9:00`, `9hrs`, `7 a. m.`, `3pm`), no cualquier número; caso cubierto en tests |
| Dos sucursales emparejan con la misma ciudad | Se devuelven las dos (el contrato es una lista) |
| Un pipeline nuevo o renombrado | Se empareja por nombre en cada consulta, sin ids fijos |

## Tareas

- [x] **T1 — `SucursalTextoParser`** (regla 7 de la spec)
  - Acceptance: `parse(?string $texto): array{direccion, horarios, mapa_url}`; los 7 textos
    reales dan el resultado esperado; casos borde de la spec cubiertos.
  - Verify: `php artisan test tests/Unit/SucursalTextoParserTest.php`
  - Files: `packages/Webkul/Warehouse/src/Services/SucursalTextoParser.php`,
    `tests/Unit/SucursalTextoParserTest.php`

- [x] **T2 — `WarehouseRepository::sucursalesParaAgente(?string $ciudad)`** (reglas 1–6, 8)
  - Acceptance: empareja sucursal ↔ pipeline por nombre normalizado (name o
    `contact_address.city`); descarta las que no emparejan; filtra por ciudad si viene;
    arma los 5 campos usando el parser; teléfono con `+591 `.
  - Verify: cubierto por los tests de T3.
  - Files: `packages/Webkul/Warehouse/src/Repositories/WarehouseRepository.php`

- [x] **T3 — `SucursalController` + ruta `GET api/v1/kohlberg/sucursales`**
  - Acceptance: 401 sin token; 200 con `{"sucursales": [...]}`; anotación `@OA\Get` para
    Swagger; casos del feature test de la spec en verde.
  - Verify: `php artisan test tests/Feature/SucursalesAgenteTest.php`; `php artisan route:list --path=api/v1/kohlberg`
  - Files: `packages/Webkul/Doctor/src/Http/Controllers/Api/SucursalController.php`,
    `packages/Webkul/Doctor/src/Routes/api.php`, `tests/Feature/SucursalesAgenteTest.php`

- [x] **T4 — Verificación contra producción y cierre**
  - Acceptance: criterios de éxito 1–8 de la spec; salida real de las 7 sucursales revisada
    por el usuario; `git diff --stat` sin vistas, migraciones ni `vendor/`.
  - Verify: llamada con tinker/HTTP dentro del contenedor; suite completa;
    `./vendor/bin/pint` solo sobre los 5 archivos tocados.
  - Files: ninguno nuevo.

## Checkpoints

1. Tras T1: mostrar al usuario cómo quedan separadas las 7 sucursales reales, antes de
   seguir.
2. Tras T4: OK del usuario sobre la respuesta real antes de avisar al equipo del agente.
