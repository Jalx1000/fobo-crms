<?php

namespace Webkul\Whatsapp\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso: el agente de IA acumuló fallas en poco tiempo.
 */
class AgenteConErrores extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array{ts: int, motivo: string, conversation_id: ?int}>  $eventos
     */
    public function __construct(public array $eventos, public int $ventana) {}

    public function build()
    {
        $motivos = [
            'respuesta_error'    => 'El agente le contestó al cliente «ocurrió un error»',
            'agente_no_responde' => 'El agente no recibió el mensaje (se agotaron los reintentos)',
        ];

        $filas = '';

        foreach ($this->eventos as $e) {
            $conv = $e['conversation_id']
                ? '<a href="'.e(route('admin.whatsapp.index').'?conversation='.$e['conversation_id']).'">#'.$e['conversation_id'].'</a>'
                : '-';

            $filas .= '<tr><td style="padding:4px 8px;">'.e(date('d/m H:i', $e['ts'])).'</td>'
                .'<td style="padding:4px 8px;">'.$conv.'</td>'
                .'<td style="padding:4px 8px;">'.e($motivos[$e['motivo']] ?? $e['motivo']).'</td></tr>';
        }

        $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#111827;">'
            .'<h2 style="color:#b91c1c;margin:0 0 12px;">El agente de IA está fallando</h2>'
            .'<p>Hubo <strong>'.count($this->eventos).' fallas</strong> en los últimos '.$this->ventana.' minutos. '
            .'Los clientes que escriben ahora pueden quedarse sin respuesta.</p>'
            .'<table style="border-collapse:collapse;border:1px solid #e5e7eb;">'
            .'<tr style="background:#f3f4f6;"><th style="padding:4px 8px;text-align:left;">Hora</th>'
            .'<th style="padding:4px 8px;text-align:left;">Conversación</th><th style="padding:4px 8px;text-align:left;">Qué pasó</th></tr>'
            .$filas.'</table>'
            .'<p style="margin-top:16px;">Qué revisar: que el servicio del agente esté en línea, sus logs y el saldo o los límites del modelo. '
            .'Mientras dure, conviene apagar la IA y atender desde la bandeja.</p>'
            .'<p style="color:#6b7280;font-size:12px;">No se vuelve a avisar durante '.(int) config('whatsapp.alertas.silencio_minutos', 60).' minutos.</p>'
            .'</div>';

        return $this->subject('⚠️ El agente de IA de Imprimir está fallando')->html($html);
    }
}
