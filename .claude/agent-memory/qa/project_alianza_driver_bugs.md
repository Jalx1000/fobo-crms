---
name: project-alianza-driver-bugs
description: Bugs confirmados en AlianzaDriver y estado de cobertura de tests tras la revisión de 2026-05-28
metadata:
  type: project
---

Tres bugs confirmados en `AlianzaDriver::checkCoverage()` tras revisión QA de 2026-05-28.

**Why:** El driver fue refactorizado de un proxy n8n a llamada directa MedinetGo (commits 727cee6, 7c76812, c03754c) sin tests que cubrieran los escenarios de fallo de la API de Alianza.

**BUG-1** (`checkCoverage` línea ~98): No verifica `$response->failed()` antes de leer `$body['messageError']`. Cuando `OdontologyCoverage` devuelve HTTP 500 el body es null, `strtolower(null)` produce string vacío, la condición EN_MORA no se cumple y retorna `NO_REGISTRADO` en lugar de `INDETERMINADO`.
- Fix: añadir `if ($response->failed()) { return $this->indeterminate(...); }` al inicio del bloque post-login.

**BUG-2** (línea ~147): `NO_REGISTRADO` retorna `'success' => false`. Debería ser `true` — el paciente no tiene cobertura pero el servicio respondió correctamente. `NacionalVidaDriver` lo hace bien.
- Consecuencia: el UI trata `NO_REGISTRADO` como error técnico, no como respuesta de negocio.

**BUG-3** (línea ~137): `EN_MORA` también retorna `'success' => false` por el mismo motivo.

**Patrón Http::fake crítico:** `Http::fake` con URL exacta (`devnet.alianza.com.bo/ApiGateway/OdontologyCoverage`) NO intercepta el GET porque incluye query params. Hay que usar el wildcard `devnet.alianza.com.bo/*` y `Http::sequence()` para las dos peticiones del driver (login + coverage).

**How to apply:** Los tests en `AlianzaDriverTest.php` tienen los 3 bugs marcados con `->todo()`. Al corregir el código, quitar los `->todo()` y los tests deben pasar. [[feedback-http-fake-closures]]
