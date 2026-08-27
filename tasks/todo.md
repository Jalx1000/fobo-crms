# Tareas — Configuración inmobiliaria CENTURY 21

- **Spec:** `docs/specs/setup-inmobiliaria-c21.md` · **Plan:** `tasks/plan.md`
- **Estado:** 🟡 Pendiente de revisión. No ejecutar hasta aprobación.
- Ninguna tarea toca más de 5 archivos. Orden por dependencia, no por importancia.

Abreviatura usada abajo:
```bash
C=heaven_c21_laravel.1.q90ec5h2gx8wqgu8cg00qztrg
X() { docker exec -w /code $C "$@"; }
```

---

- [ ] **T1 — Scaffolding del paquete `Webkul\Inmobiliaria` y registro** *(C1)*
  - Acceptance: el módulo carga vía Concord; `composer dump-autoload` resuelve el namespace; ningún
    comando registrado todavía. Sin modelos propios (`$models = []`).
  - Verify: `X php artisan about` sin errores de autoload.  *(V1)*
  - Files: `packages/Webkul/Inmobiliaria/src/Providers/ModuleServiceProvider.php`,
    `.../Providers/InmobiliariaServiceProvider.php`, `composer.json`, `config/concord.php`

- [ ] **T2 — Catálogo como config pura** *(C2)*
  - Acceptance: `Config/catalogo.php` devuelve un array con los 3 pipelines (25 etapas, Won/Lost
    incluidas por D2, `rotten_days` 30/5/7 por D1), 9 sources, 5 types y 23 atributos con sus 49
    opciones. Cero lógica: solo datos. `op_dormitorios` y `bot_puntaje` como `text` +
    `validation => 'numeric'` (D5). Ningún atributo con `is_required => 1`.
  - Verify: `X php -r "print_r(array_map('count', require 'packages/Webkul/Inmobiliaria/src/Config/catalogo.php'));"`
    devuelve los conteos de la tabla "Volumen real" del plan.
  - Files: `packages/Webkul/Inmobiliaria/src/Config/catalogo.php`

- [ ] **T3 — Manifiesto sobre `core_config`** *(C3)*
  - Acceptance: `Manifiesto::leer()` devuelve estructura vacía si no existe la fila;
    `Manifiesto::registrar($entidad, $id)` acumula; `Manifiesto::guardar()` **fusiona** con lo ya
    persistido en vez de reemplazar; `Manifiesto::olvidar()` borra la fila. Key
    `inmobiliaria.setup.manifest`.
  - Verify: tinker — registrar dos ids en dos llamadas separadas y confirmar que ambos sobreviven.
  - Files: `packages/Webkul/Inmobiliaria/src/Support/Manifiesto.php`

- [ ] **T4 — Acumulador de reporte** *(C4)*
  - Acceptance: distingue tres estados por ítem (creado / ya existía / omitido con motivo) y sabe
    renderizar un resumen por entidad. Sin dependencias de Console para poder reusarse en rollback.
  - Verify: unitario a mano en tinker; el render de un caso mixto es legible.
  - Files: `packages/Webkul/Inmobiliaria/src/Support/ResultadoCatalogo.php`

- [ ] **T5 — Aplicador idempotente: pipelines, etapas, sources, types** *(C5, parte 1)*
  - Acceptance: busca por clave natural (`code` en etapas, `name` en pipelines/sources/types) antes de
    insertar; escribe por repositorios de Krayin; registra cada id creado en el manifiesto; respeta
    `--dry-run`. Los 3 pipelines se crean con `is_default => 0` y `Venta` se marca default en un paso
    final explícito **(R3)**. Todo pipeline pasa siempre su clave `stages` **(R2)**.
  - Verify: contra el catálogo, en dry-run: reporta 3 pipelines, 25 etapas, 8 sources (omite "Web"),
    5 types.
  - Files: `packages/Webkul/Inmobiliaria/src/Support/CatalogoSeeder.php`

- [ ] **T6 — Aplicador idempotente: atributos y opciones** *(C5, parte 2)*
  - Acceptance: upsert de atributo y upsert de **cada opción por separado** — no delegar las opciones
    a `AttributeRepository::create()`, que solo las inserta cuando el atributo es nuevo **(R6)**. Las
    opciones se buscan por `name` dentro del `attribute_id` **(R4)**. Ningún atributo queda
    `is_required = 1`.
  - Verify: dry-run reporta 23 atributos y 49 opciones; re-ejecución tras aplicar reporta 0.
  - Files: `packages/Webkul/Inmobiliaria/src/Support/CatalogoSeeder.php`

- [ ] **T7 — Comando `crm:setup-inmobiliaria`** *(C6)*
  - Acceptance: soporta `--dry-run`; salida agrupada por entidad con creado/existente/omitido;
    al final imprime el **mapa `código → {opción: id}`** de los 10 selects, para n8n (SC10); cada
    entidad se aplica dentro de su propia transacción; retorna `SUCCESS`/`FAILURE`.
  - Verify: **V2** (dry-run = conteos del plan) → **V3** (backup) → **V4** (aplicación real) →
    **V5** (segundo dry-run = **0 cambios**, que es SC1).
  - Files: `packages/Webkul/Inmobiliaria/src/Console/Commands/SetupInmobiliaria.php`,
    `.../Providers/InmobiliariaServiceProvider.php`

- [ ] **T8 — Comando `crm:rollback-inmobiliaria` con guardas** *(C7)*
  - Acceptance: borra **únicamente** los ids del manifiesto **(R1)**; se niega —sin borrar nada— si
    algún pipeline o etapa del manifiesto tiene leads asociados, o si algún atributo tiene
    `attribute_values`; soporta `--dry-run`; al terminar limpia la fila del manifiesto; si no hay
    manifiesto, sale con un mensaje claro en vez de adivinar.
  - Verify: **V6** (dry-run nunca lista `lead_sources.id = 2`) y **V7** (guarda, manual, una vez).
  - Files: `packages/Webkul/Inmobiliaria/src/Console/Commands/RollbackInmobiliaria.php`,
    `.../Providers/InmobiliariaServiceProvider.php`

- [ ] **T9 — README e informe de limitaciones** *(C8)*
  - Acceptance: el README cubre cómo correr, cómo revertir, el checklist manual de verificación
    (SC2-SC7, SC10) y el procedimiento de backup. El informe de limitaciones documenta: días de
    rotación por etapa no soportados (D1/H1), ausencia de cobertura automatizada y la brecha de SC8
    (D4/H8), `bot_puntaje` ordenable solo con `CAST` (D5), y la deuda preexistente de
    `LeadRepository.php:230` (H9) más `APP_DEBUG=true` en producción (H10).
  - Verify: **V8** — `git status --porcelain vendor/` vacío y el diff no toca
    `packages/Webkul/{Lead,Attribute,Core,Admin}` (SC9).
  - Files: `docs/inmobiliaria/README.md`, `docs/inmobiliaria/limitaciones.md`

---

## Compuertas

- **Después de T7 no se avanza a T8** sin que V5 dé 0 cambios. La idempotencia es SC1 y es lo que hace
  seguro todo lo demás.
- **T7 no se aplica de verdad** (V4) sin el backup de V3 hecho y verificado.
- **T9 cierra** solo con V8 en verde.
