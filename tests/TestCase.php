<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Tablas cuyo contenido es dato de negocio irrecuperable desde un test.
     */
    protected array $tablasProtegidas = [
        'products',
        'attributes',
        'attribute_values',
        'persons',
        'organizations',
        'leads',
        'users',
        'roles',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->protegerDatosReales();
    }

    /**
     * Corta las sentencias que vacían una tabla, antes de que lleguen al motor.
     *
     * Esta suite NO corre contra una base descartable: `phpunit.xml` no
     * sobreescribe ninguna variable DB_*, así que los tests se conectan a la
     * misma base que la aplicación. Un `truncate()` o un `delete` sin `where`
     * en un test no borra datos de prueba: borra el catálogo real.
     *
     * Ya pasó. `ProductosByCiudadSucursalTest` hacía
     * `DB::table('products')->truncate()` en su teardown, contra las 29 filas
     * reales de `kl_products` y los 319 `attribute_values` de productos.
     *
     * `DatabaseTransactions` NO alcanza para frenarlo: TRUNCATE es DDL y en
     * MySQL provoca un COMMIT implícito, así que la transacción del test ya se
     * cerró sola antes de que llegue el rollback. Por eso el corte va acá,
     * antes de ejecutar.
     */
    protected function protegerDatosReales(): void
    {
        $prefijo = DB::getTablePrefix();
        $protegidas = $this->tablasProtegidas;

        DB::beforeExecuting(function (string $query) use ($prefijo, $protegidas) {
            $sql = strtolower(trim(preg_replace('/\s+/', ' ', $query)));

            foreach ($protegidas as $tabla) {
                foreach (array_unique([$tabla, $prefijo.$tabla]) as $nombre) {
                    $t = preg_quote($nombre, '/');

                    // TRUNCATE: nunca, bajo ninguna forma.
                    if (preg_match('/^truncate\s+(table\s+)?`?'.$t.'`?/', $sql)) {
                        throw new RuntimeException(
                            "Test bloqueado: TRUNCATE sobre `{$nombre}`, que tiene datos reales. "
                            .'Borrá solo las filas que el test creó, por id.'
                        );
                    }

                    // DELETE sin WHERE: vacía la tabla igual que un truncate.
                    if (preg_match('/^delete\s+from\s+`?'.$t.'`?\s*$/', $sql)) {
                        throw new RuntimeException(
                            "Test bloqueado: DELETE sin WHERE sobre `{$nombre}`, que tiene datos reales. "
                            .'Acotá el borrado a las filas que el test creó.'
                        );
                    }

                    // DROP / ALTER: una migración destructiva disparada desde un test.
                    if (preg_match('/^(drop|alter)\s+table\s+`?'.$t.'`?/', $sql)) {
                        throw new RuntimeException(
                            "Test bloqueado: se intentó modificar la estructura de `{$nombre}`."
                        );
                    }
                }
            }
        });
    }
}
