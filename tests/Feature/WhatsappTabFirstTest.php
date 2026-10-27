<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\Contact\Models\Person;
use Webkul\Lead\Models\Lead;
use Webkul\User\Models\User;

use function Pest\Laravel\actingAs;

uses(DatabaseTransactions::class);

/**
 * En la cita y en el paciente, el panel derecho abre en WhatsApp, que además
 * es la primera pestaña: es por donde se atiende al paciente.
 */
it('la ficha del paciente abre en la pestaña WhatsApp, primera de la fila', function () {
    $person = Person::query()->latest('id')->first();

    actingAs(User::find(1), 'user')
        ->get(route('admin.contacts.persons.view', $person->id))
        ->assertOk()
        ->assertSee('active-type="whatsapp"', false)
        ->assertSee('{"name":"whatsapp","label":"WhatsApp","primera":true}', false);
});

it('la cita abre en la pestaña WhatsApp, primera de la fila, y el chat no estira la columna', function () {
    $lead = Lead::query()->latest('id')->first();

    if (! $lead) {
        $this->markTestSkipped('No hay citas.');
    }

    actingAs(User::find(1), 'user')
        ->get(route('admin.leads.view', $lead->id))
        ->assertOk()
        ->assertSee('active-type="whatsapp"', false)
        ->assertSee('{"name":"whatsapp","label":"WhatsApp","primera":true}', false)
        ->assertSee('flex w-full min-w-0 flex-col gap-4 rounded-lg', false);
});

it('el panel de actividades respeta la pestaña pedida en vez de forzar «Todo»', function () {
    $componente = file_get_contents(base_path('packages/Webkul/Admin/src/Resources/views/components/activities/index.blade.php'));

    expect($componente)
        ->toContain('type.primera ? this.types.unshift(type) : this.types.push(type);')
        ->toContain('? this.activeType')
        ->not->toContain("this.selectedType = 'all';\n");
});
