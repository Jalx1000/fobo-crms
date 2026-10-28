# Spec: Fichas de evaluación previa + consentimiento informado (Tessa)

> Estado: **APROBADO (Fase 1).** Decisiones: consentimiento **flexible** (por paciente
> o por tratamiento, `product_id` nullable); alerta "no atender sin ficha" → **Fase 2**.

## Objective

Dar al CRM de Tessa un lugar para registrar, por paciente y por tratamiento, la
**ficha de evaluación previa** y el **consentimiento informado** firmado — hoy en
fichas manuales archivadas (brief, preguntas 10 y 13).

- **Usuario:** el staff de la clínica (recepción/esteticista), desde el panel del CRM.
- **No-usuario:** el agente de WhatsApp. El agente solo *informa* ("antes del
  tratamiento haremos la evaluación y te explicaremos el consentimiento"); no llena nada.
- **Éxito:** cada cita de tratamiento puede tener su ficha de evaluación y su
  consentimiento registrados y consultables desde la paciente; el CRM puede
  advertir si faltan (norma: no se atiende sin evaluación + consentimiento).

Restricción de origen: **las preguntas exactas de la ficha las define Tessa**
(entregable Word/Excel pendiente). Por eso las respuestas se guardan como JSON.

## Tech Stack

- Krayin CRM sobre Laravel + PHP 8.2, arquitectura modular Concord (paquetes `Webkul/*`).
- MySQL (prefijo de tablas `od_`). Deploy en Railway (sin PHP local en el entorno de trabajo).
- Vue 3 + Blade + Tailwind para la UI (Fase 2).

## Commands

```bash
php artisan test                      # suite Pest
php artisan test --filter Evaluation  # un grupo
php artisan migrate                   # aplicar migraciones (idempotentes)
./vendor/bin/pint                     # estilo (laravel preset + => alineado)
```

Nota: el entorno de trabajo no tiene PHP; la validación real es en el deploy.
El esquema se aplica al dev de Railway vía el tooling node (mysql2) ya usado.

## Project Structure

Paquete **`packages/Webkul/Contact`** (centrado en `persons`):

```
Contact/src/
├── Contracts/        PatientEvaluation.php, PatientConsent.php
├── Models/           PatientEvaluation(.php/Proxy), PatientConsent(.php/Proxy)
├── Database/Migrations/
│   ├── ..._create_patient_evaluations_table.php
│   └── ..._create_patient_consents_table.php
└── Providers/ModuleServiceProvider.php   (registrar los 2 modelos)
```

Seeder de demo opcional en `database/seeders/` (parity con lo que ya hacemos).

## Data Model

```
PACIENTE (persons) ─1─N→ patient_evaluations
                   └─1─N→ patient_consents
```

**patient_evaluations** (ficha de evaluación previa)
| col | tipo | nota |
|---|---|---|
| id | bigint PK | |
| person_id | FK persons (cascade) | paciente |
| activity_id | FK activities (nullable, set null) | la cita |
| product_id | FK products (nullable, set null) | el servicio evaluado |
| answers | json nullable | respuestas (preguntas TBD de Tessa) |
| notes | text nullable | observaciones libres |
| performed_by | FK users (nullable, set null) | quién la llenó |
| performed_at | datetime nullable | |
| timestamps | | |

**patient_consents** (consentimiento informado)
| col | tipo | nota |
|---|---|---|
| id | bigint PK | |
| person_id | FK persons (cascade) | |
| activity_id | FK activities (nullable, set null) | |
| product_id | FK products (nullable, set null) | opcional, por tratamiento |
| document | string nullable | nombre/versión del consentimiento |
| accepted | bool default false | |
| signed_name | string nullable | quién firma |
| signed_at | datetime nullable | |
| file_path | string nullable | PDF escaneado/firmado (upload en Fase 2) |
| performed_by | FK users (nullable, set null) | |
| timestamps | | |

Relaciones Eloquent: `Person::evaluations()`, `Person::consents()`;
`PatientEvaluation`/`PatientConsent` → `belongsTo` person/activity/product/user.

## Code Style

Patrón Concord ya usado en este repo (ver `Branch`, `ServiceZone`):

```php
class PatientEvaluation extends Model implements PatientEvaluationContract
{
    protected $table = 'patient_evaluations';

    protected $fillable = ['person_id', 'activity_id', 'product_id', 'answers', 'notes', 'performed_by', 'performed_at'];

    protected $casts = ['answers' => 'array', 'performed_at' => 'datetime'];

    public function person(): BelongsTo
    {
        return $this->belongsTo(PersonProxy::modelClass(), 'person_id');
    }
}
```

Migraciones idempotentes (`Schema::hasTable`/`hasColumn`), registro en el
`ModuleServiceProvider` `$models`, proxy `extends ModelProxy`.

## Testing Strategy

- Pest, en `tests/Feature/`.
- Fase 1: un feature test que crea una paciente, le agrega una evaluación (con
  `answers` JSON) y un consentimiento (accepted + signed_at), y verifica las
  relaciones y el cast JSON.
- Sin PHP local → los tests corren en CI/deploy; acá validamos el esquema en la BD.

## Boundaries

- **Always:** migraciones idempotentes; FKs nullable para no romper data; seguir
  el patrón Concord (contract+proxy+registro); mantener seeders en parity.
- **Ask first:** dónde se guardan los archivos del consentimiento (disco local vs
  S3); si el consentimiento es por-tratamiento o uno global por paciente; agregar
  una validación dura "no atender sin ficha".
- **Never:** exponer estos datos clínicos por la API pública del agente; commitear
  PDFs/archivos de pacientes reales; borrar data de pacientes.

## Success Criteria

1. `migrate` crea `patient_evaluations` y `patient_consents` (idempotente, re-ejecutable).
2. Modelos registrados; `Person::evaluations()` y `Person::consents()` devuelven las relaciones.
3. Se puede crear una evaluación con `answers` JSON y un consentimiento con
   `accepted/signed_at` vía Eloquent (probado por seeder/tinker o test).
4. Estos datos **no** aparecen en `GET /whatsapp/products` ni en endpoints del agente.
5. (Fase 2, fuera de este spec) plantilla de preguntas + UI de carga + upload de PDF.

## Open Questions

1. ¿El **consentimiento** es uno por paciente (declara entender *todos* los
   tratamientos) o uno por tratamiento/servicio? (modelo hoy soporta ambos con
   `product_id` nullable).
2. ¿La **evaluación** es por cita o por serie de tratamiento? (hoy: por cita vía
   `activity_id`, con `product_id` para el tratamiento).
3. Almacenamiento de archivos del consentimiento: ¿disco local del server vs S3?
4. ¿Querés desde ya la **alerta** "cita sin ficha/consentimiento", o eso va a Fase 2?
5. Fecha real de entrega de la ficha de Tessa (define cuándo arranca Fase 2).
```
