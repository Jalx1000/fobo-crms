<?php

/*
|--------------------------------------------------------------------------
| Buscador de pacientes por nombre, teléfono o CI
|--------------------------------------------------------------------------
| El mismo (PatientSearch) en el listado de personas, la tabla y el kanban de
| leads, el calendario y el selector de paciente.
*/

require_once __DIR__.'/../Support/SmdAuditHelpers.php';

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Services\PatientSearch;
use Webkul\Lead\Models\Lead;
use Webkul\User\Models\User;

beforeEach(function () {
    $this->admin = User::find(1);
    $this->ciAttr = DB::table('attributes')->where('code', 'ci_paciente')->where('entity_type', 'persons')->value('id');

    // Datos que no choquen con los de producción (los tests corren contra esa base).
    $this->tag = 'Zq'.bin2hex(random_bytes(6));
    $this->local = '6'.rand(1000000, 9999999);
    $this->ci = (string) rand(100000000, 999999999);

    $this->javier = auditPerson([
        'name'            => "Javier {$this->tag} Alejandro Mogro",
        'contact_numbers' => [['value' => '591'.$this->local, 'label' => 'work']],
    ]);
    DB::table('attribute_values')->insert(['attribute_id' => $this->ciAttr, 'entity_id' => $this->javier->id, 'entity_type' => 'persons', 'text_value' => $this->ci.' LP']);

    $this->otra = auditPerson(['name' => "Otra {$this->tag} Persona"]);
});

function found(string $term): array
{
    return DB::table('persons')->whereIn('id', app(PatientSearch::class)->matchingPersons($term))->pluck('id')->map(fn ($id) => (int) $id)->all();
}

it('busca por nombre con las palabras en cualquier orden', function () {
    expect(found("mogro {$this->tag}"))->toContain($this->javier->id)->not->toContain($this->otra->id)
        ->and(found($this->tag))->toContain($this->javier->id, $this->otra->id);
});

it('busca por teléfono con o sin código de país, con espacios o signos', function () {
    $spaced = '+591 '.substr($this->local, 0, 4).' '.substr($this->local, 4);

    expect(found($this->local))->toContain($this->javier->id)
        ->and(found('591'.$this->local))->toContain($this->javier->id)
        ->and(found($spaced))->toContain($this->javier->id)
        ->and(found($this->local))->not->toContain($this->otra->id);
});

it('busca por CI aunque esté guardada con la sigla', function () {
    expect(found($this->ci))->toBe([$this->javier->id]);
});

it('menos de 4 dígitos no busca por teléfono ni CI', function () {
    expect(found('59'))->not->toContain($this->javier->id);
});

it('el listado de personas encuentra por CI y por teléfono', function () {
    foreach ([$this->ci, '+591 '.$this->local, "mogro {$this->tag}"] as $term) {
        $records = $this->actingAs($this->admin, 'user')
            ->getJson(route('admin.contacts.persons.index').'?'.http_build_query(['filters' => ['all' => [$term]]]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->json('records');

        expect(collect($records)->pluck('id')->map(fn ($id) => (int) $id)->all())->toBe([$this->javier->id]);
    }
});

it('la tabla de leads y el kanban encuentran el lead por la CI del paciente', function () {
    $lead = Lead::create(['title' => 'Lead busqueda', 'description' => 't', 'entity_type' => 'leads', 'person' => ['id' => $this->javier->id]]);
    DB::table('leads')->where('id', $lead->id)->update([
        'person_id'              => $this->javier->id,
        'lead_pipeline_id'       => DB::table('lead_pipelines')->where('is_default', 1)->value('id'),
        'lead_pipeline_stage_id' => (int) config('smd.stage_map.confirmed'),
    ]);

    $table = $this->actingAs($this->admin, 'user')
        ->getJson(route('admin.leads.index').'?'.http_build_query(['view_type' => 'table', 'filters' => ['all' => [$this->ci]]]), ['X-Requested-With' => 'XMLHttpRequest'])
        ->assertOk()
        ->json('records');

    expect(collect($table)->pluck('id')->map(fn ($id) => (int) $id)->all())->toBe([$lead->id]);

    $stageId = (int) DB::table('leads')->where('id', $lead->id)->value('lead_pipeline_stage_id');
    $kanban = $this->actingAs($this->admin, 'user')
        ->getJson(route('admin.leads.get').'?'.http_build_query(['q' => $this->ci, 'pipeline_stage_id' => $stageId]))
        ->assertOk()
        ->json();

    $ids = collect($kanban)->flatMap(fn ($stage) => collect($stage['leads']['data'])->pluck('id'))->all();
    expect($ids)->toBe([$lead->id]);
});

it('el calendario filtra las citas por el teléfono del paciente', function () {
    $doctorId = auditDoctor(['name' => 'Dra Busqueda '.uniqid()]);
    $day = Carbon::tomorrow(config('app.timezone'));
    $suya = auditAppointment($doctorId, $this->javier->id, $day->copy()->setTime(9, 0));
    $ajena = auditAppointment($doctorId, $this->otra->id, $day->copy()->setTime(10, 0));

    $ids = collect($this->actingAs($this->admin, 'user')
        ->getJson(route('admin.activities.get').'?'.http_build_query([
            'view_type'     => 'calendar', 'calendar_mode' => 'doctor', 'calendar_view' => 'list',
            'range_start'   => $day->toDateString(), 'range_end' => $day->toDateString(), 'q' => $this->local,
        ]))
        ->assertOk()
        ->json('appointments'))->pluck('id')->map(fn ($id) => (int) $id)->all();

    expect($ids)->toContain($suya['activity_id'])->not->toContain($ajena['activity_id']);
});

it('el selector de paciente encuentra por CI', function () {
    $data = $this->actingAs($this->admin, 'user')
        ->getJson(route('admin.contacts.persons.search', ['query' => $this->ci]))
        ->assertOk()
        ->json('data');

    expect(collect($data)->pluck('id')->all())->toBe([$this->javier->id]);
});
