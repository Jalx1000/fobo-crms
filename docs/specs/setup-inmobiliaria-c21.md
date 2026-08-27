# Spec: Configuración inmobiliaria CENTURY 21 (Sofo CRM)

- **Estado:** 🟢 Decisiones cerradas (2026-08-27). Listo para Fase 2 (Plan).
- **Fase:** 1 de 4 (Specify → Plan → Tasks → Implement)
- **Instancia:** `https://c21.sofopolis.com` · Krayin 2.1.5 · BD `kohlberg` (prefijo `kl_`)
- **Reconocimiento:** completado. Ver "Hallazgos que cambian el brief".

---

## Supuestos que estoy haciendo

Corregime ahora o procedo con estos:

1. **El comando se ejecuta manualmente por un operador con acceso a `docker exec`**, no en el
   arranque del contenedor ni en el pipeline de deploy. No lo registro en ningún hook automático.
2. **La instancia es de un solo cliente (CENTURY 21).** No hay multi-tenancy: los datos maestros que
   cree son globales a la instancia.
3. **Los nombres de pipelines, etapas, sources y types del brief son definitivos** y están en el
   idioma final (español, sin i18n). No los paso por `trans()`.
4. **`n8n` consume el CRM vía la REST API con un token Sanctum**, no por acceso directo a la BD.
   Por eso el comando debe emitir el mapa `código → id` de las opciones de select.
5. **El trabajo se entrega en la branch actual** (`imprimir-modulo-whatsapp`) o en una branch nueva
   derivada de ella, no directo a `main`.
6. **"Revertible" significa un comando de rollback**, no una migración `down()`. Los datos maestros
   se crean vía repositorios, no vía migración.

---

## Objetivo

Dejar la instancia de CENTURY 21 configurada para operación inmobiliaria: tres pipelines con sus
etapas, 23 atributos de calificación sobre la entidad `leads`, 9 sources y 5 types.

**Usuarios:**
- **Asesores C21** — cargan y mueven leads por el pipeline desde la UI del CRM.
- **Agente de IA en WhatsApp (vía n8n)** — lee y escribe leads y sus atributos por la REST API.
- **Operador de Sofopolis** — ejecuta el comando de setup y, si hace falta, el de rollback.

**Éxito:** un asesor abre un lead nuevo y ve el formulario de calificación completo, con todos los
select poblados y ningún campo obligatorio nuevo; n8n puede crear un lead en `Venta` y escribir
`bot_temperatura` sin conocer ningún id hardcodeado.

**Fuera de alcance:** los flujos de n8n, el prompt del agente de IA, la integración con portales
inmobiliarios, y la lógica de scoring que produce `bot_puntaje`.

---

## Hallazgos del reconocimiento que cambian el brief

| # | Hallazgo | Impacto |
|---|---|---|
| H1 | `rotten_days` vive en `lead_pipelines`, **no** en `lead_pipeline_stages` | ✅ **Resuelto (D1):** un `rotten_days` por pipeline, tomando el máximo de sus etapas. La granularidad por etapa queda fuera del CRM. |
| H2 | `PipelineRepository::create()` **no** agrega Won/Lost; los precarga el formulario Vue | 🟡 El comando debe crearlas explícitamente o los pipelines quedan sin etapa terminal y `closed_at` nunca se setea. |
| H3 | `packages/Webkul/` **es** el core (el repo *es* `krayin/laravel-crm`), no está en `vendor/` | 🟡 La restricción "no tocar el core" aplica a `packages/Webkul/{Lead,Attribute,Core,Admin,...}`. |
| H4 | Won/Lost se detectan por `code` literal `'won'`/`'lost'` en 3 puntos del core | Los códigos son obligatorios, no decorativos. |
| H5 | `attributes` tiene `UNIQUE(code, entity_type)`; `lead_pipelines.name` es `UNIQUE`; stages tiene `UNIQUE(code, pipeline)` | ✅ Idempotencia protegida por el esquema para atributos, pipelines y etapas. |
| H6 | `lead_sources`, `lead_types` y `attribute_options` **no** tienen unique en `name` | 🟡 Su idempotencia depende solo de buscar-antes-de-insertar. Riesgo bajo real (ejecución manual, no concurrente). |
| H7 | `select` guarda `attribute_options.id` en `integer_value`, no la etiqueta | El comando debe emitir el mapa de ids para n8n. |
| H8 | La suite de tests corre contra la BD real y hace `truncate()` | ✅ **Resuelto (D4):** no se crea BD de test. Verificación por `--dry-run` + checklist manual. Limitación aceptada y documentada. |
| H9 | `LeadRepository.php:230` detecta won/lost por substring del nombre, con códigos de otro cliente | Ninguna etapa nueva colisiona. Deuda técnica preexistente, no la toco. |
| H10 | No hay staging. `APP_ENV=local` + `APP_DEBUG=true` en dominio público | Fuera de alcance, reportado aparte. Toda verificación es contra producción. |

---

## Tech Stack

| Componente | Versión |
|---|---|
| Krayin CRM | 2.1.5 |
| Laravel | 10.x |
| PHP | ^8.2 |
| Concord (módulos) | 1.16.0 |
| `krayin/rest-api` | v2.1.1 |
| MySQL | 9 (BD `kohlberg`, prefijo `kl_`) |
| Tests | Pest (`vendor/bin/pest`) |
| Estilo | Laravel Pint |

---

## Commands

Contenedor de la app: `heaven_c21_laravel.1.q90ec5h2gx8wqgu8cg00qztrg`
Contenedor de MySQL: `heaven_c21.1.etvwmtujhc67odnkcowk3f2vz`
`/code` en el contenedor es el mismo volumen que el working directory local.

```bash
# Alias usado abajo
C=heaven_c21_laravel.1.q90ec5h2gx8wqgu8cg00qztrg
DB=heaven_c21.1.etvwmtujhc67odnkcowk3f2vz

# --- Backup OBLIGATORIO antes de cualquier escritura ---
docker exec $DB mysqldump -u kohlberg -p"$DB_PASSWORD" --single-transaction kohlberg \
  > backups/kohlberg-$(date +%Y%m%d-%H%M%S).sql

# --- Setup ---
docker exec -w /code $C php artisan crm:setup-inmobiliaria --dry-run   # simula, no escribe
docker exec -w /code $C php artisan crm:setup-inmobiliaria             # aplica

# --- Rollback ---
docker exec -w /code $C php artisan crm:rollback-inmobiliaria --dry-run
docker exec -w /code $C php artisan crm:rollback-inmobiliaria

# --- Verificación ---
docker exec -w /code $C php artisan crm:setup-inmobiliaria --dry-run   # debe reportar 0 cambios
docker exec -w /code $C php artisan migrate:status

# --- Estilo (local) ---
./vendor/bin/pint packages/Webkul/Inmobiliaria

# --- Tests: NO hay suite automatizada (D4). NO correr php artisan test en esta instancia. ---
#     La verificación es el --dry-run de arriba + el checklist de docs/inmobiliaria/README.md
```

---

## Project Structure

Módulo Concord propio, siguiendo la convención de `Webkul\Doctor` y `Webkul\Whatsapp`.
**Ningún archivo fuera de este árbol se modifica**, salvo los dos puntos de registro marcados.

```
packages/Webkul/Inmobiliaria/src/
├── Console/Commands/
│   ├── SetupInmobiliaria.php        → crm:setup-inmobiliaria
│   └── RollbackInmobiliaria.php     → crm:rollback-inmobiliaria
├── Config/
│   └── catalogo.php                 → Los datos maestros como config pura (sin lógica)
├── Providers/
│   ├── ModuleServiceProvider.php    → Concord (sin modelos propios)
│   └── InmobiliariaServiceProvider.php → registra los dos comandos
└── Support/
    ├── CatalogoSeeder.php           → aplica el catálogo, idempotente
    └── ResultadoCatalogo.php        → acumula creado/existente/omitido para el reporte

docs/specs/setup-inmobiliaria-c21.md → este documento
docs/inmobiliaria/README.md          → entregable 3 (cómo correr, revertir, verificar)
docs/inmobiliaria/limitaciones.md    → entregable 4 (lo que no se pudo hacer)
tests/Feature/InmobiliariaSetupTest.php → ver Testing Strategy
```

**Puntos de registro (3 archivos del proyecto, ninguno del core Webkul):**
- `composer.json` → `autoload.psr-4`: `"Webkul\\Inmobiliaria\\": "packages/Webkul/Inmobiliaria/src"`
- `config/concord.php` → `\Webkul\Inmobiliaria\Providers\ModuleServiceProvider::class`
- `config/app.php` → `Webkul\Inmobiliaria\Providers\InmobiliariaServiceProvider::class` (sección
  "Custom service provider", junto a Doctor y Whatsapp)

---

## Code Style

Laravel Pint. Convenciones tomadas del módulo `Whatsapp` existente: docblock que explica *por qué*,
mensajes de usuario en español, `SUCCESS`/`FAILURE` como retorno.

```php
/**
 * Alta idempotente de una etapa dentro de un pipeline.
 *
 * lead_pipeline_stages tiene UNIQUE(code, lead_pipeline_id), así que el code es
 * la clave natural: buscamos por él y nunca por nombre, que el cliente puede
 * renombrar desde la UI sin avisarnos.
 */
protected function upsertEtapa(Pipeline $pipeline, array $etapa): string
{
    $existente = $pipeline->stages()->where('code', $etapa['code'])->first();

    if ($existente) {
        return $this->resultado->omitida($etapa['code'], 'ya existe');
    }

    if (! $this->dryRun) {
        $this->stages->create(array_merge($etapa, [
            'lead_pipeline_id' => $pipeline->id,
        ]));
    }

    return $this->resultado->creada($etapa['code']);
}
```

**Reglas:**
- Datos maestros en `Config/catalogo.php`, nunca inline en el comando. Un cambio de catálogo no debe
  tocar lógica.
- Acceso a datos **solo por repositorios de Krayin** (`PipelineRepository`, `StageRepository`,
  `AttributeRepository`, `SourceRepository`, `TypeRepository`) para que corran observers y validaciones.
  `DB::` crudo solo para las consultas de *lectura* de las guardas de rollback.
- Toda escritura dentro de una transacción por entidad, para que un fallo parcial no deje medio catálogo.
- Búsqueda por clave natural (`code` / `name`), nunca por id hardcodeado.

---

## Testing Strategy

> 🔴 **`php artisan test` NO se corre en esta instancia.** `phpunit.xml` no override `DB_CONNECTION`
> ni `DB_DATABASE`, ningún test usa `RefreshDatabase`, y tests existentes como
> `tests/Feature/DoctorApiTest.php:9` arrancan con `DB::table(...)->truncate()`. La suite apunta a
> `kohlberg` y destruye datos reales (H8).

**D4: no se crea BD de test.** Por decisión explícita no hay cobertura automatizada. Se reemplaza por
dos niveles, y la brecha se documenta como limitación (entregable 4).

1. **`--dry-run` como verificación primaria.** Ambos comandos lo aceptan y reportan exactamente lo que
   harían sin escribir. Es lo único que se puede correr con seguridad contra producción, y es la
   prueba de idempotencia: un `--dry-run` después de un setup exitoso debe reportar **cero cambios**.

2. **Checklist manual post-ejecución**, en `docs/inmobiliaria/README.md`. Cubre SC2–SC7 y SC10 con
   consultas SQL de lectura y una pasada por la UI.

**Brecha conocida:** SC8 (el rollback se niega si hay leads asociados) no tiene verificación
automatizada. Se comprueba a mano una sola vez, antes del primer uso real: crear un lead de prueba en
`Venta`, correr `crm:rollback-inmobiliaria --dry-run`, confirmar que reporta la negativa, y borrar el
lead. Con `leads = 0` hoy, el riesgo de esa maniobra es mínimo. Queda registrado en la limitación.

**Compensación:** el código de la guarda de rollback se mantiene lo más chico y legible posible —
una consulta de conteo por entidad, sin ramas — precisamente porque nada automatizado lo cubre.

## Boundaries

**Siempre:**
- Backup con `mysqldump` antes de cualquier escritura sobre la instancia.
- `--dry-run` antes de la ejecución real, y revisar su salida.
- Usar repositorios de Krayin para escribir.
- Buscar por clave natural antes de insertar (idempotencia).
- Correr Pint sobre el paquete nuevo antes de commitear.
- Reportar en la salida qué se creó, qué ya existía y qué se omitió.

**Preguntar primero:**
- Cualquier migración de esquema, incluida la opción 2 de Q1.
- Agregar dependencias a `composer.json` (no preveo ninguna).
- Cambiar cuál es el pipeline por defecto (Q3).
- Ejecutar el comando real contra producción (vs. `--dry-run`).
- Tocar `config/concord.php` o el `autoload` de `composer.json` (necesario, pero avisando).

**Nunca:**
- Modificar archivos bajo `vendor/`.
- Modificar archivos del core en `packages/Webkul/{Lead,Attribute,Core,Admin,Contact,...}`.
- Borrar o renombrar pipelines, etapas, atributos, sources o types **preexistentes**.
- Tocar filas de `leads` o `attribute_values`.
- Correr `php artisan test` contra la BD de la instancia (H8).
- Commitear el dump de backup al repo.
- Marcar `is_required=1` en cualquier atributo nuevo.

---

## Success Criteria

Cada uno es verificable con un comando concreto.

| # | Criterio | Verificación |
|---|---|---|
| SC1 | Correr el setup dos veces produce el mismo estado, sin duplicados | Segunda corrida con `--dry-run` reporta 0 cambios; `SELECT code, COUNT(*) ... GROUP BY code HAVING COUNT(*) > 1` devuelve 0 filas en stages, sources, types y attributes |
| SC2 | Los 3 pipelines existen con sus etapas en orden y probabilidad | `GET /api/v1/settings/pipelines` lista `Venta`, `Alquiler y anticrético`, `Captación`; `sort_order` ascendente sin huecos; probabilidades según las tablas del brief |
| SC3 | Cada pipeline termina en `won` y `lost` | Los 3 tienen etapas con `code IN ('won','lost')` como los dos `sort_order` más altos (H2/H4) |
| SC4 | `Venta` es el pipeline por defecto | Exactamente una fila con `is_default = 1`, y es `Venta` |
| SC5 | Un lead nuevo muestra los 23 atributos, ninguno obligatorio | Formulario de alta en `/admin/leads/create`; `SELECT COUNT(*) FROM kl_attributes WHERE entity_type='leads' AND is_user_defined=1 AND is_required=1` devuelve 0 |
| SC6 | Todos los select tienen sus opciones cargadas | Conteo de `attribute_options` por atributo coincide con el catálogo; `perdida_motivo` tiene 12 |
| SC7 | El rollback deja la instancia como estaba | Diff del catálogo antes/después contra el backup; los 5 sources y 2 types originales intactos |
| SC8 | El rollback se niega a borrar lo que tiene datos | Manual, una vez (D4): lead de prueba en `Venta` → `crm:rollback-inmobiliaria --dry-run` reporta la negativa → borrar el lead |
| SC9 | Ningún archivo de `vendor/` ni del core modificado | `git status --porcelain vendor/` vacío; `git diff --name-only` no toca `packages/Webkul/{Lead,Attribute,Core,Admin}` |
| SC10 | n8n puede resolver opciones de select sin ids hardcodeados | El setup imprime el mapa `código → {opción: id}` (H7), y `GET /api/v1/settings/attributes` lo devuelve |

---

## Decisiones tomadas

Cerradas el 2026-08-27. Ninguna bloquea Fase 2.

- **D1 — `rotten_days` por pipeline, con el máximo de sus etapas (H1).**
  `Venta = 30` (Documentación), `Alquiler y anticrético = 5` (Contrato), `Captación = 7`
  (Autorización). Sin cambios de esquema; el core lee y muestra el valor. La granularidad por etapa
  que pedía el brief se maneja en n8n, que ya vigila estos leads.
  *Consecuencia aceptada:* un lead estancado en "Nuevo" (donde el brief quería 1 día) recién se marca
  podrido a los 30 en `Venta`. La señal fina es responsabilidad de n8n.

- **D2 — Won/Lost se crean explícitamente (H2).** `code` literal `won`/`lost`, probabilidad `100`/`0`,
  en los dos `sort_order` más altos de cada pipeline. No era opcional: sin ellas los pipelines quedan
  sin etapa terminal y `closed_at` nunca se setea (H4).

- **D3 — `Venta` pasa a ser el pipeline por defecto.** "Pipeline Predeterminado" pierde el flag
  `is_default` (no se borra ni se renombra), como consecuencia mecánica de
  `PipelineRepository::create()`, que hace `update(['is_default' => 0])` sobre todos los demás.

- **D4 — Sin BD de test.** No se crea `kohlberg_test`. La verificación queda en `--dry-run` más
  checklist manual. La brecha (SC8) se documenta en el entregable 4.

- **D5 — `op_dormitorios` y `bot_puntaje`: tipo `text` con `validation = 'numeric'`.**
  Krayin **no tiene tipo numérico**: los 15 tipos disponibles mapean a `float_value` solo vía `price`,
  que inyecta el símbolo de moneda en la etiqueta del formulario
  (`components/attributes/index.blade.php:29-31`) y no se puede desactivar sin editar el core.
  Se prioriza la etiqueta correcta. `validation` acepta exactamente `['numeric','email','decimal','url']`,
  así que `'numeric'` es un valor soportado.
  *Consecuencia aceptada:* ambos se guardan en `text_value` (TEXT). Comparar por rango requiere
  `CAST(text_value AS UNSIGNED)` y no usa índice; `ORDER BY` es lexicográfico, lo que importa sobre
  todo en `bot_puntaje` (0-100: "100" ordena antes que "9"). n8n debe castear.

- **D6 — Paquete `Webkul\Inmobiliaria`**, comandos `crm:setup-inmobiliaria` y
  `crm:rollback-inmobiliaria`. Nombre por rubro y no por cliente, para que la instancia inmobiliaria
  siguiente lo reuse.

## Registro de cambios

| Fecha | Cambio |
|---|---|
| 2026-08-27 | Borrador inicial tras el reconocimiento de Fase 0. |
| 2026-08-27 | Q1-Q6 cerradas como D1-D6. Testing Strategy reescrita por D4. Listo para Fase 2. |
| 2026-08-27 | Implementado. Corrección: son 3 puntos de registro, no 2 (`config/app.php` también). |
