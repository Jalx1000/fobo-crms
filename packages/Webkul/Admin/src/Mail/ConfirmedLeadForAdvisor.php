<?php

namespace Webkul\Admin\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Webkul\Lead\Services\CitySyncService;

/**
 * Aviso al asesor cuando su pedido pasa a "Cliente confirmado".
 *
 * Distinto de ConfirmedLeadNotification, que avisa al ALMACÉN para que prepare
 * la entrega. Este es para el comercial que tiene que darle seguimiento, así
 * que lleva el teléfono del cliente y la ciudad en vez de la dirección de envío.
 */
class ConfirmedLeadForAdvisor extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public $lead) {}

    public function build()
    {
        /*
         * El remitente sale de config/mail.php y no de core()->getSenderEmailDetails():
         * ese método NO existe en Webkul\Core\Core. ConfirmedLeadNotification —el
         * aviso al almacén, que ya estaba— lo llama igual, así que venía fallando
         * con "Call to undefined method" en cada intento y nunca envió nada.
         */
        return $this->from(config('mail.from.address'), config('mail.from.name'))
            ->subject('Pedido confirmado #'.$this->lead->id.' — '.$this->nombre())
            ->view('admin::emails.leads.confirmado-asesor', [
                'lead'      => $this->lead,
                'nombre'    => $this->nombre(),
                'telefono'  => $this->telefono(),
                'ciudad'    => $this->ciudad(),
                'etapa'     => optional($this->lead->stage)->name ?? 'Cliente confirmado',
                'productos' => $this->productos(),
            ]);
    }

    private function nombre(): string
    {
        return optional($this->lead->person)->name ?: $this->lead->title;
    }

    /**
     * El primer teléfono del contacto.
     *
     * `contact_numbers` es un json de [{label, value}]; según cómo se cargó el
     * contacto puede venir como array asociativo o como string suelto, así que
     * se cubren las dos formas.
     */
    private function telefono(): string
    {
        $numeros = optional($this->lead->person)->contact_numbers;

        if (is_string($numeros)) {
            $numeros = json_decode($numeros, true);
        }

        $primero = is_array($numeros) ? ($numeros[0] ?? null) : null;

        if (is_array($primero)) {
            return (string) ($primero['value'] ?? '—');
        }

        return $primero ? (string) $primero : '—';
    }

    /**
     * La ciudad ES el pipeline en este CRM, así que sale de ahí.
     */
    private function ciudad(): string
    {
        return optional($this->lead->pipeline)->name
            ?? CitySyncService::LEAD_CITY_ATTRIBUTE_CODE.' sin definir';
    }

    /**
     * @return array<int, string>
     */
    private function productos(): array
    {
        return $this->lead->products
            ->map(function ($producto) {
                $cantidad = (float) $producto->quantity;
                $monto = (float) $producto->amount;

                return rtrim(rtrim(number_format($cantidad, 2, ',', '.'), '0'), ',')
                    .' x '.($producto->name ?? 'Producto')
                    .' (Bs '.number_format($monto, 0, ',', '.').')';
            })
            ->all();
    }
}
