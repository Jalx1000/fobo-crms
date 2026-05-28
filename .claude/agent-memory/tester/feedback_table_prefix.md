---
name: feedback-table-prefix
description: Nunca usar DB::table con prefijo 'od_' hardcodeado en tests — el ORM ya duplica el prefijo
metadata:
  type: feedback
---

En tests, nunca escribir `DB::table('od_persons')` — el driver ya aplica el prefijo configurado, resultando en `od_od_persons` y un QueryException.

**Why:** El `.env.testing` tiene `DB_PREFIX=od_` y el ORM añade ese prefijo automáticamente. Pasar el prefijo manualmente lo duplica.

**How to apply:** Para actualizaciones directas en BD dentro de tests, usar el modelo Eloquent: `Person::where('id', $id)->update([...])` o `$person->update([...])` si la instancia está disponible.
