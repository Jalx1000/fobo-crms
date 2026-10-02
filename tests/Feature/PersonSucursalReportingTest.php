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
 * Dentro de una transacción: la suite corre contra la base REAL.
 *
 * Sin esto, cada corrida dejaba filas permanentes. Medido: 17 personas "Test
 * Person", 7 "Export City Prospect" y 3 pipelines "Bogotá Export" acumulados en
 * producción, que además aparecían en los selectores del Punto de Distribuidor y
 * en el ruteo de derivaciones de WhatsApp.
 */

class PersonSucursalReportingTest extends TestCase
{
    /*
     * El trait va DENTRO de la clase: `uses(DatabaseTransactions::class)` es una
     * directiva de Pest y no alcanza a un test class-based de PHPUnit. Sin esto
     * cada corrida dejaba una persona y un lead permanentes en la base real.
     */
    use DatabaseTransactions;

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
        $leadId = DB::table('leads')->insertGetId([
            'title'      => 'Test Lead',
            'person_id'  => $personId,
            'created_at' => now(),
            'updated_at' => now(),
            // Add other required fields if necessary, assuming defaults or nullable
            'lead_pipeline_stage_id' => 1, // Assuming stage 1 exists
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
