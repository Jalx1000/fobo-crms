---
name: project-insurance-security
description: Estado de los controles de seguridad en GET /api/v1/insurance/verify — rate limiting, audit log, anti-enumeración
metadata:
  type: project
---

Tarea 8.5 completada. El endpoint `GET /api/v1/insurance/verify` tiene los tres controles de seguridad activos:

1. **Rate limiting** — named limiter `insurance-agent` registrado en `RouteServiceProvider::boot()`, 10 req/min por token (por `currentAccessToken()->id`). Responde 429 con `retry_after: 60`.
2. **Audit log** — tabla `insurance_audit_logs` (migración `2026_05_24_000002`). Almacena `token_hash`, `ci_hash`, `seguro_hash` (todos SHA-256), `result` (bool), `ip_address`. Append-only (sin `updated_at`). El método `logAudit()` en `InsuranceController` falla silenciosamente vía try/catch.
3. **Anti-enumeración** — `verifyForAgent()` devuelve `{"has_insurance":false,"patient_found":false}` tanto si el CI no existe como si no tiene seguro activo. `usleep(random_int(50000, 150000))` uniformiza timing en el path negativo.

**Why:** CI boliviano es dato de identificación personal. Un token comprometido podría usarse para brute-force de CIs o reconocimiento de aseguradoras.

**How to apply:** Si se añaden más endpoints que reciban CI o datos de pacientes, replicar este patrón: named rate limiter + audit log con hashes + shape de respuesta anti-enumeración.

Archivos modificados:
- `app/Providers/RouteServiceProvider.php` — líneas 31-42 (nuevo `RateLimiter::for('insurance-agent', ...)`)
- `packages/Webkul/Admin/src/Routes/Api/insurance.php` — línea 11 (añadido `->middleware('throttle:insurance-agent')`)
- `packages/Webkul/Admin/src/Http/Controllers/Api/InsuranceController.php` — `logAudit()` (líneas 199-222), llamadas en línea 174 (path negativo) y línea 184 (path positivo)
- `database/migrations/2026_05_24_000002_create_insurance_audit_logs_table.php` — nuevo archivo
