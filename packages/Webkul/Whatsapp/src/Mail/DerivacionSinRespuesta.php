<?php

namespace Webkul\Whatsapp\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Webkul\Whatsapp\Models\Conversation;

/**
 * Aviso: un cliente derivado a un asesor sigue sin respuesta.
 */
class DerivacionSinRespuesta extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Conversation $conversation,
        public int $minutos,
        public ?string $asesor = null,
    ) {}

    public function build()
    {
        $cliente = $this->conversation->person->name
            ?? $this->conversation->wa_name
            ?? $this->conversation->wa_phone
            ?? 'Cliente sin nombre';

        $mensajes = $this->conversation->messages()
            ->where('direction', 'inbound')
            ->latest('id')
            ->limit(5)
            ->get()
            ->sortBy('id');

        $lista = '';

        foreach ($mensajes as $m) {
            $lista .= '<li style="margin:2px 0;">'.e(optional($m->created_at)->format('d/m H:i')).' — '
                .e(\Illuminate\Support\Str::limit((string) ($m->body ?: '['.$m->type.']'), 200)).'</li>';
        }

        $espera = $this->minutos >= 60
            ? intdiv($this->minutos, 60).' h '.($this->minutos % 60).' min'
            : $this->minutos.' min';

        $url = route('admin.whatsapp.index').'?conversation='.$this->conversation->id;

        $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#111827;">'
            .'<h2 style="color:#b45309;margin:0 0 12px;">Cliente derivado sin respuesta</h2>'
            .'<p><strong>'.e($cliente).'</strong>'.($this->conversation->wa_phone ? ' ('.e($this->conversation->wa_phone).')' : '')
            .' espera respuesta de un asesor hace <strong>'.e($espera).'</strong>.</p>'
            .'<p>Asesor asignado: <strong>'.e($this->asesor ?? 'nadie (sin asignar)').'</strong>'
            .($this->conversation->handoff_reason ? '<br>Motivo de la derivación: '.e($this->conversation->handoff_reason) : '').'</p>'
            .'<p style="margin-bottom:4px;">Últimos mensajes del cliente:</p><ul style="margin-top:0;">'.$lista.'</ul>'
            .'<p><a href="'.e($url).'" style="display:inline-block;padding:8px 16px;background:#2563eb;color:#fff;border-radius:6px;text-decoration:none;">Abrir la conversación</a></p>'
            .'</div>';

        return $this->subject('Cliente sin respuesta: '.$cliente)->html($html);
    }
}
