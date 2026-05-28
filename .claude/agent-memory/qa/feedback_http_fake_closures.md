---
name: feedback_http_fake_closures
description: Http::response() de Laravel no acepta closures como body — provoca "Array to string conversion" en Guzzle
metadata:
  type: feedback
---

`Http::response(fn() => [...], 200)` lanza `ErrorException: Array to string conversion` en BufferStream de Guzzle porque el body se concatena como string.

**Why:** Intenté usar un closure para contar cuántas veces se llamaba al endpoint OAuth. Causó fallo inmediato en el buffer de Guzzle.

**How to apply:** Para contar llamadas, usar `Http::sequence([...])` con respuestas estáticas y luego `Http::assertSentCount(n)` para verificar el número total de peticiones enviadas. Nunca pasar un callable como primer argumento de `Http::response()`.
