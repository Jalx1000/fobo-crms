# Spec: Filtro por ciudad (pipeline) en el módulo de WhatsApp

Estado: **APROBADO** — 2026-09-07
Plan: [`plan-filtro-ciudad.md`](./plan-filtro-ciudad.md) · Tareas: [`todo-filtro-ciudad.md`](./todo-filtro-ciudad.md)
Rama: `kohlberg-wa-v2`
Specs hermanas: [`spec-mensajes-interactivos.md`](./spec-mensajes-interactivos.md) · [`spec-sprint-08-whatsapp.md`](./spec-sprint-08-whatsapp.md)

---

## Objetivo

En kohlberg **la ciudad es el pipeline**, y cada ciudad tiene su Encargado. Pero la bandeja de
WhatsApp muestra las 40 conversaciones mezcladas: un asesor de Santa Cruz ve las de Cochabamba
y viceversa. Con el handoff ya en producción eso empeora — las derivaciones se rutean por
ciudad, pero la lista donde el asesor las atiende no sabe nada de ciudades.

Este filtro le deja al asesor ver **su** ciudad, y al administrador saltar entre ciudades sin
buscar a mano.

**Usuarios:**

| Quién | Qué gana |
|---|---|
| Los 7 asesores (rol `Encargado`) | ver sólo las conversaciones de su ciudad |
| El administrador | saltar entre ciudades, y ver de un vistazo cuántas hay sin clasificar |

**Éxito, en una frase:** el asesor elige "Santa Cruz" y la lista queda con esas 10
conversaciones, cada fila con su chip de ciudad.

---

## El dato que define el diseño

**Una conversación no tiene ciudad propia.** Hay que deducirla. Medido sobre las 40
conversaciones reales:

| Camino | Cubre |
|---|---|
| `handoff_city_id` (resuelto al derivar) | 3 |
| Atributo `cliente_ciudad` del contacto | 18 |
| Pipeline del lead del contacto | 18 |
| **Cascada de los tres** | **19 de 40** |

Los dos últimos **coinciden perfectamente**: 18 con dato, **cero discrepancias**. Es el efecto
de `CitySyncService`, que ya mantiene sincronizados el pipeline del lead y el atributo del
contacto. La cascada igual se usa porque es lo que hace `CityRouter`, y porque el atributo
existe sin necesidad de que haya un lead.

Reparto real:

```
(sin ciudad)   21      Potosí          1
Santa Cruz     10      Tarija          1
Cochabamba      5      La Paz          1
                       "Sin ciudad"    1   ← el pipeline que se llama así
```

**Más de la mitad no tiene ciudad.** Eso no es un caso borde: es la opción más usada del
filtro, y la cola de trabajo que hoy nadie ve.

---

## Alcance

### Dentro

1. Resolución de la ciudad de una conversación, en cascada, como una sola expresión SQL
   reutilizable (para filtrar y para mostrar, sin N+1).
2. Parámetro `city` en `GET /admin/whatsapp/conversations`.
3. Endpoint que lista las ciudades **con su conteo**, para armar el desplegable.
4. Desplegable "Ciudad" en el panel izquierdo, arriba de los chips de etapa.
5. Chip de ciudad en cada fila de la lista.
6. La opción **Sin ciudad**, que junta el NULL y el pipeline llamado "Sin ciudad".

### Fuera, explícitamente

- **Filtrar automáticamente por la ciudad del asesor.** Tentador, pero es una decisión de
  permisos disfrazada de filtro: hoy un Encargado puede ver todas las conversaciones, y
  esconderle unas por defecto cambia eso sin que nadie lo haya pedido. Si se quiere, es otra
  tarea y hay que decidirla como política, no como default de una lista.
- **Guardar la ciudad en `whatsapp_conversations`.** Sería más rápido de consultar, pero
  agrega una columna que hay que mantener sincronizada con tres fuentes. Con 40
  conversaciones la cascada en SQL no se nota; si algún día pesa, se cachea entonces.
- **Editar la ciudad desde la bandeja.** Se cambia donde ya se cambia: en el contacto o en el
  pipeline del pedido. Un segundo lugar para editarlo es un segundo lugar donde desincronizar.
- **Filtro por ciudad en la pestaña de WhatsApp del lead/contacto.** Ahí hay una sola
  conversación; no hay nada que filtrar.

---

## Tech Stack

Sin dependencias nuevas. PHP 8.1 / Laravel 10, módulo `packages/Webkul/Whatsapp`, Blade + Vue 3
(`x-template`, compilado en runtime), Tailwind, Pest.

**Restricción heredada:** el CSS del admin se compiló el 2026-08-28 y **las clases de valor
arbitrario de Tailwind no existen** en él (`h-[220px]`, `bg-[#d9fdd3]`, y 35 más). Todo lo
visual de esta tarea usa clases estándar; si alguna medida es funcional, va en `style` inline.

---

## Commands

```bash
# Tests — dentro del contenedor Y como www-data: correrlos como root deja las
# vistas compiladas con dueño equivocado y el CRM tira 500 (ya pasó dos veces).
docker exec heaven_kolberg_laravel.1.mzxjtk1wly5rxf33hh5c5fq49 sh -lc \
  'cd /code && su -s /bin/sh www-data -c "HOME=/tmp XDG_CONFIG_HOME=/tmp php artisan test tests/Feature/WhatsappFiltroCiudadTest.php"'

# Suite completa
docker exec heaven_kolberg_laravel.1.mzxjtk1wly5rxf33hh5c5fq49 sh -lc \
  'cd /code && su -s /bin/sh www-data -c "HOME=/tmp XDG_CONFIG_HOME=/tmp php artisan test"'

# Estilo — archivo por archivo, nunca sobre un directorio
./vendor/bin/pint packages/Webkul/Whatsapp/src/Http/Controllers/ChatController.php

# Limpiar vistas tras tocar un blade — como www-data
docker exec heaven_kolberg_laravel.1.mzxjtk1wly5rxf33hh5c5fq49 sh -lc \
  'cd /code && su -s /bin/sh www-data -c "HOME=/tmp XDG_CONFIG_HOME=/tmp php artisan view:clear"'
```

---

## Project Structure

```
packages/Webkul/Whatsapp/src/
├── Support/ConversationCity.php          → NUEVO: la expresión SQL de la cascada
├── Http/Controllers/ChatController.php   → filtro `city`, chip por fila, endpoint cities()
├── Routes/admin.php                      → + admin.whatsapp.cities
└── Resources/views/chat.blade.php        → desplegable + chip en la fila

tests/Feature/WhatsappFiltroCiudadTest.php → NUEVO
```

---

## Code Style

Comentarios en español que explican **por qué**. La expresión SQL vive en una clase, no
duplicada entre el `select` y el `where`:

```php
final class ConversationCity
{
    /**
     * El id de ciudad (pipeline) de una conversación, en cascada.
     *
     * Es una sola expresión y no tres consultas porque la usan el filtro, la
     * columna de la fila y el conteo del desplegable: tenerla escrita en tres
     * lugares es garantizar que un día devuelvan cosas distintas.
     */
    public static function sql(): string
    {
        $p = DB::getTablePrefix();
        // COALESCE(handoff_city_id, atributo del contacto, pipeline del lead)
    }
}
```

Convenciones vigentes: nada de `dd()` ni `Log::info` de depuración; en el blade, `@{{ }}` para
Vue y `{{ }}` sólo para Blade (hay un test que lo vigila).

---

## Testing Strategy

Pest, en `tests/Feature/WhatsappFiltroCiudadTest.php`.

| Nivel | Qué cubre |
|---|---|
| Cascada | las tres fuentes por separado, y el orden de precedencia entre ellas |
| Filtro | `city=<id>` devuelve sólo esa ciudad; `city=none` devuelve las sin ciudad |
| Combinación | ciudad + etapa + búsqueda a la vez no se pisan |
| Conteos | la suma de los conteos del desplegable es el total de conversaciones |
| Fila | cada fila trae el nombre de la ciudad, o null |
| Blade | el desplegable existe y no rompe los guards del componente |

**Restricción no negociable:** la suite corre contra la base de **producción**. Todo dentro de
una transacción; sin `truncate()`; lo creado se borra en el teardown.

---

## Boundaries

### Always

- Una sola expresión de ciudad, compartida por filtro, fila y conteo.
- Correr los tests dentro del contenedor **y como `www-data`**.
- Pint archivo por archivo.
- Clases de Tailwind estándar; medidas funcionales en `style` inline.

### Ask first

- Filtrar por defecto según la ciudad del asesor (es política de permisos, no un filtro).
- Agregar una columna `city_id` a `whatsapp_conversations`.
- Cambiar el orden de la cascada.

### Never

- Ocultarle conversaciones a alguien que hoy las ve, sin decirlo.
- Duplicar la lógica de ciudad entre el `where` y el `select`.
- Correr artisan como root.
- Borrar tests que fallan para que la suite pase.

---

## Success Criteria

1. `GET /admin/whatsapp/conversations?city=3` devuelve **sólo** las de Santa Cruz (hoy 10).
2. `city=none` devuelve las 22 sin ciudad — incluida la del pipeline llamado "Sin ciudad".
3. Sin el parámetro, la lista no cambia respecto de hoy.
4. `city` se combina con `filter`, `stage` y `search` sin pisarlos.
5. `GET /admin/whatsapp/cities` devuelve las ciudades con conteo, y la suma da 40.
6. Una ciudad con 0 conversaciones no aparece en el desplegable.
7. Cada fila trae `city` con el nombre, o `null`.
8. El desplegable se ve arriba de los chips de etapa y no rompe el layout del panel.
9. El chip de ciudad aparece en la fila junto al de etapa.
10. La cascada respeta la precedencia: `handoff_city_id` gana sobre el atributo, y el atributo
    sobre el lead. Verificado con un caso donde los tres difieren.
11. Sin N+1: la lista hace el mismo número de consultas con el filtro que sin él.
12. La suite completa no suma fallos sobre la línea base (9 preexistentes).

---

## Open Questions

Ninguna. Confirmadas al aprobar:

- ~~¿"Sin ciudad" junta el NULL con el pipeline homónimo?~~ → **Sí**, una sola opción (21 + 1).
- ~~¿El desplegable muestra el conteo?~~ → **Sí**. Es lo que convierte "Sin ciudad: 22" en una
  cola de trabajo visible en vez de un número escondido.
