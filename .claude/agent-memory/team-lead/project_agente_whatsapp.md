---
name: project-agente-whatsapp
description: Planning 08 para el agente de WhatsApp en odontoking.wappy.dev — 4 REQs activos con 7 tareas
metadata:
  type: project
---

El agente de WhatsApp en `https://odontoking.wappy.dev` consume la API del CRM. Se creó el planning `planning/08-agente-whatsapp/` con 7 tareas.

**REQ-1 (BLOQUEANTE):** El agente usa `GET /api/doctors` (sin `end_time`). El fix es usar `GET /api/disponibilidad?doctorId=X&date=Y` (bajo `auth:sanctum`). La tarea 8.1 verifica P1 (token) y P2 (date acepta una sola fecha por llamada).

**REQ-2:** Campo `duration_minutes` (int, nullable) en tabla `products`. Default 60 min lo aplica el agente, la API devuelve null si no configurado. Tareas 8.2 (migración + API) y 8.3 (tests).

**REQ-3:** Nuevo `GET /api/v1/insurance/verify?ci_paciente=X&seguro_paciente=Y`. Reutiliza `InsuranceService::verifyWithParams()`. Lookup EAV por `ci_paciente` en `attribute_values` (text_value). Anti-enumeración: misma respuesta para "no encontrado" y "sin seguro". Tareas 8.4 (controlador), 8.5 (rate limit 10/min + audit log SHA-256), 8.6 (tests).

**REQ-4:** `GET /api/doctors/{id}` ya existe en `DoctorController::show()` bajo middleware `api` (sin Sanctum). Tarea 8.7 confirma que funciona y que los slots incluyen `end_time`.

**Why:** El agente está en producción y tiene bugs de disponibilidad (sin end_time) y asume duraciones fijas de 60 min para todos los servicios.

**How to apply:** En futuras tareas relacionadas con el agente WhatsApp, los endpoints de referencia son: `/api/disponibilidad` (auth:sanctum), `/api/public/products` (público), `/api/v1/insurance/verify` (auth:sanctum, rate 10/min), `/api/doctors/{id}` (público).
