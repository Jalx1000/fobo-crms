# Spec: Módulo Distribuidor — Punto de Distribuidor

Estado: **v1 implementado y testeado** (8 tests verdes) — pendientes PA3/PA5 y el commit
Rama: `feat/whatsapp`
Fecha: 2026-10-01

> Este spec se escribió DESPUÉS de una primera implementación, no antes — se
> documenta así a propósito. Sacó a la luz dos defectos reales (D1, D2) y seis
> suposiciones silenciosas, todas resueltas abajo.

## Objetivo

Una sola pantalla táctil donde el personal de un distribuidor hace todo su
trabajo: elige su sucursal, busca o carga el contacto por teléfono, arma el
pedido con lo que esa sucursal vende, avanza el pedido de etapa, cobra, y abre
el WhatsApp del cliente.

**Usuario:** personal de distribución, rol nuevo `Distribuidor`. Trabaja de pie,
con una mano, en tablet o celular.

**Éxito:** alguien que nunca usó el CRM toma un pedido completo —contacto nuevo
incluido— sin que nadie le explique nada, y el pedido queda en el tablero con la
ciudad, el precio y el cobro correctos.

## Qué está construido (y verificado)

| Pieza | Estado |
|---|---|
| `packages/Webkul/Distribuidor/` | 14 archivos |
| Migraciones | `distribuidor_pagos` + rol `Distribuidor` (id 70, 14 permisos) — ✅ corridas |
| Rutas | 8, confirmadas en `route:list` |
| `Services/Catalogo` | ✅ probado: botellón $7.500 en Barrio Alto Comedero y Palpalá, $8.000 en San Salvador de Jujuy |
| `Services/PuntoPedido` | crear / avanzar etapa / resolver contacto por teléfono |
| `Resources/views/punto.blade.php` | 554 líneas, táctil responsive |
| Registro | autoload, `concord.php`, `config/app.php`, `tailwind.config.js` |
| Tests | ❌ **ninguno todavía** |

## Hallazgo que fijó el diseño

`ciudad_producto_sucursal` (atributo 67, multiselect sobre productos) se guarda
como **string de ids de sucursal en `text_value`** (`'1,9'`, `'8'`, `'10'`), donde
**10 = Todas** y siempre entra.

Y hay **dos productos con el mismo nombre y distinto precio**:

```
Botellón de agua purificada 20 L   $8.000   sucursal 8 (San Salvador de Jujuy)
Botellón de agua purificada 20 L   $7.500   sucursales 1,9 (Barrio Alto Comedero, Palpalá)
```

Por eso la sucursal se elige **primero y vive fija en el encabezado**, no como un
paso del flujo: un catálogo sin filtrar hace que el distribuidor cobre el precio
de otra ciudad, y nadie se entera hasta que cuadran la caja.

## Suposiciones que tomé sin preguntar

Esto es lo que el spec existe para sacar a la luz.

- **S1 — Sucursal → ciudad por nombre.** El pedido necesita un pipeline (= ciudad);
  el Punto lo deduce matcheando el **nombre** de la sucursal contra el del
  pipeline. Hoy coinciden los tres. Si alguna vez dejan de coincidir, el Punto
  avisa y no abre el pedido en vez de adivinar — pero sigue siendo un acople por
  string entre dos tablas. **Es la suposición más frágil de todo el módulo.**
- **S2 — Canal y tipo fijos.** `lead_source_id` y `lead_type_id` son obligatorios
  en este CRM; el Punto no los pregunta y los toma de config (Directo / Nuevo
  Cliente). "Nuevo Cliente" va a ser falso para la mayoría de los pedidos.
- **S3 — "Hoy" es del usuario logueado.** Un distribuidor ve sólo sus pedidos del
  día, no los de otro. Decidido así para no mostrar la caja ajena.
- **S4 — Entrega = avanzar etapa.** Elegiste la opción con "firma/foto" y **la
  dejé afuera**: hoy "Entregar" sólo mueve el pedido a `pedido-entregado`. No hay
  foto ni firma ni registro de quién entregó más allá del historial del lead.
- **S5 — WhatsApp es un enlace, no está embebido.** El botón lleva a la vista del
  lead, donde está la pestaña WhatsApp. No se incrustó `whatsapp::inbox` dentro
  del Punto. Es una desviación de "WhatsApp dentro del punto".
- **S6 — Los cobros no se pueden editar ni borrar.** Sólo se agregan. Un cobro mal
  cargado hoy se arregla por la base.

## Decisiones tomadas (2026-10-01)

- **D1 → cantidades enteras.** Los productos no se fraccionan. La validación pasó
  a `integer|min:1`, alineada con `lead_products.quantity` que es `int`. Antes
  `numeric` dejaba pasar 2,5 y MySQL lo truncaba a 2 con el total calculado sobre
  2,5. **Cubierto por test.**
- **D2 → el cobro queda libre.** No se topea al saldo: una seña que excede o un
  redondeo en mano son reales. El saldo puede quedar negativo y eso es
  información, no un error.
- **Precio editable.** El precio llega precargado con el del catálogo de la
  sucursal y el distribuidor lo puede cambiar (descuento, precio acordado). La UI
  marca en ámbar cuándo difiere del de lista, para que el cambio sea visible y no
  un error silencioso. **Cubierto por test.**
- **S4 → se registra cuándo y quién entregó.** Tabla `distribuidor_entregas` con
  `entregado_at` explícito. No se deduce de la etapa ni de `updated_at`: ese se
  mueve con cualquier edición posterior y dejaría de ser la fecha de entrega, que
  es justo el dato que se pregunta cuando un cliente dice que no le llegó. Sin
  foto ni firma (ver PA3).
- **S5 → todo por WhatsApp.** El botón del pedido lleva a
  `/distribuidor/whatsapp/{id}`, una página propia que incluye `whatsapp::inbox`
  —el mismo componente que usa la ficha del asesor, no una segunda
  implementación— con un "Volver al Punto" de 64px.
- **S1/PA6 → el puente vive en el servidor, en un solo lugar.**
  `Catalogo::ciudadDeSucursal()` resuelve sucursal→ciudad por NOMBRE normalizado
  (sin acentos, sin caso). Se eliminó la deducción duplicada que había en JS.
- **Nuevo requerimiento → pendientes por ciudad.** La vista de pedidos tiene dos
  filtros: **Pendientes** (default) y *Mis pedidos de hoy*, más chips de ciudad.
  "Pendientes" NO filtra por dueño ni por fecha: lo que falta entregar lo levanta
  quien esté en la calle, y un pedido de antes de ayer sin entregar es más
  urgente, no menos.

## Hallazgos del modelo de datos

**Los ids de ciudad y sucursal se contradicen.** No es un detalle:

```
lead_pipelines:  1 Palpalá   3 Barrio Alto Comedero   4 San Salvador de Jujuy
warehouses:      1 Barrio Alto Comedero   8 San Salvador de Jujuy   9 Palpalá   10 Todas
```

`1` es Palpalá como ciudad y Barrio Alto Comedero como sucursal. Tratarlos como
intercambiables asigna el pedido a otra ciudad **sin producir ningún error**. Por
eso el puente es por nombre y está centralizado. Verificado por test:

```
sucursal 1 Barrio Alto Comedero -> ciudad 3
sucursal 9 Palpalá              -> ciudad 1
sucursal 8 San Salvador de Jujuy-> ciudad 4
```

**El ACL niega por dos capas, con códigos distintos.** `admin.distribuidor.index`
está en el mapa del ACL, así que el Bouncer de Krayin lo corta con **401** antes
de que corra el middleware del módulo. Las rutas que escriben no están en ese
mapa: el Bouncer las deja pasar y las corta `EnsureDistribuidorAccess` con **403**.
Sin el middleware propio, las rutas de escritura quedaban abiertas a cualquier
usuario con sesión.

**Aparecieron pipelines nuevos.** `Bogotá Export 1019` y `Bogotá Export 9977` se
crearon después de que se configuró `whatsapp.handoff.city_agents` {1,3,4}→11, y
quedaron sin asesor. El `pool_email` los atrapa, pero caen en el Encargado de
Jujuy. El mapa explícito se desactualizó en un mes.

## Tech Stack

Sin dependencias nuevas. Laravel 10 / PHP 8.2, Krayin sobre Concord, Blade +
Vue 3 global (`app.component`) + Tailwind, Pest 2.

## Comandos

```bash
C=$(docker ps --format '{{.Names}}' | grep -i 'sensia-laravel')

docker exec -w /code $C php artisan migrate --force
docker exec -w /code $C ./vendor/bin/pest --filter="Distribuidor"
docker exec -w /code/packages/Webkul/Admin $C bash -lc '. ~/.nvm/nvm.sh; npm run build'
./vendor/bin/pint packages/Webkul/Distribuidor/src
```

## Estructura

```
packages/Webkul/Distribuidor/src/
  Config/          distribuidor.php · acl.php · menu.php
  Services/        Catalogo (productos por sucursal) · PuntoPedido (ciclo del pedido)
  Models/          Pago (+Proxy) · Contracts/Pago
  Repositories/    PagoRepository
  Http/            Controllers/PuntoController · Middleware/EnsureDistribuidorAccess
  Database/Migrations/
  Resources/views/ punto.blade.php
tests/Feature/     DistribuidorPunto*.php
```

## Estilo

El del repo: comentarios en español que explican **por qué**, con la evidencia que
motivó la decisión. En la UI, las tres reglas táctiles van documentadas en el
blade y se respetan sin excepción:

```
1. 64px en acciones principales, 56px en secundarias.
2. Nada depende de `hover` — en táctil no existe.
3. Cantidades e importes con teclado numérico nativo y steppers de ±.
```

## Estrategia de testing

Pest 2, `tests/Feature/`. **La suite corre contra la base real**; el guardarraíl
de `tests/TestCase.php` corta `TRUNCATE`/`DELETE sin WHERE`/`DROP`. Los tests
nuevos borran sólo por id lo que crearon, y usan las sucursales/ciudades reales
por índice (igual que se arreglaron los de WhatsApp) en vez de fijar nombres.

Cobertura mínima para dar el v1 por terminado:

1. 403 sin el permiso `distribuidor`; 200 con el rol.
2. El catálogo de una sucursal devuelve el precio de ESA sucursal (el caso del botellón).
3. Crear pedido deja el lead en la etapa inicial de la ciudad correcta, con sus productos y el `lead_value` igual a la suma.
4. Avanzar etapa se queda dentro del pipeline del pedido.
5. Un cobro parcial deja el saldo correcto; dos cobros suman.
6. Buscar por teléfono encuentra al contacto existente aunque esté guardado con otro formato (no duplica).

## Límites

- **Siempre:** pasar por `LeadRepository`/`PersonRepository` (ahí viven los eventos, la sincronización de ciudad y la dedup por teléfono); verificar `migrate:status` antes de migrar; mantener los objetivos táctiles de 56/64px.
- **Preguntar antes:** cambiar el tipo de `lead_products.quantity`; tocar el ACL de otros roles; embeber el inbox de WhatsApp; agregar dependencias.
- **Nunca:** escribir el lead con Eloquent directo desde el Punto; mostrar el catálogo sin filtrar por sucursal; borrar un cobro desde la UI.

## Criterios de éxito

1. `pest --filter="Distribuidor"` pasa completo.
2. Un usuario con rol `Distribuidor` abre `/admin/distribuidor` y NO ve tablero ni configuración.
3. Un usuario sin el permiso recibe 403 en `/admin/distribuidor/pedido` (no sólo el menú oculto).
4. El catálogo muestra el precio de la sucursal elegida — verificado con el botellón en las tres.
5. Pedido completo (contacto nuevo + 2 productos) en **≤ 6 toques** después de elegir sucursal.
6. Usable a 390px de ancho sin scroll horizontal, y a 768px en dos columnas.
7. Ningún objetivo táctil por debajo de 56px.
8. D1 y D2 resueltos.

## Preguntas abiertas

- **PA3** — ¿La entrega necesita foto o firma? Hoy se registra fecha, hora y quién.
- **PA5** — ¿Qué canal y tipo de cliente corresponde a un pedido tomado en mano?
  Hoy quedan fijos en Directo / Nuevo Cliente, y "Nuevo Cliente" va a ser falso
  para la mayoría.
- **PA7** — ¿Los pedidos pendientes de TODAS las ciudades los puede ver cualquier
  distribuidor, o cada uno sólo los de su ciudad? Hoy ve todas y puede filtrar.
- **PA8** — Las dos ciudades "Bogotá Export" aparecen en los filtros del Punto.
  ¿Son reales, o datos de prueba que hay que excluir?
