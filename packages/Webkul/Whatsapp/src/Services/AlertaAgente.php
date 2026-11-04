<?php

namespace Webkul\Whatsapp\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Webkul\Whatsapp\Mail\AgenteConErrores;

/**
 * Cuenta las fallas del agente de IA y avisa por correo cuando se acumulan.
 *
 * Caso real: del 07/10 21:08 al 08/10 08:48 el agente contestó 23 veces
 * «Disculpe, ocurrió un error…» y nadie se enteró en 12 horas. Una falla suelta
 * no amerita un correo; varias seguidas sí, y una sola vez por rato.
 */
class AlertaAgente
{
    protected const EVENTOS = 'whatsapp:alerta-agente:eventos';

    protected const ENVIADA = 'whatsapp:alerta-agente:enviada';

    /**
     * ¿Este texto del agente es su mensaje de error genérico?
     */
    public function esRespuestaDeError(?string $texto): bool
    {
        $marca = mb_strtolower((string) config('whatsapp.alertas.texto_error'));

        return $marca !== '' && str_contains(mb_strtolower((string) $texto), $marca);
    }

    /**
     * Registra una falla y, si con ella se llega al umbral, manda el correo.
     */
    public function registrar(string $motivo, ?int $conversationId = null): void
    {
        $emails = (array) config('whatsapp.alertas.emails', []);

        if (! $emails) {
            return;
        }

        $ventana = max(1, (int) config('whatsapp.alertas.ventana_minutos', 15));
        $desde = now()->subMinutes($ventana)->getTimestamp();

        $eventos = array_values(array_filter(
            (array) Cache::get(self::EVENTOS, []),
            fn ($e) => ($e['ts'] ?? 0) >= $desde
        ));

        $eventos[] = ['ts' => now()->getTimestamp(), 'motivo' => $motivo, 'conversation_id' => $conversationId];

        Cache::put(self::EVENTOS, $eventos, now()->addMinutes($ventana));

        if (count($eventos) < max(1, (int) config('whatsapp.alertas.umbral', 3))) {
            return;
        }

        // add() solo escribe si no existe: un único correo por período de silencio.
        if (! Cache::add(self::ENVIADA, true, now()->addMinutes(max(1, (int) config('whatsapp.alertas.silencio_minutos', 60))))) {
            return;
        }

        try {
            Mail::to($emails)->send(new AgenteConErrores($eventos, $ventana));
        } catch (\Throwable $e) {
            // Que un SMTP caído no rompa la respuesta al agente ni el job.
            Log::error('WhatsApp: no se pudo mandar el aviso de agente con errores.', ['error' => $e->getMessage()]);
        }
    }
}
