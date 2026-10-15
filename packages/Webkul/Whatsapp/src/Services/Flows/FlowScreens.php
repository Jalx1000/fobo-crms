<?php

namespace Webkul\Whatsapp\Services\Flows;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Helpers comunes a los formularios: armar pantallas, el cierre y las fechas
 * en español ("Lun 13/10 · 09:00").
 */
trait FlowScreens
{
    protected function screen(string $screen, array $data, ?string $error = null): array
    {
        if ($error) {
            $data['error_message'] = $error;
        }

        return ['screen' => $screen, 'data' => $data];
    }

    /**
     * Cierre: los params viajan al webhook dentro de `nfm_reply.response_json`.
     */
    protected function finish(string $token, string $flow, array $params): array
    {
        return [
            'screen' => 'SUCCESS',
            'data'   => [
                'extension_message_response' => [
                    'params' => array_filter(
                        ['flow_token' => $token, 'flow' => $flow] + $params,
                        fn ($v) => $v !== null && $v !== '',
                    ),
                ],
            ],
        ];
    }

    protected function name(string $table, $id): string
    {
        return trim((string) DB::table($table)->where('id', (int) $id)->value('name'));
    }

    /** "Lun 13/10" */
    protected function dayLabel(string $date): string
    {
        $d = Carbon::parse($date)->locale('es');

        return ucfirst(rtrim($d->isoFormat('ddd'), '.')).' '.$d->format('d/m');
    }

    /** "Lun 13/10 · 09:00" */
    protected function label(string $dateTime): string
    {
        [$date, $time] = explode(' ', $dateTime) + [1 => ''];

        return $this->dayLabel($date).' · '.substr($time, 0, 5);
    }
}
