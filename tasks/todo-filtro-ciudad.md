# Tareas — Filtro por ciudad en WhatsApp

Spec: [`spec-filtro-pipeline-whatsapp.md`](./spec-filtro-pipeline-whatsapp.md) · Plan: [`plan-filtro-ciudad.md`](./plan-filtro-ciudad.md)

```bash
C=heaven_kolberg_laravel.1.mzxjtk1wly5rxf33hh5c5fq49
docker exec $C sh -lc 'cd /code && su -s /bin/sh www-data -c \
  "HOME=/tmp XDG_CONFIG_HOME=/tmp php artisan test tests/Feature/WhatsappFiltroCiudadTest.php"'
```

## F1 · La expresión de la cascada

- [x] **1.1** Crear `Support/ConversationCity.php`
  - Acceptance: `sql()` devuelve el `COALESCE` de las tres fuentes con prefijo `kl_` resuelto vía `DB::getTablePrefix()`; el id del atributo `cliente_ciudad` se busca por code, no hardcodeado
  - Verify: test 1.2
  - Files: `packages/Webkul/Whatsapp/src/Support/ConversationCity.php`

- [x] **1.2** Tests de la cascada
  - Acceptance: una fuente por test (handoff / atributo / lead), más la precedencia con los tres distintos; y que el reparto de las 40 reales coincida con lo medido
  - Verify: `--filter=cascada`
  - Files: `tests/Feature/WhatsappFiltroCiudadTest.php`

## F2 · El filtro

- [x] **2.1** Parámetro `city` en `ChatController::conversations()`
  - Acceptance: `city=<id>` filtra esa ciudad; `city=none` trae las sin ciudad **y** las del pipeline llamado "Sin ciudad"; ausente = lista de hoy. Se aplica después de los filtros existentes
  - Verify: tests 2.2
  - Files: `Http/Controllers/ChatController.php`

- [x] **2.2** Tests del filtro
  - Acceptance: `city=3` → sólo Santa Cruz; `city=none` → sólo sin ciudad; sin parámetro → mismo total que hoy; **y combinado con `stage` y `search` sin pisarse**
  - Verify: `--filter=filtro`
  - Files: `tests/Feature/WhatsappFiltroCiudadTest.php`

## F3 · Conteos para el desplegable

- [x] **3.1** Endpoint `cities()` + ruta `admin.whatsapp.cities`
  - Acceptance: `[{id, name, count}]` ordenado por conteo desc, más `none`; ciudades con 0 no aparecen; **usa la misma deduplicación `ROW_NUMBER` que la lista** (R1)
  - Verify: test 3.2
  - Files: `Http/Controllers/ChatController.php`, `Routes/admin.php`

- [x] **3.2** Test de conteos
  - Acceptance: la suma de todos los `count` (incluido `none`) es igual al total que devuelve la lista sin filtro
  - Verify: `--filter=conteo`
  - Files: `tests/Feature/WhatsappFiltroCiudadTest.php`

## F4 · El chip en la fila

- [x] **4.1** Campo `city` por fila
  - Acceptance: `selectSub` con la expresión + nombre desde el `pluck` de `lead_pipelines` que ya existe; `null` cuando no hay ciudad; **sin consultas nuevas**
  - Verify: test 4.2
  - Files: `Http/Controllers/ChatController.php`

- [x] **4.2** Test de la fila y del N+1
  - Acceptance: cada fila trae `city`; con `DB::listen`, la lista hace el mismo número de consultas con filtro y sin él
  - Verify: `--filter="fila\|consultas"`
  - Files: `tests/Feature/WhatsappFiltroCiudadTest.php`

## F5 · El desplegable

- [ ] **5.1** `<select>` de ciudad en `chat.blade.php`
  - Acceptance: arriba de los chips de etapa, con conteo al lado de cada opción y "Sin ciudad" separada al final; **sólo clases estándar de Tailwind** (el CSS es del 28-08); recarga la lista al cambiar
  - Verify: browser real, consola abierta
  - Files: `Resources/views/chat.blade.php`

- [ ] **5.2** Chip de ciudad en la fila del front
  - Acceptance: junto al chip de etapa, se oculta si `city` es null
  - Verify: browser real
  - Files: `Resources/views/chat.blade.php`

- [x] **5.3** Test del blade
  - Acceptance: el select existe, la url de ciudades se pasa al componente, y los guards del componente siguen pasando (llaves dobles, php en el js, iconos)
  - Verify: `--filter=blade` + `WhatsappComposerActionsTest`
  - Files: `tests/Feature/WhatsappFiltroCiudadTest.php`

## Cierre

- [x] **6.1** Suite completa sin fallos nuevos (línea base: 9)
  - Verify: suite completa **como www-data**
- [x] **6.2** Pint archivo por archivo
  - Verify: `PASS` en cada uno
