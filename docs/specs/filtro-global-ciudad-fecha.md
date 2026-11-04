# Spec: Filtro global (ciudad + fecha) en Tablero, Oportunidades y Contactos

Estado: **BORRADOR - pendiente de aprobación**
Rama: `imprimir-modulo-whatsapp` · Checkpoint previo: `c2a060e`
Referencia: CRM Kohlberg (`kolberg_laravel`): `Helpers/GlobalDateFilter.php`, `LeadController`, `PersonController`, `dashboard/index.blade.php`

## Contexto

El filtro global se heredó de Kohlberg: una **ciudad** y un **rango de fechas** que se eligen
una vez y valen en el Tablero, Oportunidades (leads) y Contactos (persons), guardados en
dos cookies (`global_pipeline_id` y `global_date_range`).

En Kohlberg **la ciudad es el pipeline**. En imprimir no:

- La ciudad es el atributo select **`ciudad`**, igual en `persons`, `leads` y `quotes`
  (migración `2026_09_15_100000_create_ciudad_attributes`). Sus opciones salen de
  `config('cotizacion.ciudades_cliente')`, y `Listeners/Ciudad.php` las sincroniza **por nombre**,
  porque cada entidad tiene sus propios ids de opción.
- El selector "Todas las ciudades" del tablero, de leads y de persons **filtra por
  `lead_pipeline_id`**, así que no filtra por ciudad.
- `PersonDataGrid` y `Reporting/Person.php` filtran por el atributo `cliente_ciudad`, que
  **esa misma migración borró**. Con una ciudad elegida, la lista de contactos y el KPI de
  contactos **ignoran el filtro** en silencio.
- Las tarjetas "Leads por ciudad" y "Ventas por ciudad" agrupan por pipeline.
- La cookie de fechas no vence: un rango elegido hace semanas sigue fijando el tablero en el pasado.
  Kohlberg ya lo resolvió con `GlobalDateFilter` (la fecha final vence a medianoche).

## Supuestos

1. **Ciudad por nombre.** El filtro viaja como el **nombre** de la ciudad
   (`?ciudad=Santa%20Cruz`), no como id de opción, porque los ids cambian entre entidades.
   Es el mismo criterio que `Listeners/Ciudad.php`.
2. **"Sin ciudad"** (`?ciudad=none`) = entidades sin valor en el atributo `ciudad`.
3. **Ciudad de una oportunidad** = solo `leads.ciudad`. Si está vacía, el lead cuenta como "Sin ciudad".
   La cascada hacia la ciudad del contacto queda **pospuesta** (decisión 2026-09-28). Como la
   expresión vive en un solo lugar (B), agregarla después es cambiar una línea.
4. **Ciudad de un contacto** = `persons.ciudad`, sin mirar sus leads.
5. **Cookie nueva `global_ciudad`**, sin encriptar y legible desde JS (en `EncryptCookies::$except`).
   `global_pipeline_id` deja de ser el filtro de ciudad.
6. El **selector de pipeline del kanban de leads** sigue visible como selector de pipeline,
   independiente del filtro de ciudad (hoy están mezclados). **Confirmado:** imprimir usa varios pipelines.
7. **La bandeja de WhatsApp queda fuera**, igual que la búsqueda por email, teléfono y PSID.

## Objetivo

Como gerente o asesor, elijo **una ciudad y un rango de fechas una vez** y el Tablero,
Oportunidades y Contactos muestran solo lo de esa ciudad y ese rango. Al día siguiente, el
rango termina en "hoy" y no en la fecha que dejé ayer.

## Alcance

| # | Cambio | Archivos principales |
|---|---|---|
| A | Portar `GlobalDateFilter` (vencimiento diario, `remember`/`forget`) y usarlo en los 3 módulos + JS del tablero | `Helpers/GlobalDateFilter.php` (nuevo), `DashboardController`, `LeadController`, `PersonController`, `dashboard/index.blade.php` |
| B | `Helpers/GlobalCityFilter.php` (nuevo): cookie `global_ciudad`, lista de ciudades y la **expresión SQL única** de la ciudad del lead y del contacto | nuevo |
| C | Oportunidades: el filtro de ciudad aplica la expresión B sobre `LeadDataGrid` y el kanban; el pipeline queda como selector aparte | `LeadController`, `LeadDataGrid`, `leads/index/*.blade.php` |
| D | Contactos: reemplazar `cliente_ciudad`/pipeline por `persons.ciudad` | `PersonController`, `PersonDataGrid`, `contacts/persons/index.blade.php` |
| E | Tablero: todas las tarjetas filtran con B; "Leads/Ventas por ciudad" y "No atendidos por ciudad" **agrupan por atributo `ciudad`**, no por pipeline | `Helpers/Reporting/{Lead,Person,Product}.php`, `Helpers/Dashboard.php`, vistas `dashboard/index/*ciudad*` |
| F | Migración de la cookie: una `global_pipeline_id` vieja se ignora y se borra (no se traduce) | `LeadController`, `PersonController`, JS del tablero |

**Fuera de alcance:** la bandeja de WhatsApp, la búsqueda por email/teléfono/PSID, los permisos por
ciudad del asesor y los cambios de esquema.

## Commands

```bash
php artisan test tests/Feature/FiltroGlobalCiudadTest.php     # nuevo
php artisan test tests/Feature/FiltroGlobalFechaTest.php      # nuevo
php artisan test tests/Feature/PersonSucursalReportingTest.php tests/Feature/DataGridPlatformFilterTest.php
./vendor/bin/pint <archivo>   # archivo por archivo
# Despliegue: reiniciar workers (supervisord) tras el deploy
```

## Code Style

Una sola fuente para la expresión SQL, igual que `ConversationCity` en Kohlberg: se escribe una
vez y la consumen el `where`, el `select` y el `groupBy`.

```php
// La ciudad de una oportunidad: la suya, y si no tiene, la de su contacto.
// Una sola expresión para filtrar, mostrar y agrupar; escrita tres veces,
// tarde o temprano devolvería tres cosas distintas.
$query->whereRaw(GlobalCityFilter::leadCitySql().' = ?', [$ciudad]);
```

Tablas con prefijo `kl_`: toda expresión `DB::raw` usa `DB::getTablePrefix()`.

## Testing Strategy (Pest, feature)

1. **Fecha:** una cookie guardada ayer → la fecha final pasa a hoy; guardada hoy → se respeta;
   sin `savedAt` (formato viejo) → se trata como vencida; `from > to` → se ajusta.
2. **Ciudad, leads:** `ciudad=Santa Cruz` trae solo los leads con esa ciudad; `none` trae los que no
   tienen ciudad (aunque su contacto sí tenga); sin parámetro no cambia nada. Combinado con
   `pipeline_id`, los dos filtros se suman sin pisarse.
3. **Ciudad, contactos:** filtra por `persons.ciudad`, y el KPI "Total contactos" coincide 1:1 con la lista.
4. **Tablero:** la suma de "Leads por ciudad" (incluido "Sin ciudad") = total de leads del rango.
5. **Cookie:** elegir ciudad en un módulo la aplica en los otros dos; una `global_pipeline_id` vieja no filtra.
6. **Regresión:** los tests existentes de reporting y DataGrid pasan.

UI: comprobación en el navegador real con la consola abierta (sin errores) en los tres módulos.

## Boundaries

- **Always:** una sola expresión de ciudad; tests antes de hacer commit; Pint; commit por tarea.
- **Ask first:** borrar la cookie `global_pipeline_id` en producción, tocar datos (backfill de `ciudad`), cambios de esquema.
- **Never:** filtrar por id de opción de otra entidad, cambiar permisos o ACL, tocar la bandeja de WhatsApp en este trabajo.

## Success Criteria

- [ ] Con "Santa Cruz" elegida, el Tablero, Oportunidades y Contactos muestran solo Santa Cruz, y la elección se mantiene al pasar de un módulo a otro.
- [ ] "Sin ciudad" muestra exactamente lo que no tiene ciudad.
- [ ] "Leads por ciudad" y "Ventas por ciudad" muestran ciudades reales (Santa Cruz, La Paz…), no pipelines.
- [ ] Un rango guardado ayer termina hoy al abrir el Tablero.
- [ ] Ninguna referencia a `cliente_ciudad` en el código.
- [ ] Tests nuevos y existentes en verde.

## Decisiones tomadas

- 2026-09-28 - Imprimir usa varios pipelines: el selector de pipeline se mantiene, separado del de ciudad.
- 2026-09-28 - Sin cascada lead → contacto por ahora: la ciudad del lead es solo `leads.ciudad`.

## Open Questions

1. **Tarjetas "por sucursal"** (`leads-por-sucursal`, `ventas-por-sucursal`): ¿también obedecen al filtro de ciudad? Propuesta: sí, como el resto de tarjetas.
