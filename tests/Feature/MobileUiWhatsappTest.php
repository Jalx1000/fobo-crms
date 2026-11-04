<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Webkul\User\Models\User;

uses(DatabaseTransactions::class);

function bandejaHtml(): string
{
    return test()->actingAs(User::find(1), 'user')
        ->get(route('admin.whatsapp.index'))
        ->assertStatus(200)
        ->getContent();
}

/**
 * Compositor en el celular: el emoji lo da el teclado del teléfono y
 * respuestas rápidas / plantilla van en el menú «+», así la caja de texto
 * tiene lugar. En la computadora siguen siendo botones sueltos.
 */
it('en el celular el compositor agrupa respuestas rápidas y plantillas en el menú «+»', function () {
    expect(bandejaHtml())
        ->toContain("esCelular: window.matchMedia('(max-width: 1023px)').matches")
        ->toContain(":aria-label=\"esCelular ? 'Más opciones' : 'Adjuntar archivo'\"")
        ->toContain('v-if="(canAttach || esCelular) && ! recording"')
        ->toContain('@click="showAttach = false; openQuickReplies()"')
        ->toContain('@click="showAttach = false; openTemplates()"')
        ->toContain('<div v-if="canSend(\'emoji\')" class="js-pop relative max-lg:hidden">');
});

it('los adjuntos del menú solo aparecen si el proveedor puede mandar archivos', function () {
    $html = bandejaHtml();

    foreach (['image', 'document', 'audio'] as $tipo) {
        expect($html)->toContain("v-if=\"canAttach && canSend('{$tipo}')\"");
    }
});

it('al abrir un chat en el celular no se abre el teclado solo', function () {
    expect(bandejaHtml())->toContain('if (! this.esCelular) this.focusComposer();');
});

it('el detalle del cliente ocupa todo el ancho en el celular', function () {
    expect(bandejaHtml())
        ->toContain('max-w-[320px] flex-col max-lg:max-w-none')
        ->toContain('aria-label="Cerrar detalle"');
});
