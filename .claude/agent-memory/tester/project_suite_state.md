---
name: project-suite-state
description: Estado de la suite de tests — cobertura actual, tests passing, gaps conocidos
metadata:
  type: project
---

Suite a 2026-05-24: **200 passed, 2 skipped, 4 failed** (1222 assertions, ~25s).

Los 2 skipped son intencionales y pre-existentes (SpecialtyApiTest, IaHistoryTest).

Los 4 failed son pre-existentes y no relacionados con el trabajo reciente:
- `PatientCrudTest > crear paciente`: redirect a URL distinta de la esperada (1454 vs genérica)
- `ProductDurationMinutesTest` (3 tests): campo `duration_minutes` nulo en BD de testing

## Archivos de test relevantes

| Archivo | Tests | Cubre |
|---|---|---|
| `tests/Feature/PersonSmdSyncTest.php` | 18 | syncSmd, searchSmd, listeners |
| `tests/Feature/ShareMeDataWebhookErrorTest.php` | 5 | auth webhook, validación payload |
| `tests/Feature/InsuranceVerificationTest.php` | 12 | verificación seguros UI (POST con person_id) |
| `tests/Feature/AgentInsuranceVerifyTest.php` | 18 | GET /api/v1/insurance/verify — agente WhatsApp |
| `tests/Feature/EntitySyncTest.php` | 2 | sync bidireccional CI/Seguro Person↔Lead |

## Patrón clave: tests de AgentInsuranceVerify

- URL real: `/api/v1/insurance/verify` (GET, bajo `auth:sanctum + throttle:insurance-agent`)
- Sin `Accept: application/json` el middleware 401 busca ruta `login` → explota con 500
- Rate limiter key con `actingAs()` sin token real: `insurance-agent|unauthenticated` → limpiar con `RateLimiter::clear('insurance-agent|unauthenticated')` en `beforeEach`
- Los drivers positivos (VIGENTE/EN_MORA) llaman `$person->leads` en `updateInsuranceAttributes` pero `verifyWithParams` pasa un `stdClass` → falla. Solución: mockear `InsuranceService::verifyWithParams` via `app()->instance()` + Mockery
- Token hash en audit log con `actingAs()` sin token: `hash('sha256', 'unknown')` (64 chars)
- `insurance_audit_logs` requiere migración previa: `php artisan migrate --env=testing`

## Gaps sin cobertura

- `syncSmd 403`: Bouncer otorga todos los permisos al User::find(1) en testing
- Tests de rate limit con token Sanctum real (actualmente usa key `unauthenticated`)
- Flujo VIGENTE con driver real (bloqueado por stdClass/leads, requeriría refactor del driver)
