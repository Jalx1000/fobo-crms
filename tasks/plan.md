# Plan técnico — Sprint 08 · Módulo WhatsApp

Spec: [`tasks/spec-sprint-08-whatsapp.md`](./spec-sprint-08-whatsapp.md) · aprobado 2026-09-01
Tareas: [`tasks/todo.md`](./todo.md)
Rama: `kohlberg-wa-v2` desde `kohlberg-whatsapp`
Estimado: **18 días**

---

## 1. Componentes y dependencias

```
                    ┌─────────────────────────────┐
                    │  B0 · Contrato del gateway  │  ← cuello de botella
                    │  +sendTemplate +sendMedia   │
                    │  +sendLocation, match tipo  │
                    └──────────┬──────────────────┘
                               │ bloquea
              ┌────────────────┼────────────────┐
              ▼                ▼                ▼
      ┌──────────────┐  ┌─────────────┐  ┌──────────────┐
      │ A · Plantillas│  │ C · Ubicación│  │ F · Media    │
      │ envío+reporte │  │ picker mapa  │  │ + consolidar │
      └──────────────┘  └─────────────┘  └──────────────┘

      ┌──────────────┐        ┌──────────────┐
      │ B · Handoff  │ ─ ─ ─▶ │ E · Reactivar│   (no bloquea:
      └──────────────┘ define └──────────────┘    agrega el plazo largo)
        el plazo largo

      ┌──────────────┐
      │ D · Moneda Bs│  sin dependencias
      └──────────────┘
```

**Bloqueos duros:** B0 → {A, C, F}.
**Bloqueo blando:** B → E (E entrega el temporizador corto; B le agrega el largo).
**Sin dependencias:** D, E, B.

## 2. Orden de implementación

| Fase | Bloques | Días | Por qué acá |
|---|---|---|---|
| 1 | D, E | 1,5 | Cero dependencias, valor visible, y calientan el módulo sin tocar el gateway |
| 2 | B0 | 2 | Desbloquea todo lo demás. Nada de A/C/F puede empezar antes |
| 3 | A | 5 | El más grande. Su riesgo externo ya se disparó (R1): A1-A4 se construyen contra `hello_world`, y A6-A8 esperan a que Meta apruebe las de negocio |
| 4 | B | 4 | Independiente; se puede solapar con A si hay dos personas |
| 5 | C | 2,5 | Necesita B0; el grueso es frontend |
| 6 | F | 3 | Consolidación; cada pieza es independiente y se puede recortar si el sprint se estira |

**Qué se puede paralelizar con dos personas:** desde la fase 3, A (back+front) y B (back+front)
son disjuntos: A toca `TemplateCatalog`/`Dashboard`, B toca `Services\Handoff`/`AgentController`.
El único punto de contacto es `InboxController`, que ambos amplían — coordinar por método, no
por archivo.

**Qué NO se puede paralelizar:** nada contra B0. Ampliar el interface mientras otro escribe
contra la versión vieja garantiza un merge roto.

## 3. Decisiones técnicas tomadas

| # | Decisión | Alternativa descartada | Por qué |
|---|---|---|---|
| 1 | Handoff en columnas de `whatsapp_conversations` (imprimir) | tabla `whatsapp_handoffs` (c21) | c21 rutea al titular de un catálogo que kohlberg no tiene |
| 2 | Ubicación con picker Leaflet (imprimir) | ubicación por API gateada (c21) | Lo pedido es que el asesor elija el punto en un mapa |
| 3 | Moneda formateada en el módulo | `APP_CURRENCY=BOB` | Cambiar la moneda base afecta cotizaciones y exportaciones del CRM entero |
| 4 | Reactivación a 30 min por `.env` | valor fijo en código | Se pidió 30, pero el pedido original decía 8 h: que sea variable |
| 5 | Reporte filtrado por `type` + `sender='human'` | solo por `type` | El agente no manda plantillas hoy; filtrar solo por type mentiría el día que lo haga |
| 6 | Leaflet por CDN en runtime | bundle de Vite | ~150 KB que la mayoría de las sesiones nunca usa |

## 4. Riesgos y mitigación

| # | Riesgo | Prob. | Impacto | Mitigación |
|---|---|---|---|---|
| R1 | 🔴 **MATERIALIZADO (2026-09-01).** La cuenta tiene **una sola** plantilla: `hello_world`, en inglés, sin variables — la de ejemplo de Meta. Ninguna de negocio | — | alto | El código de A se construye igual contra `hello_world`; lo que queda bloqueado es la **validación real** (A6 en adelante) y el reporte, que no tendría qué mostrar. **Las plantillas de negocio se dan de alta ya** (P1b): Meta tarda 24-48 h |
| R2 | **Ampliar el interface `Gateway` rompe el arranque** si `KommoGateway` no implementa los métodos nuevos: fatal de PHP, no un 501 | alta | alto — el CRM no levanta | Los stubs de Kommo van **en el mismo commit** que el interface. Nunca dos commits |
| R3 | **El agente externo se rompe con el handoff.** Ya pasó en imprimir: el CRM devolvía objeto donde el agente tipaba int, el agente moría con ValidationError y seguía respondiendo 200 | media | alto — el bot deja de contestar en silencio | `toAgentArray()` con `assigned_user` entero, test dedicado, y **avisar al equipo del agente antes de desplegar B** |
| R4 | **Los tests nuevos crean datos en producción.** No hay base descartable | alta | medio | Guardarraíl ya instalado en `TestCase::setUp()` + `DatabaseTransactions` en todo test nuevo + limpieza acotada por id |
| R5 | **El CDN de Leaflet caído** deja el picker sin abrir | baja | bajo | Decidir self-host (pregunta abierta 3). El modal ya muestra un error legible si el script no carga |
| R6 | **E y B se pelean por el temporizador**: una derivación pedida por el cliente volvería al bot a los 30 min | alta si se ignora | medio | E se entrega con el plazo corto; B agrega `handoff_after_minutes` y el `reject()` correspondiente en el mismo bloque |
| R7 | **Ventana de 24 h cerrada al probar** el envío de plantillas | media | bajo | Es justamente el caso de uso: se prueba contra una conversación vieja a propósito |

## 5. Checkpoints de verificación

Entre fases, no dentro. No se avanza sin esto.

| Después de | Verificación | Cómo |
|---|---|---|
| Fase 1 (D, E) | La moneda dice Bs en los 5 lugares; el agente vuelve solo a los 30 min | Navegador + `whatsapp:reactivate-agent --dry-run` |
| Fase 2 (B0) | El contenedor arranca, los dos gateways compilan, un tipo no soportado da 501 | `php artisan test --filter=Gateway` + healthcheck |
| Fase 3 (A) | Una plantilla real llega a un teléfono real; el gráfico aparece solo para admin | Envío manual + login con rol Encargado |
| Fase 4 (B) | Derivación end-to-end con el agente real; 409 al segundo intento | Contra el agente en `test_mode` |
| Fase 5 (C) | El cliente recibe una ubicación **nativa**, no un link | Teléfono real |
| Fase 6 (F) | Un audio sale con el mime correcto y WhatsApp lo acepta | Teléfono real |

## 6. Estrategia de despliegue

- Cada fase es un PR contra `kohlberg-wa-v2`. La rama no se mergea a `kohlberg` hasta la fase 6.
- **Las migraciones se corren a mano en producción**, nunca en el arranque del contenedor.
- El bloque A y el B se estrenan con `ai.test_mode` activo (viene en F, pero **se adelanta a la
  fase 3** justamente para esto — es la única pieza de F que cambia de orden).
- Rollback: los tres bloques con migración (B, E) solo agregan columnas nullable. Revertir es
  volver el código atrás; las columnas pueden quedar.
