# Plan de implementación: Tablero e inbox por asesor

Spec: `docs/specs/tablero-por-asesor.md` (aprobado el 16/10/2026). Referencia: ponce_de_leon, implementado el 14/10
(`Admin/src/Helpers/Alcance.php`, `Whatsapp/src/Services/ConversacionesVisibles.php`, `LeadRepository::sinCambioDeAsesor()`).

Absorbe lo que quedaba de `tasks/todo-tablero-consistente.md`:
- **T6** (por asesor: solo ejecutivos + nota de otros) → tarea **A3**.
- **T7** (ACL en todas las tarjetas) → tareas **A1–A2**, con `Alcance` en vez de `bouncer()`.
- **T8, T9, T10** siguen en ese plan; se hacen después y no dependen de este.

Siguen pendientes y fuera de alcance: **"Sin ciudad" (P2)** y **"Tiempo en responder" (P5)** del spec tablero-consistente.

## Decisiones de arquitectura

- **Una regla por módulo, copiada de ponce.**
  - `Webkul\Admin\Helpers\Alcance`: `veTotal()`, `sinRestriccion()`, `userId()`, `leads()`. Los roles salen de config
    (`reporting.roles_con_total` = Administrador, Supervisores; `reporting.roles_sin_restriccion` = Administrador).
  - `AbstractReporting::asesoresVisibles()` pasa a devolver `Alcance::userId()` (null o `[id]`). Así **todo** lo que ya usa
    `pedidosDelTablero()` (productos, ventas, aviso de entregados) queda con el alcance nuevo sin tocarlo.
- **El inbox reusa su propia regla de dueño.** `$deAsesor` sale de `ChatController` y pasa a
  `Whatsapp\Services\ConversacionesVisibles::deAsesor($query, $userId)`. El filtro "Míos" y la visibilidad usan
  el mismo método, así que son el mismo conjunto por construcción (D8).
- **404, no 403,** para conversaciones, contactos y pedidos ajenos en el inbox (no confirma que existen).
- **El servidor fuerza `user_id`.** `DashboardController::aplicarAlcance()`: para quien no ve el total, se pisa
  cualquier `user_id` de la URL con el suyo, en `stats` y en `export`.
- **El pool no se abre a los ejecutivos.** El reparto ya asigna por turno (`Handoff::ownerFor()`). El filtro "pool" y
  la acción "Reasignar" quedan solo para quien ve el total. Si una derivación queda sin asignar, al volver a pedirla se reintenta el turno.
- **El cambio de asesor de un pedido se valida en el repositorio** (`LeadRepository`), no en cada controlador: cubre la ficha,
  la edición en línea, la actualización masiva y la API. El reparto automático no pasa por un usuario logueado,
  así que `Alcance` (sin usuario = sin restricción) lo deja pasar.
- Sin cambios de esquema. No se tocan los datos de la matriz ni los permisos de los usuarios.

## Grafo de dependencias

```
A1 Alcance + scope del tablero (stats/export, user_id forzado)
 ├── A2 Las tarjetas que faltan, con alcance + test que recorre todos los type
 ├── A3 Ranking por asesor: el ejecutivo ve su fila; solo ejecutivos; nota de otros (ex T6)
 └── A4 Excel + títulos «Mis …»
B1 ConversacionesVisibles + listado del inbox (depende de A1: usa Alcance)
 └── B2 Todos los endpoints del inbox con exigir() + test que recorre todas las rutas
       └── B3 Pool y Reasignar solo para quien ve el total; reintento del turno
C1 Asesor de un pedido: solo Administrador y Supervisores (depende de A1)
C2 Contactos: ficha ajena 404 y «Asesor Asignado» solo Admin/Supervisores (depende de A1)
```

Se pueden hacer en paralelo, una vez hecho A1: la rama A, la rama B y C1.

## Tareas

### Fase A · Tablero
- **A1** · `Alcance`, config de roles, `asesoresVisibles()` vía `Alcance`, y `aplicarAlcance()` en `stats` y `export` (M)
- **A2** · Alcance en las tarjetas que faltan: prospectos, ventas, embudo, Evolución "Pedidos entregados", contactos, más un test que recorre todos los `type` (M)
- **A3** · Ranking por asesor (ex T6): el ejecutivo ve solo su fila, las filas son solo de ejecutivos, y la nota "N de otros usuarios o sin asesor" (M)
- **A4** · Excel con el mismo alcance y títulos "Mis …" para los ejecutivos (S)

### Checkpoint A · revisar con el usuario
- [ ] Auditoría contra producción, solo lectura, como Javier, Mariano, Bulmaro y Mauricio: el ejecutivo solo ve sus pedidos; el administrador y el supervisor ven lo mismo que antes.

### Fase B · Inbox de WhatsApp
- **B1** · `ConversacionesVisibles` (D8, D9, D11) aplicada al listado; "Míos" usa el mismo método (M)
- **B2** · `exigir()` en cada endpoint de `InboxController` y `ChatController`, más un test que recorre todas las rutas y espera 404 (M)
- **B3** · Pool y "Reasignar" solo para quien ve el total; reintento del turno para una derivación sin asignar (S)

### Checkpoint B · revisar con el usuario
- [ ] Como Bulmaro, el inbox muestra 160 conversaciones (o las que tenga ese día) y ninguna de Eduardo. Como Javier o Mariano, 393.
- [ ] Deploy: `view:clear` y **reiniciar el worker de la cola whatsapp** (supervisord).

### Fase C · Pedidos y contactos
- **C1** · Asesor de un pedido: solo Administrador y Supervisores, en el servidor y en la UI (M)
- **C2** · Contactos: la ficha, la edición y el guardado de un contacto ajeno dan 404; "Asesor Asignado" solo lo cambian Administrador y Supervisores (M). Hoy un ejecutivo puede abrir por URL el contacto de otro y asignárselo.

### Checkpoint final
- [ ] Tests de Dashboard, Whatsapp y Lead en verde (cada carpeta por separado: la suite completa ya estaba rota por `ciudadDe()`).
- [ ] Los criterios de éxito del spec, verificados en producción en modo lectura.

## Riesgos y mitigación

| Riesgo | Impacto | Mitigación |
|---|---|---|
| Un endpoint del inbox queda sin `exigir()` y filtra una conversación ajena | Alto | El test de B2 recorre `Route::getRoutes()` del módulo y falla si alguna ruta con `{id}`, `phone` o `person` no responde 404. |
| Un `type` del tablero queda sin alcance | Alto | El test de A2 recorre `DashboardController::$typeFunctions` completo. |
| Al bloquear el cambio de asesor se rompe el reparto automático | Alto | `Alcance` sin usuario logueado = sin restricción, y un test del reparto (`Responsables::asignar`) dentro de C1. |
| El ejecutivo deja de ver una conversación derivada a él cuyo pedido es de otro | Medio | D8 incluye "derivación abierta asignada a él". Hoy hay 0 casos así. |
| Los tests corren contra la base real (con transacciones) | Medio | Fechas de 2020 y `DatabaseTransactions`; ningún test hace commit (igual que ponce). |
| El worker de la cola sigue con código viejo después del deploy | Medio | Reiniciar supervisord en el checkpoint B (ver memoria del proyecto). |

## Preguntas abiertas

Ninguna. La matriz de reparto queda como está (P5).
