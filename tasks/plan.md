# Spec: pendientes de SENSIA (sin el flujo de delivery)

Estado del repo al escribir esto: rama `feat/whatsapp`, árbol limpio, 288 tests
pasando y 14 fallas preexistentes ajenas a esto (familias Doctor/Specialty/Products
y CitySync).

## Objetivo

Cerrar los pendientes de la lista de SENSIA excepto el flujo de delivery con
delimitación de zona, que queda explícitamente fuera de alcance por pedido del
negocio.

Usuario: el distribuidor en la calle con un celular, y el encargado que mira el CRM
desde una notebook. Éxito: los siete puntos de abajo quedan verificables con un test
o con una comprobación manual nombrada.

## Lo que ya está hecho (verificado en código, no asumido)

| Punto de la lista | Dónde |
|---|---|
| Foto no obligatoria al entregar | `PuntoController::avanzar` valida `foto` como `nullable` |
| Cajita de bidones por cliente en el PUNTO | `Services\Bidones` + dos cajas en el diálogo de entrega |
| Georreferenciación del cliente | `distribuidor_ubicaciones` + mapa editable con pin |
| Distribuidores sin tablero / prospectos / productos | el rol `Distribuidor` no tiene esos permisos |

## ASUMO LO SIGUIENTE — corregime antes de que implemente

1. **"CONTADOR DE BIDONES POR CLIENTE (pendiente)"** no es la cajita del Punto —esa
   está hecha y tachada en la lista— sino **ver el saldo desde el CRM**. Hoy
   `grep` sobre `packages/Webkul/Admin/src/Resources/views` no encuentra ni una
   mención a bidones: el dato existe pero sólo se ve desde el Punto.
2. **"Logo o icono identificativo en WhatsApp"** son dos cosas distintas y asumo que
   querés las dos: la **foto de perfil del número** (se carga en Meta, no es código)
   y una **marca visible en la bandeja del CRM**, que hoy muestra iniciales de color.
3. **La dirección escrita y la referencia son del CLIENTE**, no del pedido. Hoy
   `Ubicacion` las lee de `ubicacion_lead`, que es un atributo **por pedido**: eso
   obliga a reescribir el domicilio en cada pedido. Van junto a las coordenadas, en
   `distribuidor_ubicaciones`.
4. **"Pedido automático" = el que crea el agente de WhatsApp**, y editarlo en la
   entrega significa cambiar productos y cantidades, no el cliente ni la ciudad.
5. El mensaje de bienvenida se manda **sólo al crear un contacto desde el Punto**, no
   al importar contactos ni al crearlos desde el CRM.

## Restricción dura que condiciona el punto 7

El mensaje de bienvenida va a un número con el que **nunca hablamos**, así que cae
fuera de la ventana de 24 h de WhatsApp y **exige una plantilla aprobada por Meta**.

Hoy hay **una sola plantilla aprobada: `hello_world`, en inglés** (verificado contra
el catálogo). No existe ninguna de bienvenida en español.

Consecuencia: puedo construir el mecanismo de envío y sus tests, pero **no puede
funcionar hasta que alguien cree y apruebe la plantilla en Meta**, que es una acción
tuya y tarda entre minutos y 24 h. Lo dejo detrás de una bandera apagada para no
ensuciar el log con rechazos.

## Tech stack

Krayin CRM sobre Laravel 10 / PHP 8.2, módulos Concord en `packages/Webkul/`.
Front: Blade + Vue 3 global en `<script type="text/x-template">`, Tailwind, Leaflet
1.9.4 desde CDN. Tests: Pest 2 contra la base de **producción**, con
`DatabaseTransactions`.

## Comandos

```bash
C=$(docker ps --format '{{.Names}}' | grep -i 'sensia-laravel')

# Tests (SIEMPRE como www-data: como root deja vistas compiladas root:root y la app
# se cae con Permission denied)
docker exec -u www-data -w /code "$C" ./vendor/bin/pest
docker exec -u www-data -w /code "$C" ./vendor/bin/pest tests/Feature/<archivo>.php

# Estilo
./vendor/bin/pint

# Migraciones — LAS CORRE EL USUARIO. El classifier me las bloquea por ser
# un cambio de esquema en producción.
php artisan migrate
```

## Estructura

```
packages/Webkul/Distribuidor/src/   el Punto: Services, Http, Models, Resources/views
packages/Webkul/Whatsapp/src/       bandeja, plantillas, jobs, gateways
packages/Webkul/Admin/src/          UI del CRM: vistas, DataGrids, Support
tests/Feature/                      Pest, un archivo por área
tasks/                              este plan y el todo
```

## Estilo

Comentarios que explican **por qué**, con el síntoma concreto que evitan. Nombres de
dominio en español. Ejemplo del código actual:

```php
/*
 * Los bidones se cuentan contra el CLIENTE y no contra el pedido: el saldo que
 * importa es "cuántos tiene en su casa", y eso atraviesa todos sus pedidos.
 */
```

Convenciones que ya mordieron en esta sesión y no se repiten:

- En el `x-template` sólo comentarios HTML: `{{` es sintaxis de Blade **y** de Vue, y
  romperlo deja el componente en blanco sin ningún error.
- `expect()->toContain()` recibe varios needles por varargs: un "mensaje" como segundo
  argumento se interpreta como otra cosa a buscar. Va `assertStringContainsString`.
- Sin alias de tabla en SQL crudo: Laravel aplica el prefijo `kl_` también al alias.
- `@click="metodo"` sin paréntesis le pasa el MouseEvent como primer argumento.

## Estrategia de tests

Pest, en `tests/Feature/`. Todo test usa `DatabaseTransactions` porque la base es la
de producción. Cada cambio de comportamiento se prueba **al revés**: se reintroduce el
bug y se confirma que el test falla. Un test que no vi fallar no cuenta.

Niveles: servicio para las reglas (saldos, precedencias), HTTP para los endpoints y
sus 422, y aserciones sobre la plantilla renderizada para lo que sólo vive en la vista
(balance de etiquetas, foco, responsive).

## Límites

- **Siempre:** correr la suite completa antes de cada commit; `pint`; verificar que no
  quedaron datos de prueba ni archivos `root` en `storage/`.
- **Preguntar primero:** cualquier migración (no puedo correrla); tocar
  `generated/supervisord.conf` o `.env`; mandar mensajes reales a clientes.
- **Nunca:** borrar datos del usuario; `git stash` sobre un árbol con cambios sin
  commitear; commitear secretos.

## Criterios de éxito

1. El saldo de bidones de un cliente se ve desde el CRM sin entrar al Punto.
2. La bandeja de WhatsApp muestra una marca identificable de Sensia.
3. Un pedido creado por el agente se puede editar en cantidades y productos antes de
   entregarlo, y el total se recalcula.
4. Al cargar un contacto se puede escribir la dirección en texto **además** de marcar
   el pin, y las dos conviven.
5. Hay un campo REFERENCIA debajo de la dirección y el mapa, y lo que se escribe ahí
   llega al distribuidor que toca el timbre.
6. El mensaje de bienvenida se manda al crear un contacto, detrás de una bandeja de
   configuración y de una plantilla aprobada.

## Preguntas abiertas

1. **Bidones en el CRM**: ¿alcanza con verlo en la ficha del contacto, o querés además
   una pantalla con todos los clientes que deben envases, ordenada por cantidad?
2. **Logo de WhatsApp**: ¿la foto de perfil del número la cargás vos en Meta, o querés
   que prepare la imagen y te deje las instrucciones?
3. **Editar el pedido**: ¿se puede editar sólo antes de cobrar, o también después?
   Cambiar el total de un pedido ya cobrado deja un saldo que no cierra.
4. **Mensaje de bienvenida**: ¿qué tiene que decir? Lo necesito para redactar la
   plantilla que vas a someter a Meta.
