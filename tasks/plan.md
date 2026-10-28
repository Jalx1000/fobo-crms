# Plan — Fichas de evaluación + consentimiento (Fase 1: storage)

Spec: `docs/specs/fichas-evaluacion-consentimiento.md` (APROBADO).

## Componentes y orden
1. Migraciones (Contact pkg) — base, van primero.
   - `patient_evaluations` (person_id, activity_id?, product_id?, answers JSON, notes, performed_by?, performed_at)
   - `patient_consents` (person_id, activity_id?, product_id?, document, accepted, signed_name, signed_at, file_path, performed_by?)
   - FKs: person_id/activity_id/product_id/performed_by = unsignedInteger (int unsigned en esas tablas).
2. Contratos + Proxies + Modelos (Concord): PatientEvaluation, PatientConsent.
3. Registro en Contact ModuleServiceProvider.
4. Relaciones en Person: evaluations(), consents().
5. Aplicar esquema al dev de Railway (node/mysql2) — idempotente.
6. Commit + push.

## Riesgos / mitigación
- Sin PHP local → no corre Pest acá. Mitigación: migraciones idempotentes + aplicar
  y verificar el esquema en la BD; el test Pest queda para CI/deploy.
- Acoplamiento Contact→Activity/Product (FKs). Mitigación: FKs nullable + set null.

## Verificación
- Tablas creadas en la BD; insertar una evaluación (answers JSON) y un consentimiento
  de prueba vía SQL y leerlos de vuelta.
- Confirmar que nada de esto se expone en `GET /whatsapp/products`.

## Fuera de alcance (Fase 2)
Plantilla de preguntas, UI de carga, upload de PDF, alerta "no atender sin ficha".
