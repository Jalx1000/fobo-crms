# Spec: Pedidos sin ciudad — asesor por producto a la hora

Estado: **implementado** (16/10/2026).

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
- [ ] Worker reiniciado; un pedido nuevo sin ciudad aparece con el asesor de su producto a la hora.
