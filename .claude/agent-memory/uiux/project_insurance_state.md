---
name: project-insurance-verification-state
description: Implementación actual del seguro médico en la vista del paciente — endpoints, estados, lógica de verificación
metadata:
  type: project
---

# Estado actual de verificación de seguro

## Lo que ya existe en el sidebar

- **Botón "Seguro"** (naranja) en la barra de acciones rápidas del sidebar
- **Componente Vue `v-insurance-activity`**: abre modal para capturar CI + aseguradora, luego llama verificación
- Lógica de debounce para re-verificar automáticamente cuando cambia el atributo `seguro_paciente`

## Endpoints disponibles

- `POST {id}/verify-insurance` — verifica seguro del paciente
- `POST {id}/update-insurance` — actualiza datos y luego verifica
- `POST {id}/clear-insurance-cache` — limpia cache de verificación
- `POST verify-insurance-quick` — verificación sin contexto de persona (para crear cita)
- `GET insurance-options` — lista opciones de aseguradoras

## Atributos del paciente relevantes

- `ci_paciente` — carnet de identidad (atributo custom en `persons`)
- `seguro_paciente` — ID de la aseguradora (atributo select, tiene labels)

## Estados de verificación que retorna `InsuranceService::verify()`

- `VIGENTE` — cobertura activa
- `EN_MORA` — con deuda
- `NO_ENCONTRADO` — no encontrado en aseguradora
- `SIN_SEGURO` — sin seguro registrado
- `INDETERMINADO` — error o aseguradora no soportada

## Cache

`InsuranceService` usa cache con TTL de 3600 segundos (1 hora). No hay columna en BD para persistir el último resultado de verificación.

**Why:** No existe campo `insurance_status` ni `insurance_checked_at` en la tabla `persons`. El estado de verificación vive en cache (Redis/file). La tab nueva necesitaría leerlo del cache o añadir columnas a la BD para mostrar "última verificación hace N horas".

**How to apply:** Para mostrar el estado en la tab, usar el endpoint `verify-insurance` con un flag de "solo leer cache sin re-consultar" o agregar columnas a la migración.
