<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Webkul\Admin\Helpers\Reporting\Lead as LeadReporting;
use Webkul\Attribute\Models\Attribute;
use Webkul\Contact\Models\Person;
use Webkul\Lead\Models\Lead;

class PersonSucursalReportingTest extends TestCase
{
    // Corre contra la base real: todo lo que crea se deshace al terminar.
    use DatabaseTransactions;

    public function test_leads_count_by_branches_uses_person_attribute()
    {
        // El reporte filtra por los usuarios que puede ver quien lo pide.
        $this->actingAs(\Webkul\User\Models\User::findOrFail(1), 'user');

        // Los pedidos de administradores y supervisores no cuentan: el pedido
        // va a nombre de un asesor.
        $asesor = \Webkul\User\Models\User::whereHas('role', fn ($q) => $q->whereNotIn('name', ['Administrador', 'Supervisores']))
            ->where('status', 1)->firstOrFail();

        // Ensure the attribute exists (mimic migration)
        $attribute = Attribute::where('code', 'sucursal')
            ->where('entity_type', 'persons')
            ->first();

        if (! $attribute) {
            $attribute = Attribute::create([
                'code' => 'sucursal',
                'name' => 'Sucursal',
                'type' => 'text',
                'entity_type' => 'persons',
                'admin_name' => 'Sucursal',
                'is_required' => 0,
                'is_unique' => 0,
                'validation' => '',
                'sort_order' => 1,
                'is_user_defined' => 1,
            ]);
        }

        // Create a Person
        // Note: Using DB insert for simplicity if factories are not set up perfectly
        $personId = DB::table('persons')->insertGetId([
            'name' => 'Test Person',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Add Sucursal Attribute Value
        $sucursalValue = 'Test Branch '.rand(1000, 9999);
        DB::table('attribute_values')->insert([
            'entity_type' => 'persons',
            'attribute_id' => $attribute->id,
            'entity_id' => $personId,
            'text_value' => $sucursalValue,
        ]);

        // Create a Lead linked to this Person
        $leadId = DB::table('leads')->insertGetId([
            'title' => 'Test Lead',
            'person_id' => $personId,
            'user_id' => $asesor->id,
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
