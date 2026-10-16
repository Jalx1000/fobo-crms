<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Los tests corren contra la base de producción, cada uno dentro de una
     * transacción que se deshace al terminar (tests/Pest.php). Un TRUNCATE,
     * DROP, ALTER o CREATE TABLE confirma esa transacción sin avisar y lo
     * que hace ya no se deshace: el 16/10/2026 un TRUNCATE vació tablas
     * reales. Se cortan ANTES de llegar a MySQL (beforeExecuting).
     */
    protected function setUp(): void
    {
        parent::setUp();

        DB::beforeExecuting(function (string $sql) {
            if (preg_match('/^\s*(truncate|drop|alter|create\s+table|rename\s+table)\b/i', $sql)) {
                throw new RuntimeException('Test bloqueado: «'.strtok($sql, "\n").'» no se puede deshacer y la base es la de producción. Trabajá solo con los registros que crea el test.');
            }
        });
    }
}
