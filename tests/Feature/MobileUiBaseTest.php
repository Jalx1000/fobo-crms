<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

/**
 * Barra inferior del celular: sale del menú real con los módulos de
 * `admin.mobile_nav` (WhatsApp, Citas, Pacientes, Actividades) y «Más».
 */
it('el layout trae la barra inferior con los módulos de Odontoking y «Más»', function () {
    $html = $this->actingAs(User::find(1), 'user')
        ->get(route('admin.dashboard.index'))
        ->assertStatus(200)
        ->assertSee('aria-label="Navegación principal"', false)
        ->assertSee("\$emitter.emit('mobile-nav:more')", false)
        ->assertSee('viewport-fit=cover', false)
        ->getContent();

    preg_match('#<nav\s+aria-label="Navegación principal".*?</nav>#s', $html, $nav);

    expect($nav)->not->toBeEmpty();

    foreach (['whatsapp', 'leads', 'contacts', 'activities'] as $modulo) {
        expect($nav[0])->toContain(route('admin.'.($modulo === 'contacts' ? 'contacts.persons' : $modulo).'.index'));
    }

    // Configuración nunca va en la barra: se llega por «Más».
    expect($nav[0])->not->toContain(route('admin.settings.index'));
});

it('la barra inferior respeta admin.mobile_nav', function () {
    config(['admin.mobile_nav' => ['products']]);

    $html = $this->actingAs(User::find(1), 'user')
        ->get(route('admin.dashboard.index'))
        ->getContent();

    preg_match('#<nav\s+aria-label="Navegación principal".*?</nav>#s', $html, $nav);

    // El primer enlace es el configurado; el resto se completa con el menú.
    preg_match('#href="([^"]+)"#', $nav[0], $primero);

    expect($primero[1])->toBe(route('admin.products.index'));
});

/**
 * Las listas usan la tarjeta compartida en el celular, y en las pantallas
 * que editan en un modal «Editar» abre el modal (navegar a la URL mostraba JSON).
 */
it('Usuarios usa la tarjeta compartida y «Editar» abre el modal', function () {
    $this->actingAs(User::find(1), 'user')
        ->get(route('admin.settings.users.index'))
        ->assertStatus(200)
        ->assertSee('v-datagrid-mobile-card-template', false)
        ->assertSee("accion.index === 'edit' ? editModal(accion.url) : performAction(accion)", false);
});

/**
 * Bandeja de WhatsApp: en el celular la fila de etapas va plegada detrás de
 * un botón y la barra inferior se esconde con un chat abierto.
 */
it('la Bandeja pliega las etapas y esconde la barra con un chat abierto', function () {
    $this->actingAs(User::find(1), 'user')
        ->get(route('admin.whatsapp.index'))
        ->assertStatus(200)
        ->assertSee('aria-label="Filtrar por etapa"', false)
        ->assertSee("filtrosAbiertos ? '' : 'max-lg:hidden'", false)
        ->assertSee("document.body.classList.toggle('chat-abierto'", false);
});

/**
 * El hilo pasa todas las altas por guardarMensaje(): sin duplicados y en
 * orden de llegada aunque el poll y el envío se crucen.
 */
it('el hilo de WhatsApp guarda los mensajes en un solo lugar y ordenados', function () {
    $html = $this->actingAs(User::find(1), 'user')
        ->get(route('admin.whatsapp.index'))
        ->getContent();

    expect($html)
        ->toContain('guardarMensaje(mensaje, reemplazaA = null)')
        ->toContain('this.guardarMensaje(res.data?.message, tempId)')
        ->toContain('this.guardarMensaje(res.data?.note, tempId)')
        ->not->toContain('this.messages.push(data.message)');
});
