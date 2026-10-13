<?php

namespace Webkul\Distribuidor\Listeners;

use Webkul\Core\ViewRenderEventManager;

/**
 * Agrega la tarjeta de bidones a la ficha del contacto del CRM.
 *
 * Se engancha al evento de render que la ficha ya dispara, en vez de editar
 * `admin::contacts.persons.view`. Dos razones:
 *
 *  1. Ese blade es del core de Krayin. Tocarlo deja un conflicto en cada
 *     actualización, y el día que alguien lo resuelva mal la tarjeta desaparece sin
 *     que nadie se entere.
 *  2. La dependencia queda apuntando bien: el módulo Distribuidor sabe que existen
 *     los contactos, y el módulo Admin no necesita saber que existen los bidones.
 */
class BidonesEnContacto
{
    public function __invoke(ViewRenderEventManager $manager): void
    {
        $manager->addTemplate('distribuidor::contacto.bidones');
    }
}
