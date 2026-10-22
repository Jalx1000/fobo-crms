<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Attribute\Traits\CustomAttribute;

/**
 * Agrega los atributos custom a las respuestas de /api/v1/products.
 *
 * ¿Por qué un middleware y no el Resource?
 *
 * El endpoint lo sirve `krayin/rest-api`, un paquete de composer, y su `ProductResource`
 * devuelve ocho campos fijos. El controlador lo usa por NOMBRE DE CLASE
 * (`ProductResource::collection(...)`), así que no se puede sustituir por el contenedor,
 * y el provider del paquete se autodescubre —Laravel carga los providers de paquetes
 * ANTES que los de config/app.php— así que tampoco se puede ganar la ruta registrando
 * otra. Editar `vendor/` se pierde en el próximo `composer install`.
 *
 * Queda enriquecer la respuesta. Vive en `app/` y se engancha al grupo `api` de
 * `app/Http/Kernel.php`, los dos archivos nuestros, así que sobrevive a cualquier
 * actualización del paquete.
 *
 * El problema concreto que resuelve: hay dos productos con el MISMO nombre —"Botellón de
 * agua purificada 20 L" a $7.500 y a $8.000— y lo único que los distingue es de qué
 * depósito son, que vive en `ciudad_producto_sucursal`. Sin ese atributo, por API no hay
 * forma de saber cuál corresponde a cada zona.
 */
class AgregarAtributosDeProducto
{
    /** Las rutas de productos del REST API, por nombre de la ruta. */
    protected const RUTAS = ['api/v1/products', 'api/v1/products/search'];

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if (! $this->corresponde($request, $response)) {
            return $response;
        }

        $cuerpo = $response->getData(true);

        if (! is_array($cuerpo) || ! isset($cuerpo['data'])) {
            return $response;
        }

        /*
         * La lista devuelve `data` como array de productos; el detalle, como un solo
         * producto. Se normaliza para tratarlos igual y se devuelve con la misma forma con
         * la que llegó: cambiarla rompería a quien ya consume el endpoint.
         */
        $esLista = array_is_list($cuerpo['data']);
        $items = $esLista ? $cuerpo['data'] : [$cuerpo['data']];

        $ids = array_values(array_filter(array_map(fn ($i) => (int) ($i['id'] ?? 0), $items)));

        if (! $ids) {
            return $response;
        }

        $atributos = $this->atributosDe($ids);

        $items = array_map(function ($item) use ($atributos) {
            foreach ($atributos[(int) ($item['id'] ?? 0)] ?? [] as $clave => $valor) {
                /*
                 * No se pisa un campo que ya venga. En Krayin `name`, `sku`, `price`,
                 * `quantity` y `description` TAMBIÉN son atributos custom, así que sin esto
                 * el precio del Resource ("7500.0000") quedaría reemplazado por el del
                 * atributo, con otro formato, y cambiaría la respuesta para quien ya la usa.
                 */
                if (! array_key_exists($clave, $item)) {
                    $item[$clave] = $valor;
                }
            }

            return $item;
        }, $items);

        /*
         * Las listas no traen productos inactivos: solo se ven en el módulo de
         * Productos. El detalle por id sí, porque un pedido viejo puede tener uno y
         * quien lo consulta necesita su nombre y su precio.
         */
        if ($esLista) {
            $inactivos = array_flip(\Webkul\Product\Support\ProductosActivos::inactivos());
            $items = array_values(array_filter($items, fn ($i) => ! isset($inactivos[(int) ($i['id'] ?? 0)])));
        }

        $cuerpo['data'] = $esLista ? $items : $items[0];

        $response->setData($cuerpo);

        return $response;
    }

    protected function corresponde(Request $request, $response): bool
    {
        if (! $response instanceof JsonResponse || ! $response->isSuccessful()) {
            return false;
        }

        $uri = (string) optional($request->route())->uri();

        // El detalle es api/v1/products/{id}; se excluyen los sub-recursos como
        // /warehouses o /inventories, que no devuelven productos.
        return in_array($uri, self::RUTAS, true) || $uri === 'api/v1/products/{id}';
    }

    /**
     * Los atributos custom de esos productos, resueltos a etiquetas.
     *
     * Dos consultas en total —valores y atributos— más una por cada lookup distinto. No se
     * usa `$producto->attributesToArray()` del trait CustomAttribute justamente por eso: ese
     * camino resuelve atributo por atributo y producto por producto, y con cien productos
     * en la lista se vuelve un N+1 de cientos de consultas.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>>
     */
    protected function atributosDe(array $ids): array
    {
        $filas = DB::table('attribute_values as v')
            ->join('attributes as a', 'a.id', '=', 'v.attribute_id')
            ->where('v.entity_type', 'products')
            ->whereIn('v.entity_id', $ids)
            ->get(['v.entity_id', 'a.code', 'a.type', 'a.lookup_type', 'v.*']);

        $porTipo = CustomAttribute::$attributeTypeFields;

        // Las etiquetas se juntan primero y se resuelven en bloque, para no consultar el
        // mismo depósito una vez por producto.
        $aResolver = [];
        $crudos = [];

        foreach ($filas as $f) {
            $columna = $porTipo[$f->type] ?? 'text_value';
            $valor = $f->{$columna} ?? null;

            $crudos[] = [$f, $valor];

            if ($f->lookup_type && $valor !== null && $valor !== '') {
                foreach ($this->idsDe($valor) as $id) {
                    $aResolver[$f->lookup_type][$id] = $id;
                }
            }
        }

        $etiquetas = $this->etiquetas($aResolver);
        $opciones = $this->opcionesDeSelect($filas);

        $salida = [];

        foreach ($crudos as [$f, $valor]) {
            $pid = (int) $f->entity_id;

            $salida[$pid][$f->code] = $this->normalizar($f, $valor);

            // Los ids sueltos no le dicen nada a nadie: se agrega la etiqueta al lado, en
            // una clave hermana, para no cambiar el tipo del valor original.
            if ($f->lookup_type && $valor !== null && $valor !== '') {
                $nombres = array_values(array_filter(array_map(
                    fn ($id) => $etiquetas[$f->lookup_type][$id] ?? null,
                    $this->idsDe($valor),
                )));

                if ($nombres) {
                    $salida[$pid][$f->code.'_labels'] = $nombres;
                }
            } elseif ($f->type === 'select' && $valor !== null && $valor !== '') {
                $salida[$pid][$f->code.'_label'] = $opciones[(int) $valor] ?? null;
            }
        }

        return $salida;
    }

    /** Un multiselect guarda "1,9"; un select guarda 10. */
    protected function idsDe($valor): array
    {
        return array_values(array_filter(
            array_map('intval', explode(',', (string) $valor)),
        ));
    }

    /**
     * @param  array<string, array<int, int>>  $porLookup
     * @return array<string, array<int, string>>
     */
    protected function etiquetas(array $porLookup): array
    {
        $repo = app(AttributeRepository::class);
        $salida = [];

        foreach ($porLookup as $lookup => $ids) {
            try {
                // Por el repositorio que declara config/attribute_lookups.php, no por una
                // tabla hardcodeada: así un lookup nuevo funciona sin tocar esto.
                $filas = $repo->getLookUpEntity($lookup, array_values($ids));

                foreach ($filas ?? [] as $fila) {
                    $salida[$lookup][(int) $fila->id] = (string) $fila->name;
                }
            } catch (\Throwable $e) {
                // Un lookup mal configurado no puede tumbar la respuesta entera: se
                // devuelven los ids sin etiqueta, que ya es más de lo que había antes.
                continue;
            }
        }

        return $salida;
    }

    /** @return array<int, string> */
    protected function opcionesDeSelect($filas): array
    {
        $ids = collect($filas)->where('type', 'select')->pluck('attribute_id')->unique()->all();

        if (! $ids) {
            return [];
        }

        return DB::table('attribute_options')
            ->whereIn('attribute_id', $ids)
            ->pluck('name', 'id')
            ->map(fn ($n) => (string) $n)
            ->all();
    }

    /** El valor con el tipo que le corresponde, no todo como texto. */
    protected function normalizar($f, $valor)
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return match ($f->type) {
            'boolean'     => (bool) $valor,
            'multiselect' => $this->idsDe($valor),
            'select'      => (int) $valor,
            'price'       => (float) $valor,
            default       => $valor,
        };
    }
}
