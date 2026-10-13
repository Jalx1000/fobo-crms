<?php

namespace Webkul\Admin\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        'contacts.person.create.after' => [
            'Webkul\Admin\Listeners\Person@linkToEmail',
            'Webkul\Admin\Listeners\Ciudad@dePersona',
        ],

        /*
        | La ciudad del cliente viaja en los dos sentidos entre el contacto, la
        | oportunidad y la cotización: decide la zona del precio y quién
        | atiende, así que tenerla distinta en cada lado es un error caro.
        */
        'contacts.person.update.after' => [
            'Webkul\Admin\Listeners\Ciudad@dePersona',
        ],

        'lead.create.after' => [
            'Webkul\Admin\Listeners\Lead@linkToEmail',
            'Webkul\Admin\Listeners\Ciudad@deOportunidad',
            'Webkul\Admin\Listeners\ValorPedido@deOportunidad',
        ],

        'lead.update.after' => [
            'Webkul\Admin\Listeners\Lead@handleLeadUpdate',
            'Webkul\Admin\Listeners\Ciudad@deOportunidad',
            'Webkul\Admin\Listeners\ValorPedido@deOportunidad',
            'Webkul\Admin\Listeners\AsesorCotizaciones@dePedido',
        ],

        /*
        | El NIT viaja en los dos sentidos entre la cotización y la persona.
        | Se engancha acá y no en el controlador del CRM porque la API REST
        | dispara los mismos eventos: así el `register_cotizacion` del agente
        | pasa por el mismo camino que un asesor cargando a mano.
        */
        'quote.create.after' => [
            'Webkul\Admin\Listeners\Nit@sincronizar',
            'Webkul\Admin\Listeners\Ciudad@deCotizacion',
            'Webkul\Admin\Listeners\Sucursal@desdeElProducto',
            'Webkul\Admin\Listeners\ValorPedido@deCotizacion',
            'Webkul\Admin\Listeners\ProductoPedido@deCotizacion',
            // Correo con el detalle al asesor (MAIL_COTIZACION_NUEVA). Último:
            // los de arriba completan ciudad y sucursal antes de que se lea.
            'Webkul\Admin\Listeners\CotizacionNueva@handle',
        ],

        // Cotización enviada por WhatsApp: queda en el historial del lead y del contacto.
        'quote.whatsapp.after' => [
            'Webkul\Admin\Listeners\CotizacionEnviada@handle',
        ],

        'quote.messenger.after' => [
            'Webkul\Admin\Listeners\CotizacionEnviada@handle',
        ],

        'quote.update.after' => [
            'Webkul\Admin\Listeners\Nit@sincronizar',
            'Webkul\Admin\Listeners\Ciudad@deCotizacion',
            'Webkul\Admin\Listeners\ValorPedido@deCotizacion',
            'Webkul\Admin\Listeners\ProductoPedido@deCotizacion',
        ],

        'activity.create.after' => [
            'Webkul\Admin\Listeners\Activity@afterUpdateOrCreate',
        ],

        'activity.update.after' => [
            'Webkul\Admin\Listeners\Activity@afterUpdateOrCreate',
        ],
    ];
}
