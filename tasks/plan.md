# Plan técnico — Configuración inmobiliaria CENTURY 21

- **Spec:** `docs/specs/setup-inmobiliaria-c21.md` (decisiones D1-D6 cerradas)
- **Fase:** 2 de 4 (Specify ✅ → **Plan** → Tasks → Implement)
- **Estado:** 🟡 Pendiente de revisión humana. No implementar hasta aprobación.

---

## Volumen real del catálogo

Contado contra el estado actual de la instancia, no contra el brief:

| Entidad | A crear | Ya existe | Nota |
|---|---:|---:|---|
| Pipelines | 3 | 0 | `Venta`, `Alquiler y anticrético`, `Captación` |
| Etapas | **25** | 0 | 7+2, 6+2, 6+2 — los `+2` son Won/Lost (D2) |
| Sources | **8** | **1** | "Web" ya existe como `id=2` → se omite |
| Types | 5 | 0 | Ninguno colisiona con los 2 actuales |
| Atributos (leads) | 23 | 0 | 10 de tipo `select`, 13 de otros tipos |
| Opciones de select | **49** | 0 | `perdida_motivo` es el más grande, con 12 |

Estos números son la salida esperada del primer `--dry-run`. Si no coinciden, algo cambió en la
instancia entre el reconocimiento y la ejecución, y hay que re-verificar antes de aplicar.

---

## Decisión de arquitectura: el manifiesto

**Problema.** "Web" figura en el catálogo del brief y **ya existe** en la instancia (`id=2`). El setup
idempotente la omite sin problema, pero el rollback no puede decidir qué borrar comparando contra el
catálogo: borraría "Web", que preexistía y no es nuestra. Lo mismo aplicaría a cualquier source, type
o atributo que el cliente cree a mano con un nombre del catálogo.

**Decisión.** El setup persiste un **manifiesto** de los ids que realmente creó, en `core_config`
(`code = 'inmobiliaria.setup.manifest'`, `value` TEXT). El rollback borra **solo** esos ids.

```json
{
  "version": 1,
  "applied_at": "2026-08-27T14:03:11-04:00",
  "created": {
    "pipelines": [2, 3, 4],
    "stages": [7, 8, 9, "..."],
    "sources": [6, 7, "..."],
    "types": [3, 4, 5, 6, 7],
    "attributes": [10, 11, "..."],
    "attribute_options": [1, 2, "..."]
  }
}
```

**Por qué `core_config` y no un archivo:** vive en la BD, así que entra en el `mysqldump` — backup y
manifiesto nunca se desincronizan. Un JSON en `storage/` se pierde en un redeploy del contenedor y
dejaría el rollback ciego. Tamaño estimado: ~170 ids ≈ 1.5 KB, muy por debajo del límite de `TEXT`.

**Ejecuciones sucesivas:** el setup **fusiona** en el manifiesto existente en vez de reemplazarlo, para
que una corrida parcial seguida de otra no pierda el rastro de la primera.

---

## Componentes y orden de implementación

```
C1 Scaffolding ──┬──> C2 Catálogo ──┐
                 ├──> C3 Manifiesto ─┼──> C5 Aplicador ──┬──> C6 Setup ──> C8 Docs
                 └──> C4 Reporte ────┘                   └──> C7 Rollback ──┘
```

| # | Componente | Archivos | Depende de |
|---|---|---|---|
| C1 | Scaffolding del paquete + registro | `Providers/{Module,Inmobiliaria}ServiceProvider.php`, `composer.json`, `config/concord.php`, `config/app.php` | — |
| C2 | Catálogo como config pura | `Config/catalogo.php` | C1 |
| C3 | Manifiesto (lectura/escritura en `core_config`) | `Support/Manifiesto.php` | C1 |
| C4 | Acumulador de reporte (creado/existente/omitido) | `Support/ResultadoCatalogo.php` | C1 |
| C5 | Aplicador idempotente | `Support/CatalogoSeeder.php` | C2, C3, C4 |
| C6 | Comando de setup | `Console/Commands/SetupInmobiliaria.php` | C5 |
| C7 | Comando de rollback + guardas | `Console/Commands/RollbackInmobiliaria.php` | C3, C5 |
| C8 | README + informe de limitaciones | `docs/inmobiliaria/{README,limitaciones}.md` | C6, C7 |

**Paralelizable:** C2, C3 y C4 son independientes entre sí. **Secuencial y crítico:** C1 antes que
todo (sin autoload el resto no carga), y C5 antes que C6/C7.

---

## Riesgos y mitigación

| # | Riesgo | Prob. | Impacto | Mitigación |
|---|---|---|---|---|
| R1 | Rollback borra datos preexistentes ("Web") | Alta | 🔴 Alto | Manifiesto (arriba). Es la razón de que exista. |
| R2 | `PipelineRepository::create()` explota sin `$data['stages']` — hace `foreach` sin `??` | Media | Medio | El catálogo obliga a que todo pipeline traiga sus etapas; validar la forma del config antes de aplicar |
| R3 | `is_default` se pisa entre pipelines: `create()` con `is_default` hace `update(['is_default'=>0])` sobre todos | Media | Medio | Crear los 3 con `is_default=0` y setear `Venta` como default en un paso final explícito |
| R4 | Duplicación de `attribute_options` al re-ejecutar (sin unique en `name`) | Media | Medio | Buscar por `name` dentro del `attribute_id` antes de insertar; nunca usar `create()` a ciegas en la segunda pasada |
| R5 | Sin cobertura automatizada (D4): un bug de idempotencia se ve recién en producción | Alta | Medio | `--dry-run` obligatorio antes de cada aplicación + backup previo; mantener la guarda de rollback trivialmente simple |
| R6 | `AttributeRepository::create()` crea opciones solo si el atributo es nuevo; si existe, las opciones nuevas no se agregan | Media | Medio | Tratar atributo y opciones como dos pasos separados en C5, no delegar las opciones al repo |
| R7 | Un upgrade de Krayin cambia la firma de los repositorios | Baja | Medio | Todo el acoplamiento vive en C5; el catálogo (C2) queda intacto |
| R8 | La instancia cambió entre el reconocimiento y la ejecución | Media | Bajo | El `--dry-run` reporta los conteos de la tabla de arriba; discrepancia = frenar |

**Sobre R6:** `AttributeRepository::create()` inserta las opciones dentro del mismo llamado
(`AttributeRepository.php:41-49`), pero solo cuando crea el atributo. En una re-ejecución el atributo
ya existe, el repo devuelve temprano y las opciones faltantes nunca se agregan. Por eso C5 hace
*upsert de atributo* y *upsert de cada opción* por separado, en vez de confiar en el camino feliz del
repositorio.

---

## Puntos de verificación

Entre fases, no al final. Cada uno es una compuerta: si falla, no se avanza.

| V | Después de | Comprobación | Criterio |
|---|---|---|---|
| V1 | C1 | `docker exec -w /code $C php artisan about` | Sin errores de autoload; el módulo carga |
| V2 | C6 | `php artisan crm:setup-inmobiliaria --dry-run` | Reporta exactamente los conteos de "Volumen real" |
| V3 | antes de aplicar | `mysqldump` a `backups/` | El archivo existe y pesa > 0 |
| V4 | aplicación real | `php artisan crm:setup-inmobiliaria` | Salida sin errores; imprime el mapa de ids (SC10) |
| V5 | C6 (idempotencia) | segundo `--dry-run` | **0 cambios** — es SC1, el criterio más importante |
| V6 | C7 | `crm:rollback-inmobiliaria --dry-run` | Lista solo los ids del manifiesto; nunca `lead_sources.id = 2` |
| V7 | C7 (guarda) | manual: lead de prueba en `Venta` → rollback `--dry-run` | Reporta la negativa y no borra nada (SC8) |
| V8 | C8 | `git status --porcelain vendor/` y diff sobre el core | Vacío (SC9) |

---

## Fuera de alcance

- Los flujos de n8n y el prompt del agente de IA.
- El consumidor de días de rotación por etapa (D1 lo delega a n8n).
- Arreglar la suite de tests destructiva (H8) o `APP_DEBUG=true` en producción (H10) — reportados,
  no incluidos.
- La deuda de `LeadRepository.php:230` (won/lost por substring del nombre, H9).
