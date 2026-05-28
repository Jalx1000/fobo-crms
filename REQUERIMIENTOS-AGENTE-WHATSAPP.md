# REQUERIMIENTOS — Plataforma Agente WhatsApp

**De:** Plataforma Agente WhatsApp (03.agent-production)
**Para:** Team Lead CRM Odontoking
**Fecha:** 2026-05-24
**Distribuir a:** backend, arquitecto, ciberseguridad, qa

---

## Contexto

El agente conversacional de WhatsApp está en producción en `https://odontoking.wappy.dev`. Se detectaron 3 problemas en el flujo de agendamiento de citas y se solicita 1 endpoint nuevo.

---

## REQ-1 — El agente está usando el endpoint equivocado · BLOQUEANTE

### Problema

El agente llama a `GET /api/doctors` (endpoint Krayin vendor) para mostrar horarios disponibles. Ese endpoint devuelve el campo `availability` con solo `start_time` — sin `end_time`. El agente **inventa** que cada cita dura 60 minutos, generando confirmaciones incorrectas en producción.

**El endpoint correcto ya existe:** `GET /api/disponibilidad?doctorId=X&date=Y` devuelve `startTime` y `endTime` reales y ya verifica contra citas reservadas.

### Lo que necesitamos

**Nada que modificar en la API.** El fix es del lado del agente WhatsApp — vamos a migrar el flujo para usar `GET /api/disponibilidad` en lugar de `GET /api/doctors`.

**Sin embargo, necesitamos confirmar:**

1. ¿`GET /api/disponibilidad` requiere `auth:sanctum`? Sí, está en el grupo `middleware(auth:sanctum)`. El token que usamos (`ODONTOKING_API_TOKEN`) ¿es un token Sanctum válido para ese endpoint?
2. ¿El endpoint acepta múltiples fechas en una sola llamada o hay que llamarlo una vez por fecha?
3. ¿Qué rango de fechas es razonable consultar (ej. próximos 7 días)?

---

## REQ-2 — Agregar `duration_minutes` a productos/servicios · ALTO

### Problema

El agente no puede validar si un slot alcanza para el servicio solicitado (Limpieza ≠ Implante en duración). No hay campo de duración en `GET /api/public/products`.

### Lo que necesitamos

Agregar un atributo personalizado `duration_minutes` (entero, en minutos) a los productos en el CRM, y exponerlo en la respuesta de `GET /api/public/products`.

**Duración por defecto: 60 minutos.** El campo es opcional. Cuando un producto no lo tiene configurado (`null`), el agente asume 60 minutos. Este default NO debe aplicarse en backend — el API devuelve `null` y el agente decide.

**Respuesta esperada por producto:**
```json
{ "id": 5, "name": "Limpieza", "duration_minutes": 45 }
```

Cuando no está configurado:
```json
{ "id": 8, "name": "Consulta General", "duration_minutes": null }
```

> El campo `duration_minutes` **nunca debe omitirse** de la respuesta aunque sea `null`.

### Criterio de aceptación

- Atributo `duration_minutes` visible en el panel de administración al editar un producto (campo opcional)
- Campo siempre presente en `GET /api/public/products`, devuelve `null` si no está configurado
- Tests: respuesta con valor configurado, respuesta con `null`, campo nunca ausente

---

## REQ-3 — NUEVO ENDPOINT: Verificación de seguro dental · NUEVO FEATURE

### Descripción

Necesitamos verificar si un paciente tiene seguro dental activo usando los custom attributes ya existentes en el CRM: `ci_paciente` (cédula) y `seguro_paciente` (empresa aseguradora).

### Endpoint propuesto

```
GET /api/v1/insurance/verify
```

**Parámetros (ambos requeridos):**

| Parámetro         | Tipo   | Descripción                                        |
|-------------------|--------|----------------------------------------------------|
| `ci_paciente`     | string | Cédula del paciente (custom attribute del Person)  |
| `seguro_paciente` | string | Nombre de la empresa aseguradora (texto libre)     |

**Ejemplo:**
```
GET /api/v1/insurance/verify?ci_paciente=0912345678&seguro_paciente=Equinoccial
```

**Respuesta con seguro activo:**
```json
{
  "has_insurance": true,
  "insurance_name": "Seguros Equinoccial",
  "policy_number": "POL-2025-00123",
  "coverage_type": "dental",
  "valid_until": "2026-12-31",
  "covered_services": ["limpieza", "radiografia", "extraccion"],
  "patient_name": "Adenilza Flores",
  "patient_id": 55
}
```

**Respuesta sin seguro o paciente no encontrado (misma estructura, anti-enumeración):**
```json
{
  "has_insurance": false,
  "patient_found": false
}
```

### Requerimientos de seguridad (para agente `ciberseguridad`)

- Auth: mismo token API que usamos en los demás endpoints (`ODONTOKING_API_TOKEN`)
- Rate limiting: máx **10 req/min por token** (evita enumeración masiva)
- Audit log por consulta: `{ token_hash, ci_hash, seguro_hash, timestamp, result: bool }`
  - Guardar **hash** de los valores buscados, nunca en claro (PII)
- Respuesta idéntica para "no encontrado" y "sin seguro" (evita confirmar existencia del paciente)
- No retornar `patient_id` cuando `has_insurance: false`

### Contexto técnico — infraestructura ya existente, solo falta el endpoint

- **`ci_paciente`** y **`seguro_paciente`** son Custom Attributes del modelo `Person` ✅
- **`InsuranceService::verifyWithParams($ci, $seguroName)`** ya existe ✅
- **`InsuranceController::verifyQuick()`** ya hace algo similar pero requiere `seguro_option_id` (entero) en lugar del nombre en texto

**Lo que falta implementar:**
1. Endpoint API (auth Sanctum por token) que reciba `ci_paciente` + `seguro_paciente` (nombre en texto)
2. Lookup del `Person` por valor del custom attribute `ci_paciente` en tabla EAV `attribute_values`
3. Rate limiting 10 req/min por token
4. Audit log con valores hasheados

**Archivos clave a reutilizar:**
- `packages/Webkul/Admin/src/Services/InsuranceService.php` — método `verifyWithParams()`
- `packages/Webkul/Admin/src/Http/Controllers/Contact/Persons/InsuranceController.php` — referencia
- `tests/Feature/InsuranceVerificationTest.php` — tests existentes a extender

### Criterio de aceptación con tests obligatorios

- [ ] Búsqueda con `ci_paciente` + `seguro_paciente` válidos → devuelve seguro activo
- [ ] `ci_paciente` no existe → `{ has_insurance: false, patient_found: false }`
- [ ] `ci_paciente` existe pero `seguro_paciente` no coincide → misma respuesta anterior
- [ ] Request #11 en 1 minuto → 429 Too Many Requests
- [ ] Audit log registra la consulta con valores hasheados
- [ ] `patient_id` ausente en respuesta negativa

---

## REQ-4 — Endpoint por doctor ID individual · MEJORA

`GET /api/doctors/{id}` — actualmente el agente carga los 100 doctores y filtra en Python para obtener 1. Un endpoint directo reduce el payload ~99% y permite caché por doctor.

---

## Preguntas bloqueantes

| # | Pregunta | Estado |
|---|---|---|
| P1 | ¿El token `ODONTOKING_API_TOKEN` tiene acceso a `auth:sanctum` (`/api/disponibilidad`)? | **Abierta** → backend |
| P2 | ¿`GET /api/disponibilidad` acepta múltiples fechas o una por llamada? | **Abierta** → backend |
| P3 | ¿Existen `ci_paciente` y `seguro_paciente` en el CRM e `InsuranceService::verifyWithParams()`? | **CERRADA** — Sí, confirmado |
| P4 | ¿La duración de cita varía por servicio o es fija (60 min por defecto)? | **Abierta** → backend |

---

## Prioridades

| REQ | Prioridad | Bloqueante hoy |
|-----|-----------|----------------|
| REQ-1 | Crítica | Sí — necesitamos respuesta a P1 y P2 para hacer el fix |
| REQ-2 | Alta | No |
| REQ-3 | Alta | No — infraestructura ya existe |
| REQ-4 | Baja | No |

---

*Plataforma Agente WhatsApp — Lead Dev*
*Producción: https://odontoking.wappy.dev*
