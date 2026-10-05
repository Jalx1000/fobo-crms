# Pendientes de SENSIA — tareas ordenadas por dependencia

Fuera de alcance por pedido del negocio: **flujo de delivery con delimitación de
zona**.

Orden: primero lo que no depende de nada ni de nadie, al final lo que espera una
aprobación externa. Ninguna tarea toca más de ~5 archivos.

---

## T1 — Dirección escrita y REFERENCIA del cliente

Cubre dos puntos de la lista: el llenado literal de la dirección y el cuadro de
REFERENCIA debajo de la dirección y el mapa. Van juntos porque son el mismo dato y la
misma migración; separarlos obligaría a dos migraciones sobre la misma tabla.

- [ ] **T1.1** Migración: `direccion` y `referencia` (texto, nullable) en
      `distribuidor_ubicaciones`
  - Acceptance: las dos columnas existen y admiten null; una ubicación que sólo tiene
    coordenadas sigue siendo válida
  - Verify: **la corrés vos** con `php artisan migrate`; después
    `Schema::hasColumn('distribuidor_ubicaciones', 'referencia')`
  - Files: `packages/Webkul/Distribuidor/src/Database/Migrations/…`
  - ⚠️ Bloquea a T1.2 y T1.3. Y mientras no corra, el servicio tiene que tolerar su
    ausencia como ya hace con la tabla, o el Punto tira 500 en producción.

- [ ] **T1.2** `Ubicacion` guarda y devuelve dirección y referencia
  - Acceptance: `guardarDelMapa` y `guardarGps` aceptan los dos textos; `deCliente`
    los devuelve; un punto sin texto sigue funcionando
  - Verify: tests de servicio, incluido el caso sin la columna todavía creada
  - Files: `Services/Ubicacion.php`, `Models/UbicacionCliente.php`

- [ ] **T1.3** Los dos campos en el Punto, debajo del mapa
  - Acceptance: están en el alta de contacto y en el modal de editar domicilio; se
    guardan; la referencia se ve en la tarjeta del pedido, que es donde la lee quien
    toca el timbre
  - Verify: test HTTP del alta con los tres datos + aserción sobre la plantilla
  - Files: `Resources/views/punto.blade.php`, `Http/Controllers/PuntoController.php`

- [ ] **T1.4** Reconciliar con `ubicacion_lead`
  - Acceptance: queda UNA fuente de verdad para la referencia. La cascada de
    `dePedido()` prefiere la del cliente y usa la del pedido como respaldo
  - Verify: test de que una referencia por cliente le gana a la del atributo del lead
  - Files: `Services/Ubicacion.php`
  - Nota: hoy `referenciaLimpia()` lee del atributo por pedido. Sin este paso quedan
    dos lugares donde escribir lo mismo y se desincronizan.

## T2 — Contador de bidones visible en el CRM

- [ ] **T2.1** Exponer el saldo en la ficha del contacto del CRM
  - Acceptance: al abrir un contacto se ve cuántos bidones tiene sin devolver y desde
    cuándo; un contacto sin movimientos no muestra ruido
  - Verify: test HTTP sobre la vista del contacto
  - Files: `packages/Webkul/Admin/src/Http/Controllers/Contact/Persons/PersonController.php`,
    su vista, `Services/Bidones.php`

- [ ] **T2.2** *(depende de la pregunta 1)* Pantalla de envases a recuperar
  - Acceptance: lista de clientes con saldo > 0 ordenada por cantidad, acotada por el
    alcance de ciudad del usuario
  - Verify: test de que un usuario de Jujuy no ve clientes de otra ciudad
  - Files: un DataGrid nuevo + ruta + entrada de menú
  - ⚠️ No arranca hasta que confirmes si hace falta: `pendientesDeDevolucion()` ya
    existe y hoy se muestra en Caja.

## T3 — Editar un pedido antes de entregarlo

- [ ] **T3.1** Endpoint de edición de productos y cantidades
  - Acceptance: cambia líneas y recalcula el total; respeta el alcance por ciudad;
    rechaza editar un pedido ya entregado o cancelado
  - Verify: tests HTTP de los tres casos (edita, 403 de otra ciudad, 422 ya entregado)
  - Files: `PuntoController.php`, `Services/PuntoPedido.php`, `Routes/admin.php`
  - ⚠️ Depende de la pregunta 3: si se permite editar después de cobrar, hace falta
    decidir qué pasa con la diferencia. **No lo implemento hasta que respondas.**

- [ ] **T3.2** UI de edición en la tarjeta del pedido
  - Acceptance: se llega en un toque desde "Para entregar"; se ve el total antes y
    después; no se puede guardar un pedido sin líneas
  - Verify: aserciones sobre la plantilla + los tests de foco y responsive que ya
    existen
  - Files: `punto.blade.php`

## T4 — Identidad visual en WhatsApp

- [ ] **T4.1** Marca de Sensia en la bandeja del CRM
  - Acceptance: la bandeja muestra el logo en su encabezado; las conversaciones sin
    foto siguen cayendo en las iniciales de color que ya hay
  - Verify: aserción sobre la plantilla renderizada
  - Files: `packages/Webkul/Whatsapp/src/Resources/views/chat.blade.php`

- [ ] **T4.2** Foto de perfil del número de WhatsApp
  - Acceptance: el número muestra el logo de Sensia en el teléfono del cliente
  - Verify: mirarlo desde un teléfono que le escriba
  - ⚠️ **No es código**: se carga en Meta Business Manager. Preparo la imagen en el
    tamaño que pide Meta y te dejo los pasos.

## T5 — Mensaje de bienvenida al crear un contacto

Último a propósito: es el único que depende de una aprobación externa.

- [ ] **T5.1** Redactar la plantilla y someterla a Meta
  - Acceptance: existe una plantilla aprobada en español para abrir conversación
  - Verify: aparece en el catálogo con estado aprobado
  - ⚠️ **Acción tuya en Meta.** Hoy la única aprobada es `hello_world` en inglés.
    Necesito la pregunta 4 respondida para redactarla.

- [ ] **T5.2** Envío al crear el contacto, detrás de una bandera
  - Acceptance: con la bandera apagada no manda nada; encendida y con plantilla
    aprobada, encola el envío; si la plantilla falta, lo registra y NO rompe el alta
  - Verify: tests con `Queue::fake()` de los tres casos
  - Files: `PuntoController.php` o un listener, `Config/distribuidor.php`
  - Nota: el alta del contacto no puede fallar porque el mensaje no salió. Crear el
    cliente es el trabajo; saludarlo es un extra.

---

## Dependencias en una línea

```
T1.1 (migración tuya) ─→ T1.2 ─→ T1.3 ─→ T1.4
T2.1 (independiente)
T2.2 ──── espera respuesta 1
T3.1 ──── espera respuesta 3 ─→ T3.2
T4.1 (independiente)
T4.2 (acción tuya en Meta)
T5.1 (acción tuya en Meta) ─→ T5.2
```

## Lo que puedo arrancar ahora mismo, sin esperar nada

**T2.1** (bidones en la ficha del CRM) y **T4.1** (logo en la bandeja). Las dos son
independientes y no necesitan migración ni aprobación.

## Deuda de infraestructura que sigue abierta y no es de esta lista

- La cola: `supervisord.conf` corre `queue:work redis` con `QUEUE_CONNECTION=sync` y
  sin `REDIS_HOST`. Loguea un error cada 4 s —`laravel.log` va por 447 MB— y además
  hace que un webhook de Meta espere al agente dentro del request. **T5.2 depende de
  esto**: un envío encolado con `sync` sale dentro del request del alta.
- `APP_DEBUG=true` con `APP_ENV=local` en producción.
- `WHATSAPP_AGENT_TOKEN` es el de kolberg y Railway lo rechaza con 401.
