<?php

/*
| Filtros del calendario (especialidad, doctores, estado). Antes el selector de
| doctores mostraba 2 nombres sueltos y un contador que decía "0" con 1–2
| doctores, "Todos/Ninguno" estaban fuera de la lista, no se usaba con teclado,
| no recordaba la elección y "Ninguno" dejaba el calendario rayado sin aviso.
*/

function calendarFiltersView(): string
{
    return file_get_contents(__DIR__.'/../../packages/Webkul/Admin/src/Resources/views/components/activities/doctor-day-calendar.blade.php');
}

// Barra de escritorio: del buscador hasta el panel del celular (que repite los filtros).
function calendarToolbar(): string
{
    $view = calendarFiltersView();
    $start = strpos($view, '<div class="dwc-controls ddc-toolbar">');

    return substr($view, $start, strpos($view, '<!-- Celular/tablet: todos los filtros en un panel', $start) - $start);
}

function calendarFiltersSheet(): string
{
    $view = calendarFiltersView();
    $start = strpos($view, '<!-- Celular/tablet: todos los filtros en un panel');

    return substr($view, $start, strpos($view, '<!-- Sin doctores en el filtro', $start) - $start);
}

it('la barra ofrece especialidad, doctores y estado, en ese orden', function () {
    $toolbar = calendarToolbar();

    $order = array_map(fn ($l) => strpos($toolbar, 'label="'.$l.'"'), ['Especialidad', 'Doctores', 'Estado']);

    expect($order)->each->not->toBeFalse()
        ->and($order)->toBe(collect($order)->sort()->values()->all())
        ->and($toolbar)->toContain(':items="doctorsInSpecialty"')
        ->toContain('v-model="selectedSpecialtyIds"')
        ->toContain('v-model="selectedStageIds"');
});

it('especialidad y estado vacíos significan sin filtrar; doctores vacío no', function () {
    $toolbar = calendarToolbar();

    expect(substr_count($toolbar, 'empty-means-all'))->toBe(2)
        ->and($toolbar)->toMatch('/label="Doctores"\s+all-text="Todos"\s+empty-text="Ninguno"/');
});

it('el botón del filtro resume la selección en vez de chips con un contador', function () {
    $view = calendarFiltersView();

    expect($view)
        ->toContain('if (n === total) return `${this.allText} (${total})`;')
        ->toContain('if (n === 1) return this.selectedItems[0].name;')
        ->toContain('return `${n} ${this.plural}`;')
        // El filtro activo se ve: clase propia y texto del resumen.
        ->toContain(':class="{ \'is-filtered\': isFiltered }"');
});

it('el filtro se usa con teclado: botón real, checkboxes enfocables y Esc cierra', function () {
    $view = calendarFiltersView();
    $tpl = substr($view, strpos($view, 'id="v-filter-select-template"'), 3000);

    expect($tpl)->toContain('@keydown.esc="close(true)"')
        ->toContain('<button')
        ->toContain('aria-haspopup="true"')
        ->toContain(':aria-expanded="open ? \'true\' : \'false\'"')
        ->toContain('<label class="fs-item">')
        ->toContain('<input type="checkbox"')
        // Todos/Ninguno dentro del desplegable.
        ->toContain('@click="selectVisible">Seleccionar todos</button>')
        ->toContain('@click="clearVisible">Ninguno</button>');

    expect($view)->toContain('if (returnFocus) this.$nextTick(() => this.$refs.trigger?.focus());');
});

it('el filtro de estado acota todas las vistas', function () {
    $view = calendarFiltersView();

    expect($view)->toContain('return this.appointments.filter(a => stages.has(String(a.lead_pipeline_stage_id)));')
        ->toContain('const events = this.visibleAppointments')
        ->toMatch('/return this\.visibleAppointments\s+\.filter\(a => ids\.has\(String\(a\.doctor_id\)\)\)/')
        ->toContain('return this.visibleAppointments.filter(a => String(a.doctor_id) === String(doctorId)).length;');
});

it('los filtros se recuerdan por navegador y "todos los doctores" se guarda como null', function () {
    $view = calendarFiltersView();

    expect($view)->toContain("const FILTERS_STORAGE_KEY = 'odk.calendar.filters.v1';")
        ->toContain('doctors: allDoctors ? null : this.selectedDoctorIds,')
        ->toContain('this.restoreFilters();')
        // localStorage puede fallar (modo privado): nunca rompe el calendario.
        ->toMatch('/try \{\s*window\.localStorage\.setItem/')
        ->toMatch('/try \{\s*saved = JSON\.parse\(window\.localStorage\.getItem/');
});

it('sin doctores en el filtro se avisa y se ofrece mostrar todos', function () {
    expect(calendarFiltersView())
        ->toContain('<div v-if="!columns.length && doctors.length && !isLoading" class="ddc-filter-empty" role="status">')
        ->toContain('<button type="button" class="ddc-tb-link" @click="clearFilters">Mostrar todos</button>');
});

it('hay un "Limpiar filtros" y la búsqueda de paciente se borra con un botón', function () {
    $toolbar = calendarToolbar();

    expect($toolbar)->toContain('<button v-if="hasActiveFilters" type="button" class="ddc-tb-link ddc-tb-clear" @click="clearFilters">Limpiar filtros</button>')
        ->toContain('aria-label="Borrar búsqueda de paciente"');
});

it('los modales de cita siguen usando su selector de doctores', function () {
    $view = calendarFiltersView();

    expect($view)->toContain('<script type="text/x-template" id="v-doctor-multiselect-template">')
        ->toContain("app.component('v-doctor-multiselect', {")
        ->toContain('<v-doctor-multiselect :items="doctors" v-model="appointmentForm.doctor_ids"></v-doctor-multiselect>');
});

it('en el celular los filtros se abren desde un botón "Filtros" con el número de activos', function () {
    $view = calendarFiltersView();
    $mobile = substr($view, strpos($view, '/* Celular y tablet: una fila por control, todo a lo ancho. */'), 6000);

    expect(calendarToolbar())->toContain('class="ddc-tb-filters-btn"')
        ->toContain('aria-haspopup="dialog"')
        ->toContain('@click="openMobileFilters"')
        ->toContain('<span v-if="activeFilterCount" class="ddc-tb-filters-badge" aria-hidden="true">@{{ activeFilterCount }}</span>');

    // Escritorio: sin botón ni panel. Celular: sin los desplegables, buscador y botón en una fila.
    expect($view)->toContain('.ddc-tb-filters-btn, .ddc-sheet, .ddc-sheet-overlay { display: none; }')
        ->toContain('.ddc-tb-searchrow { display: contents; }')
        ->and($mobile)->toContain('.ddc-tb-doctors { display: none; }')
        ->toContain('.ddc-tb-searchrow { display: flex; gap: 8px; align-items: center; }');
});

it('el panel muestra los tres filtros desplegados y se cierra con Esc o "Ver N citas"', function () {
    $sheet = calendarFiltersSheet();

    expect(substr_count($sheet, '<v-filter-select'))->toBe(3)
        ->and(substr_count($sheet, "\n                        inline\n"))->toBe(3)
        ->and($sheet)->toContain('role="dialog"')
        ->toContain('aria-modal="true"')
        ->toContain('@keydown.esc.stop="closeMobileFilters"')
        ->toContain('<div v-if="mobileFiltersOpen" class="ddc-sheet-overlay" @click="closeMobileFilters"></div>')
        ->toContain('Ver @{{ citasLabel(listAppointments.length) }}')
        // "Limpiar" del panel no borra la búsqueda de paciente.
        ->toContain('@click="clearPanelFilters">Limpiar</button>');

    preg_match('/clearPanelFilters\(\) \{(.*?)\n            \},/s', calendarFiltersView(), $m);
    expect($m[1])->not->toContain('patientSearch');
});

it('el foco va al panel al abrirlo y vuelve al botón al cerrarlo', function () {
    $view = calendarFiltersView();

    expect($view)->toContain('this.$nextTick(() => this.$refs.sheetClose?.focus());')
        ->toContain('this.$nextTick(() => this.$refs.filtersButton?.focus());');
});

it('el filtro inline no tiene botón y muestra la lista siempre', function () {
    $view = calendarFiltersView();
    $tpl = substr($view, strpos($view, 'id="v-filter-select-template"'), 3500);

    expect($tpl)->toMatch('/<button\s+v-if="!inline"\s+type="button"\s+ref="trigger"/')
        ->toContain('v-if="inline || open"')
        ->toContain(":class=\"inline ? 'fs-inline' : 'fs-dropdown'\"")
        ->and($view)->toContain('inline: { type: Boolean, default: false },')
        ->toContain('.fs-inline .fs-list { max-height: none; overflow: visible; }');
});
