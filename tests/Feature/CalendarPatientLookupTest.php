<?php

/*
|--------------------------------------------------------------------------
| Selector de paciente del modal "Nueva cita" (calendario)
|--------------------------------------------------------------------------
| El backend (PatientSearch) encuentra por nombre, CI o teléfono, pero el
| componente lookup volvía a filtrar en el navegador por `name.includes(...)`
| y descartaba los que coincidían por CI o teléfono (y "javier mogro" contra
| "Javier Alejandro Mogro"). Los selectores de paciente del calendario usan
| `server-filtered` para mostrar lo que devuelve el backend tal cual.
*/

use Webkul\User\Models\User;

function calendarHtml(): string
{
    return test()->actingAs(User::find(1), 'user')
        ->get(route('admin.activities.index'))
        ->assertOk()
        ->getContent();
}

it('los selectores de paciente del calendario no filtran por nombre en el navegador', function () {
    $html = calendarHtml();

    foreach (['appointment_person', 'appointment_person_edit'] as $name) {
        expect($html)->toMatch('/name="'.$name.'"\s+placeholder="Buscar por nombre, CI o teléfono…"\s+server-filtered/');
    }
});

it('el lookup respeta server-filtered y muestra CI y teléfono del resultado', function () {
    $html = calendarHtml();

    expect($html)
        ->toContain('serverFiltered: {')
        ->toContain("if (this.serverFiltered) {\n                        return this.searchedResults;")
        ->toContain('itemDetail(item)');
});

it('el selector de servicio sigue filtrando por nombre como antes', function () {
    expect(calendarHtml())->not->toMatch('/name="appointment_product"\s+placeholder="[^"]*"\s+server-filtered/');
});
