# Spec: Pedidos — filtro por ejecutivo comercial

Estado: **implementado** (16/10/2026). Fase A en `5c9c73e`; fase B decidida por el usuario el mismo día.

## Objetivo

En el módulo de pedidos (kanban y lista), un selector **«Ejecutivo comercial» al lado del de ciudad**
permite ver los pedidos de un ejecutivo en particular (pedido del usuario, 16/10).

- **Administrador y Supervisores** ven los pedidos de **todos** los ejecutivos comerciales y usan el
  selector para filtrar.
- **Ejecutivos comerciales** (Bulmaro, Eduardo, Fabio, Gabriela, Mauricio) ven **solo sus pedidos** y
  **no ven el selector**.

## Decisiones

| # | Decisión |
|---|---|
| A1 | El selector está en `leads/index/view-switcher.blade.php`, a la izquierda del de ciudad: vale para kanban y lista. |
| A2 | La elección va en la URL (`?ejecutivo=<user_id>`, como el filtro de canal) y se conserva al cambiar de ciudad, de vista y al exportar. |
| A3 | Opciones: ejecutivos comerciales **activos** (rol «Ejecutivo Comercial»). Mariano y Andrés (Supervisores) no son opciones. |
| A4 | El filtro se aplica **después** del permiso de vista: solo achica lo que el usuario ya puede ver. |
| B1 | **El selector no se muestra a los ejecutivos comerciales**: solo a quien ve el total (`Alcance::veTotal()`, roles Administrador y Supervisores). |
| B2 | Un ejecutivo comercial **no ve pedidos de otro ejecutivo** por ningún camino (sigue el permiso `individual`, D13b de `tablero-por-asesor.md`). |
| B3 | Para quien no ve el total, el parámetro `?ejecutivo=` **se ignora**: una URL compartida por un supervisor le muestra igual sus propios pedidos, no un tablero vacío. |

Fuera de alcance: contactos, cotizaciones, inbox de WhatsApp y tablero (cada uno ya tiene su regla).

## Comandos

```bash
C=$(docker ps --format '{{.Names}}' | grep imprimir_laravel)
docker exec -u www-data -w /code $C php artisan test tests/Feature/Lead/FiltroEjecutivoTest.php
docker exec -u www-data -w /code $C php artisan test tests/Feature/Lead/          # pedidos
docker exec -u www-data -w /code $C php artisan test                              # suite completa (no deja registros)
docker exec -e TMPDIR=/tmp -w /code $C ./vendor/bin/pint <archivos tocados>       # solo los archivos tocados
```

## Estructura

```
packages/Webkul/Admin/src/Helpers/FiltroEjecutivo.php                            → opciones, elegido() y aplicar()
packages/Webkul/Admin/src/Resources/views/leads/index/ejecutivo-filter.blade.php → el selector
packages/Webkul/Admin/src/Resources/views/leads/index/view-switcher.blade.php    → lo ubica junto a la ciudad
packages/Webkul/Admin/src/Http/Controllers/Lead/LeadController.php               → kanban
packages/Webkul/Admin/src/DataGrids/Lead/LeadDataGrid.php                        → lista
packages/Webkul/Admin/src/Helpers/Alcance.php                                    → quién ve el total (se consulta, sin cambios)
tests/Feature/Lead/FiltroEjecutivoTest.php                                       → tests
```

## Estilo

La regla en **un solo lugar** (`FiltroEjecutivo`, que pregunta a `Alcance`), comentarios en español
con el porqué y la fecha de la decisión, y el servidor siempre valida aunque la vista oculte el control.

```php
// Selector «Ejecutivo comercial» (dentro de lo que ya puede ver).
FiltroEjecutivo::aplicar($query);
```

## Tests

Pest, en `tests/Feature/Lead/`, contra la base real con `DatabaseTransactions` global: cada test crea
y borra sus registros (fechas de enero de 2020). **Nunca una base de prueba aparte.**

1. El administrador filtra el kanban y la lista por ejecutivo.
2. El selector está junto a la ciudad, lista solo ejecutivos y cambiar de ciudad conserva la elección.
3. Un supervisor ve el selector y filtra.
4. Un ejecutivo no ve el selector.
5. Un ejecutivo con `?ejecutivo=<otro>` sigue viendo solo sus pedidos, en el kanban y en la lista.

## Límites

- **Siempre:** validar en el servidor; correr `tests/Feature/Lead/` y la suite antes del commit; Pint
  solo sobre los archivos tocados; comandos como `www-data`.
- **Preguntar antes:** cambiar el permiso `individual` de los usuarios; tocar contactos, cotizaciones,
  inbox o tablero; cambios de datos en producción.
- **Nunca:** que un ejecutivo vea pedidos de otro ejecutivo; crear una base de prueba.

## Criterios de éxito

- [x] El selector está al lado de la ciudad en el kanban y en la lista, y filtra los dos.
- [x] Se conserva al cambiar de ciudad, de vista y al exportar.
- [x] Decisiones B1–B3 tomadas por el usuario.
- [x] El ejecutivo no ve el selector y el parámetro se le ignora; Administrador y Supervisores filtran.
- [x] Tests de pedidos y suite completa en verde (757, la base queda igual antes y después).
