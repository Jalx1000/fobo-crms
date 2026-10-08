<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Webkul\Admin\Helpers\Reporting\Lead as LeadReporting;
use Webkul\Attribute\Models\Attribute;
use Webkul\Contact\Models\Person;
use Webkul\Lead\Models\Lead;

/*
 * La suite corre contra la base de PRODUCCIÓN: todo dentro de una
 * transacción, así lo que el test crea se deshace al terminar.
 *
 * Creaba personas, pedidos y valores de atributo.
 */
class PersonSucursalReportingTest extends TestCase
{
    /*
     * El aislamiento y la sesión van DENTRO de la clase.
     *
     * Este archivo no usa it() de Pest sino una clase PHPUnit, y sobre una
     * clase declarada así `uses()` y `beforeEach()` no tienen efecto: quedaban
     * escritos y no se aplicaban.
     */
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Bouncer::getAuthorizedUserIds() lee auth()->user()->view_permission,
         * así que sin sesión reventaba con "Attempt to read property on null"
         * antes de llegar a medir nada.
         */
        $this->actingAs(getDefaultAdmin(), 'user');
    }

    public function test_leads_count_by_branches_uses_person_attribute()
    {
        // Ensure the attribute exists (mimic migration)
        $attribute = Attribute::where('code', 'sucursal')
            ->where('entity_type', 'persons')
            ->first();

        if (! $attribute) {
            $attribute = Attribute::create([
                'code'            => 'sucursal',
                'name'            => 'Sucursal',
                'type'            => 'text',
                'entity_type'     => 'persons',
                'admin_name'      => 'Sucursal',
                'is_required'     => 0,
                'is_unique'       => 0,
                'validation'      => '',
                'sort_order'      => 1,
                'is_user_defined' => 1,
            ]);
        }

        // Create a Person
        // Note: Using DB insert for simplicity if factories are not set up perfectly
        $personId = DB::table('persons')->insertGetId([
            'name'       => 'Test Person',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Add Sucursal Attribute Value
        $sucursalValue = 'Test Branch '.rand(1000, 9999);
        DB::table('attribute_values')->insert([
            'entity_type'  => 'persons',
            'attribute_id' => $attribute->id,
            'entity_id'    => $personId,
            'text_value'   => $sucursalValue,
        ]);

        // Create a Lead linked to this Person
        /*
         * El lead necesita un dueño, y NO puede ser un administrador.
         *
         * getLeadsCountByBranches() hace leftJoin a `users` y filtra dos veces
         * sobre users.id: por getAuthorizedUserIds() y por excludeIgnoredRoles(),
         * que deja afuera a "Administrador" y "Supervisores" para que sus leads
         * no ensucien las estadísticas de los asesores.
         *
         * Sin user_id el lead entraba al join con NULL y ningún whereIn lo
         * dejaba pasar; con el admin quedaba excluido por rol. Las dos veces el
         * test fallaba por algo que no tiene que ver con lo que quiere medir.
         */
        $asesorId = DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->whereNotIn('roles.name', ['Administrador', 'Supervisores'])
            ->where('users.status', 1)
            ->value('users.id');

        if (! $asesorId) {
            $this->markTestSkipped('No hay ningún usuario que cuente en el reporte.');
        }

        $leadId = DB::table('leads')->insertGetId([
            'title'                  => 'Test Lead',
            'person_id'              => $personId,
            'user_id'                => $asesorId,
            'created_at'             => now(),
            'updated_at'             => now(),
            'lead_pipeline_stage_id' => 1, // "No atendido", la etapa inicial
        ]);

        // Initialize Reporting Helper
        $reporting = app(LeadReporting::class);
        $reporting->setStartDate(now()->subDays(1));
        $reporting->setEndDate(now()->addDays(1));

        // Execute Method
        $result = $reporting->getLeadsCountByBranches();

        // Verify Result
        $this->assertIsArray($result);
        $this->assertArrayHasKey('labels', $result);
        $this->assertArrayHasKey('data', $result);

        $this->assertContains($sucursalValue, $result['labels'], "The result labels should contain the person's sucursal value.");

        $index = array_search($sucursalValue, $result['labels']);
        $this->assertGreaterThanOrEqual(1, $result['data'][$index], 'The count for the sucursal should be at least 1.');
    }
}
