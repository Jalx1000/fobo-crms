# Spec: Pedidos sin ciudad — asesor por producto a la hora

Estado: v1 y **v2 implementadas** (16/10/2026). La v2 considera la ciudad y el producto que el cliente dijo dentro de la hora.

## Objetivo

Un pedido que nace en «Sin ciudad» se asigna, **a la hora**, al asesor de cobertura nacional
(«Bolivia») que atiende **su producto**. Sin derivar: la IA sigue respondiendo y no se manda correo.

**Por qué:** el pedido sin ciudad nace con un asesor por turno, antes de saber el producto (el
agente lo pregunta después). El 16/10 había 113 en NO ATENDIDOS con más de 1 h; 19 estaban con el
asesor equivocado para su producto. Se corrigieron a mano (respaldo
`storage/app/respaldos/reasignar-sin-ciudad-20261016-102032.json`); esta regla lo hace sola.

## Regla

Al crearse un pedido en el pipeline «Sin ciudad» se agenda `AsignarSinCiudad` para dentro de
**60 minutos** (`cotizacion.sin_ciudad.asignar_minutos`). Al correr:

1. **Solo actúa si sigue igual:** el pedido sigue en «Sin ciudad», en NO ATENDIDOS, sin derivación
   abierta y sin ningún mensaje de un asesor (humano) en su conversación. Si algo cambió, no hace nada.
2. **El asesor sale de la matriz de reparto**, casillas «Bolivia» (hoy: Stretch, Hules y Tapas →
   Bulmaro; Magia Verde → Eduardo; Mariano no está en la matriz):
   - con producto (el primero del pedido): `Responsables::candidatos($producto, null)`;
   - sin producto: el turno «sin ciudad» (`Responsables::asesorPorDefecto()`).
3. **No rota por rotar:** si el asesor actual ya es uno de los válidos (para el producto o, sin
   producto, uno de cobertura nacional del turno), se queda con él. Solo si no lo es, se asigna el
   siguiente en el turno.
4. **Con el pedido se mueven** su contacto, sus cotizaciones y la conversación asignada
   (`Responsables::todoConElPedido`, la misma regla del reparto).
5. **Nunca:** derivar, apagar la IA ni mandar correos. Se deja una nota en el pedido.

## Decisiones (16/10/2026)

| # | Decisión |
|---|---|
| D1 | Umbral de **1 hora** desde que nace el pedido. Sin horario: no avisa a nadie. |
| D2 | Asesor por **producto**, según la matriz «Bolivia», **sin Mariano**. |
| D3 | **No derivar**, IA encendida, **sin correos**. |
| D4 | Si el asesor actual ya es válido, no se cambia. |

## v2 — ciudad y producto dichos dentro de la hora (16/10)

**Caso real, pedido #6610** (Bruval Productos de Limpieza, nació 10:43 en «Sin ciudad» a nombre de
Eduardo). El primer mensaje del cliente fue «¿Cuáles son los tamaños disponibles del **Stretch Film**?».
El pedido no tiene producto cargado (`lead_products` vacío), así que la v1 lo trataba como «sin
producto»: Eduardo es de cobertura nacional → «válido» → no lo cambiaba. Stretch es de Bulmaro: a las
11:40 lo pasó a mano Danitza.

Hoy, además:
- Si el cliente **dice la ciudad** contestando el menú, `CiudadDesdeChat` ya lo pasa al pipeline de la
  ciudad y lo reasigna en el momento (producto × ciudad). Pero la v1 **deja de mirarlo** porque ya no
  está en «Sin ciudad»: si el producto lo dijo **después** de la ciudad, nadie lo corrige.
- El producto que se detecta en el chat (`CiudadDesdeChat::producto()`: palabras como «stretch»,
  «film», «bolsa», «hule», «tapa») **no** lo usa la v1.

### Regla v2

Al cumplirse la hora, para todo pedido que **nació** en «Sin ciudad»:

1. **Sigue esperando:** NO ATENDIDOS, sin derivación abierta y sin mensajes de un asesor. (Ya no se
   exige que siga en «Sin ciudad»: si dijo la ciudad, igual se revisa.)
2. **Ciudad:** la del pipeline si ya pasó a una ciudad; si no, la del contacto; si no, ninguna.
3. **Producto:** el cargado en el pedido; si no hay, **el que el cliente nombró en el chat**
   (`CiudadDesdeChat::producto()`, la misma detección que ya usa la reasignación por ciudad).
4. **Quién corresponde** (matriz de reparto, sin Mariano):

   | Dijo… | Válidos | Ejemplo |
   |---|---|---|
   | producto y ciudad | `candidatos(producto, ciudad)`: los de esa ciudad; si no hay, los nacionales | Hules + Santa Cruz → Fabio; Stretch + Santa Cruz → Bulmaro |
   | solo producto | nacionales del producto | Stretch → Bulmaro; Magia Verde → Eduardo |
   | solo ciudad | quien atiende **todos** los productos en esa ciudad; si no hay nadie, el turno nacional | Cochabamba → Mauricio; La Paz/El Alto → Gabriela; Santa Cruz → turno nacional |
   | nada | turno nacional «sin ciudad» (como la v1) | Bulmaro / Eduardo |

5. Si el asesor actual ya es válido, **no se cambia**; si no, el siguiente del turno entre los válidos.
6. Igual que la v1: se mueven contacto, cotizaciones y conversación (`todoConElPedido`); **nunca**
   derivar, apagar la IA ni mandar correos.

### Matriz cargada vs. tabla del 16/10

| Producto | Tabla del usuario | Matriz cargada hoy |
|---|---|---|
| Stretch Film | Bulmaro (Bolivia), Mauricio (CBBA), Gabriela (LP), Mariano (Bolivia) | igual, **sin Mariano** |
| Magia Verde | Eduardo (Bolivia), Mauricio, Gabriela, Mariano | igual, **sin Mariano** |
| Hules | Fabio (SC), Mauricio, Gabriela, Mariano (Bolivia) | igual **+ Bulmaro (Bolivia)**, sin Mariano |
| Tapas | Mauricio, Gabriela, Mariano (Bolivia) | igual **+ Bulmaro (Bolivia)**, sin Mariano |

Bulmaro cubre Hules y Tapas a nivel nacional en lugar de Mariano (decisión del 13/10: «debe ser sin
Mariano»). La v2 **no cambia la matriz**.

### Decisiones v2 (16/10, respondidas por el usuario)

- **Q1. Sin Mariano.** La matriz queda como está: Hules y Tapas nacionales → Bulmaro.
- **Q2. El producto detectado en el chat se carga en el pedido** (`ProductoDelPedido::registrar`, que
  solo agrega si el pedido no lo tiene).
- Quiénes son válidos se calcula **sin gastar el turno** de nadie
  (`Responsables::candidatos()` / `atiendenTodoEn()`); el turno solo se marca al asignar.

Simulación sobre producción (transacción deshecha, 16/10): de 17 pedidos que nacieron sin ciudad y
seguían esperando más de una hora, 4 cambiarían de asesor (#1906 → Eduardo; #1910, #1914 y #2622 →
Bulmaro) y 10 recibirían su producto. Los pedidos existentes **no** se tocan sin pedido explícito.

### Tests v2

1. #6610: «¿tamaños del Stretch Film?», sin ciudad, de Eduardo → a la hora pasa a Bulmaro.
2. Dijo Cochabamba (ya en ese pipeline) y después «bolsas» → Mauricio (si no lo era).
3. Dijo Santa Cruz y «hules» → Fabio; Santa Cruz y «stretch» → Bulmaro.
4. Solo La Paz, sin producto → Gabriela; solo Santa Cruz → turno nacional.
5. Si el actual ya es válido → no cambia. Si un asesor escribió, hay derivación abierta o ya no está
   en NO ATENDIDOS → no hace nada (igual que la v1).
6. Un pedido que nació con ciudad no se agenda (igual que la v1).

## Fuera de alcance

- Pedidos ya existentes (se resolvieron a mano el 16/10).
- Cambiar la matriz de reparto o sumar a Mariano.

## Comandos

```bash
C=$(docker ps --format '{{.Names}}' | grep imprimir_laravel)
docker exec -u www-data -w /code $C php artisan test tests/Feature/Whatsapp/AsignarSinCiudadTest.php
docker exec -u www-data -w /code $C php artisan test   # suite completa (no deja registros)
docker exec $C supervisorctl restart whatsapp           # el job corre en la cola whatsapp
```

## Estructura

```
packages/Webkul/Whatsapp/src/Jobs/AsignarSinCiudad.php        → el job (nuevo)
packages/Webkul/Admin/src/Listeners/Lead.php (o uno nuevo)     → agenda el job en lead.create.after
packages/Webkul/Product/src/Config/cotizacion.php              → sin_ciudad.asignar_minutos = 60
tests/Feature/Whatsapp/AsignarSinCiudadTest.php                → tests (nuevo)
```

## Tests

1. Pedido «Sin ciudad» con Magia Verde de Bulmaro → a la hora pasa a Eduardo (y su contacto).
2. Con Stretch y ya de Bulmaro → no cambia.
3. Sin producto y de un asesor nacional → no cambia; de alguien que no es nacional → turno.
4. Si el cliente dijo su ciudad, si un asesor ya escribió, si hay derivación abierta o si ya no
   está en NO ATENDIDOS → no hace nada.
5. Nunca abre derivación, nunca apaga la IA, nunca manda correo.
6. Crear un pedido en «Sin ciudad» agenda el job a 60 minutos; en otra ciudad, no.

## Criterios de éxito

- [x] Los 6 tests en verde y la suite completa sin dejar registros (los 4 fallos de esa corrida son de `APP_DEBUG=false` en el .env, ajenos a esto: con debug pasan).
- [x] Worker reiniciado (16/10, tras la v2).
- [ ] Un pedido nuevo sin ciudad aparece a la hora con el asesor de su producto y su ciudad.
- [x] v2: 10 tests del job en verde y suite completa (761) sin dejar registros.
