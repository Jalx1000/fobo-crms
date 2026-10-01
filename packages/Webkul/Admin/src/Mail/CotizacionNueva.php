<?php

namespace Webkul\Admin\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Webkul\Admin\Listeners\Ciudad;
use Webkul\Product\Support\Opciones;

/**
 * Detalle de una cotización recién creada para el equipo: la cotización, el
 * cliente, la oportunidad y los productos.
 *
 * Sin $asesor es la copia fija (MAIL_COTIZACION_NUEVA_COPIA), que además lista
 * a quiénes se les designó.
 */
class CotizacionNueva extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public $quote,
        public $asesor = null,
        public array $designados = [],
    ) {}

    public function build()
    {
        $quote = $this->quote->loadMissing(['items', 'person.organization', 'user', 'leads.stage', 'leads.pipeline', 'leads.user']);

        return $this->subject("Nueva cotización #{$quote->id}: {$quote->subject}")
            ->view('admin::emails.cotizacion-nueva', [
                'datos' => $this->datos($quote),
                'lead'  => $quote->leads->first(),
            ]);
    }

    /**
     * Los campos de la cotización ya legibles. Los selects guardan el id de la
     * opción y la sucursal el id del almacén: se resuelven como en el PDF.
     *
     * @return array<string, ?string>
     */
    protected function datos($quote): array
    {
        $ciudades = app(Ciudad::class);

        $sucursal = is_numeric($quote->sucursal ?? null)
            ? DB::table('warehouses')->where('id', $quote->sucursal)->value('name')
            : ($quote->sucursal ?: null);

        return array_filter([
            'Asunto'           => $quote->subject,
            'Fecha'            => $quote->created_at?->format('d/m/Y H:i'),
            'Válida hasta'     => $quote->expired_at ? \Carbon\Carbon::parse($quote->expired_at)->format('d/m/Y') : null,
            'Asesor'           => $quote->user?->name,
            'Ciudad'           => $ciudades->nombreDe('quotes', $quote->ciudad ?? null),
            'Sucursal'         => $sucursal,
            'Razón social'     => $quote->razon_social ?? null,
            'NIT'              => $quote->nit ?? null,
            'Forma de pago'    => Opciones::nombre($quote->forma_pago ?? null),
            'Lugar de entrega' => Opciones::nombre($quote->lugar_entrega ?? null),
            'Tiempo de entrega'=> $quote->tiempo_entrega ?? null,
        ], fn ($valor) => filled($valor));
    }
}
