# Informe de Integración - Alianza Seguros × Odontoking CRM

**Fecha:** 2026-06-02
**Versión:** 1.0
**Preparado por:** Equipo Técnico Odontoking / Sofopolis Srl

---

## Índice

1. [Resumen ejecutivo](#1-resumen-ejecutivo)
2. [Inventario de integración](#2-inventario-de-integración)
3. [Diagramas de flujo](#3-diagramas-de-flujo)
4. [Diagrama de actividades - Ciclo completo](#4-diagrama-de-actividades--ciclo-completo)
5. [Configuración y entornos](#5-configuración-y-entornos)

---

## 1. Resumen ejecutivo

Odontoking CRM integra la API de Alianza Seguros para verificar en tiempo real la cobertura dental de los pacientes. La integración opera en dos flujos principales:

- **Verificación desde el panel administrativo:** el operador consulta manualmente el seguro de un paciente desde su perfil.
- **Verificación desde el agente WhatsApp:** verificación automática por CI a través de un webhook n8n, con respuesta anti-enumeración.

| # | Flujo | Disparador |
|---|---|---|
| A | Verificación manual desde el CRM | Admin panel |
| B | Verificación desde creación de paciente | Formulario de alta |
| C | Verificación vía API REST (agente WhatsApp) | GET /api/v1/insurance/verify |

---

## 2. Inventario de integración

### 2.1 Configuración

| Variable de entorno | Descripción |
|---|---|
| `ALIANZA_USER` | Usuario de autenticación para la API de Alianza (`cli.odontok`) |
| `ALIANZA_PASS` | Contraseña de autenticación para la API de Alianza |

### 2.2 Servicios

| Clase | Responsabilidad |
|---|---|
| `AlianzaDriver` | Driver de verificación de cobertura. Realiza login y consulta de cobertura contra la API de Alianza. |
| `InsuranceService` | Orquestador central. Resuelve el driver según el seguro del paciente, gestiona caché y persiste resultados. |

### 2.3 Endpoints expuestos por Odontoking (recepción)

| Método | URI | Descripción | Seguridad |
|---|---|---|---|
| `POST` | `/api/insurance/verify` | Verificación directa con person_id + CI + tipo de seguro | Bearer token Sanctum |
| `GET` | `/api/v1/insurance/verify` | Verificación para agente WhatsApp por CI | Bearer token, rate limit 10 req/min |

### 2.4 Endpoints de Alianza Seguros consumidos por Odontoking

| Método HTTP | Endpoint | Propósito |
|---|---|---|
| `POST` | `{base_url}/LoginUserProv` | Autenticación - obtiene `accessToken` y `nUsercode` |
| `GET` | `{base_url}/OdontologyCoverage?ci={ci}&nUsercode={nUsercode}` | Verificación de cobertura dental por CI |

> **Base URL actual (desarrollo):** `https://devnet.alianza.com.bo/ApiGateway`
> **Base URL producción:** a proveer por Alianza Seguros

### 2.5 Estructura de la respuesta de cobertura

Campos que Odontoking extrae de la respuesta de `OdontologyCoverage`:

| Campo Alianza | Uso en Odontoking |
|---|---|
| `ESTADO` | Estado del asegurado (`VIGENTE`, `EN MORA`, etc.) |
| `NRO. DOCUMENTO` | CI del asegurado |
| `CONTRATANTE` | Nombre del contratante |
| `NOMBRE COMPLETO` | Nombre del paciente |
| `EDAD` | Edad |
| `CODIGO DE ASEGURADO` | Código interno Alianza |
| `COBERTURA ADICIONAL ODONTOLOGICA` | Tipo de cobertura dental |
| `COASEGURO ODONTOLOGICO` | Porcentaje de coaseguro |
| `CLINICA ODONTOLOGICA` | Clínica asignada |
| `RELACION` | Relación con el contratante |
| `FECHA DE INGRESO` | Fecha de ingreso al seguro |

### 2.6 Estados manejados por Odontoking

| Estado interno | Condición | Acción en CRM |
|---|---|---|
| `VIGENTE` | `ESTADO == VIGENTE` en respuesta Alianza | Badge verde, nota de actividad creada |
| `EN_MORA` | `messageError` contiene "pago pendiente" | Badge rojo, nota de actividad creada |
| `NO_REGISTRADO` | Respuesta exitosa pero sin cobertura | Badge amarillo |
| `INDETERMINADO` | Error de conexión o autenticación fallida | Sin badge, log de error |
| `SIN_SEGURO` | Paciente no tiene seguro registrado en el CRM | Badge amarillo |

### 2.7 Persistencia

| Tabla | Campo | Propósito |
|---|---|---|
| `persons` | `insurance_status` (string, nullable) | Último estado de cobertura obtenido |
| `persons` | `insurance_checked_at` (timestamp, nullable) | Fecha y hora de la última verificación |
| `attribute_values` | `estado_seguro_paciente` | Atributo custom con el estado del seguro (por persona) |
| `attribute_values` | `estado_seguro_paciente_cita` | Atributo custom replicado en cada cita/lead del paciente |
| `activities` | tipo `note` | Nota de actividad creada automáticamente con el detalle de cobertura |
| `insurance_audit_logs` | `token_hash`, `ci_hash`, `seguro_hash`, `result`, `ip_address` | Log de auditoría de cada verificación (datos hasheados, sin PII en claro) |

---

## 3. Diagramas de flujo

### Flujo A: Verificación manual desde el panel CRM

```mermaid
flowchart TD
    A([Operador hace clic\nVerificar Seguro]) --> B[GET /admin/contacts/persons/insurance-status/id]
    B --> C[InsuranceService::verify]
    C --> D[Leer atributos del paciente\nci_paciente + seguro_paciente]
    D --> E{¿Tiene seguro\nregistrado?}
    E -- No o sin seguro --> F([Respuesta: SIN_SEGURO])
    E -- Sí --> G{¿Resultado\nen caché?}
    G -- Sí --> H([Retornar resultado cacheado])
    G -- No --> I[AlianzaDriver::verify]
    I --> J[POST LoginUserProv\nuser + password]
    J --> K{¿Login\nexitoso?}
    K -- No --> L[updateInsuranceAttributes INDETERMINADO]
    L --> M([Respuesta: INDETERMINADO])
    K -- Sí --> N[GET OdontologyCoverage\nci + nUsercode + Bearer token]
    N --> O{¿Respuesta\nexitosa?}
    O -- Sí, VIGENTE --> P[updateInsuranceAttributes VIGENTE\nBadge verde]
    O -- Sí, EN MORA --> Q[updateInsuranceAttributes EN_MORA\nBadge rojo]
    O -- Sin cobertura --> R[updateInsuranceAttributes NO_REGISTRADO]
    O -- Error --> S[updateInsuranceAttributes INDETERMINADO]
    P & Q --> T[InsuranceService::createNoteActivity\nnota en perfil + leads del paciente]
    T --> U[Guardar en persons\ninsurance_status + insurance_checked_at]
    R & S --> U
    U --> V([Mostrar resultado en panel])
```

---

### Flujo B: Verificación desde formulario de creación de paciente

```mermaid
flowchart TD
    A([Operador llena formulario\nde nuevo paciente]) --> B[Ingresa CI + tipo de seguro]
    B --> C[InsuranceService::verifyWithParams\nci + seguroName]
    C --> D{seguroName\ncontiene alianza?}
    D -- No --> E[Otro driver o INDETERMINADO]
    D -- Sí --> F[AlianzaDriver::verify\ncon stdClass mínimo como person]
    F --> G[POST LoginUserProv]
    G --> H{¿Token OK?}
    H -- No --> I([INDETERMINADO])
    H -- Sí --> J[GET OdontologyCoverage]
    J --> K{¿Cobertura?}
    K -- VIGENTE --> L([Badge verde + datos de cobertura])
    K -- EN_MORA --> M([Badge rojo + mensaje de mora])
    K -- Sin registro --> N([Badge amarillo])
    K -- Error --> O([INDETERMINADO])
```

---

### Flujo C: Verificación vía API REST - Agente WhatsApp

```mermaid
flowchart TD
    WA([Agente WhatsApp\nrecibe CI del paciente]) --> R[GET /api/v1/insurance/verify\nci_paciente + seguro_paciente]
    R --> AUTH{¿Bearer token\nválido?}
    AUTH -- No --> E401([401 Unauthorized])
    AUTH -- Sí --> RL{¿Rate limit\n10 req/min?}
    RL -- Excedido --> E429([429 Too Many Requests])
    RL -- OK --> WH[POST webhook n8n\nempresa_seguro + carnet_identidad]
    WH --> WR{¿Webhook\nrespondió?}
    WR -- Error --> FA([has_insurance: false\npatient_found: false])
    WR -- OK --> EV{¿success y\ndata no vacío?}
    EV -- No --> FB([has_insurance: false\npatient_found: false])
    EV -- Sí --> AL[logAudit\ntoken_hash + ci_hash hasheados]
    AL --> RS([has_insurance: true\ninsurance_name, coverage_type\nvalid_until, patient_name])
```

---

## 4. Diagrama de actividades - Ciclo completo

```mermaid
flowchart TD
    IDLE([Sistema en espera])

    IDLE --> MA[Admin abre perfil de paciente\ny hace clic en Verificar Seguro]
    MA --> RC{Resultado\nen caché?}
    RC -- Sí --> CACHED([Mostrar resultado cacheado])
    RC -- No --> DRV[AlianzaDriver::verify]
    DRV --> LG[POST LoginUserProv\nObtener accessToken + nUsercode]
    LG --> LF{Login\nfallido?}
    LF -- Sí --> IND([INDETERMINADO\nError de autenticación])
    LF -- No --> CV[GET OdontologyCoverage\ncon CI del paciente]
    CV --> RES{Estado de\ncobertura}
    RES -- VIGENTE --> VIG[Badge verde\nActualizar atributos paciente]
    RES -- EN_MORA --> MORA[Badge rojo\nActualizar atributos paciente]
    RES -- Sin registro --> NOREG([NO_REGISTRADO\nBadge amarillo])
    RES -- Error HTTP --> INDERR([INDETERMINADO])
    VIG & MORA --> NOTE[Crear nota de actividad\nen perfil del paciente y sus citas]
    NOTE --> PERSIST[Guardar insurance_status\ny insurance_checked_at en BD]
    PERSIST --> CACHE[Guardar en caché\nTTL configurable]
    CACHE --> SHOW([Mostrar resultado en panel])

    IDLE --> WAPI[GET /api/v1/insurance/verify\nAgente WhatsApp]
    WAPI --> ACHECK{Auth + Rate\nlimit OK?}
    ACHECK -- No --> ERRS([401 o 429])
    ACHECK -- Sí --> WHN8N[POST n8n webhook\nempresa_seguro + carnet_identidad]
    WHN8N --> WHRES{Respuesta\nwebhook}
    WHRES -- Error o sin cobertura --> NOINS([has_insurance: false])
    WHRES -- Con cobertura --> AUDIT[logAudit\nen insurance_audit_logs]
    AUDIT --> INSRES([has_insurance: true\nDatos de cobertura])
```

---

## 5. Configuración y entornos

### Variables de entorno

| Variable | Entorno desarrollo | Entorno producción | Notas |
|---|---|---|---|
| `ALIANZA_USER` | `cli.odontok` | A proveer por Alianza | Credencial de proveedor |
| `ALIANZA_PASS` | `97531` | A proveer por Alianza | Contraseña temporal en dev |
| `ALIANZA_BASE_URL` | `https://devnet.alianza.com.bo/ApiGateway` | URL a proveer por Alianza | Hardcodeada en `AlianzaDriver`, debe externalizarse para producción |

### IP del servidor Odontoking

| Entorno | IP | Ubicación |
|---|---|---|
| Desarrollo / Producción | `76.13.69.34` | Brasil - São Paulo (Ubuntu 24.04) |

### Caché de verificaciones

Los resultados de verificación se cachean para evitar consultas repetitivas a la API de Alianza:

| Parámetro | Valor |
|---|---|
| TTL por defecto | 3600 segundos (1 hora) |
| Configurable via | `services.insurance.cache_ttl` |
| Habilitado via | `services.insurance.cache_enabled` |
| Invalidación manual | `InsuranceService::forceVerify(personId)` |

### Auditoría

Cada verificación vía API queda registrada en `insurance_audit_logs` con datos hasheados (SHA-256). Nunca se almacena CI, token ni nombre en texto claro.

### Archivos clave de referencia

| Archivo | Descripción |
|---|---|
| `packages/Webkul/Admin/src/Services/Insurance/Drivers/AlianzaDriver.php` | Driver de verificación - login y consulta de cobertura |
| `packages/Webkul/Admin/src/Services/InsuranceService.php` | Orquestador de seguros con caché y persistencia |
| `packages/Webkul/Admin/src/Http/Controllers/Api/InsuranceController.php` | Endpoints REST de verificación |
