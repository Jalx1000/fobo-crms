<?php

namespace Webkul\Whatsapp\Helpers\Reporting;

use Illuminate\Support\Facades\DB;
use Webkul\Admin\Helpers\Alcance;
use Webkul\Whatsapp\Services\ConversacionesVisibles;

/**
 * Lo que está pasando AHORA en WhatsApp, para el tablero del gerente.
 *
 * Es deliberadamente un reporte de estado presente y no de período: a un
 * gerente, «cuántas conversaciones quedaron sin responder el mes pasado» no le
 * sirve para nada, y «cuántas hay sin responder en este momento» le dice si
 * tiene que llamar a alguien por teléfono.
 *
 * Por eso ninguna de estas cifras toma el filtro de fechas del tablero. Es la
 * excepción consciente al resto de las tarjetas.
 *
 * Sí toma el alcance del usuario (spec tablero-por-asesor, D9): un asesor ve
 * solo lo que le toca atender a él —las mismas conversaciones de su inbox y
 * las derivaciones a su nombre—, así el número de la tarjeta coincide con lo
 * que encuentra al hacer clic.
 */
class Operacion
{
    /**
     * Las cifras operativas, en una sola consulta por métrica.
     *
     * @return array<string, mixed>
     */
    public function getResumen(): array
    {
        $horas = (int) config('whatsapp.ai.window_hours', 24);

        return [
            'sin_responder'        => $this->sinResponder(),
            'ventanas_por_cerrar'  => $this->ventanasPorCerrar($horas),
            'derivaciones_libres'  => $this->derivacionesPorEstado('abierto'),
            'derivaciones_activas' => $this->derivacionesPorEstado('tomado'),
            'ia_apagada'           => $this->conIaApagada(),
            'por_asesor'           => $this->derivacionesPorAsesor(),
        ];
    }

    /**
     * Conversaciones con mensajes del cliente sin leer.
     */
    protected function sinResponder(): int
    {
        return (int) $this->conversaciones()->where('unread_count', '>', 0)->count();
    }

    /**
     * Ventanas de 24 h que se cierran dentro de las próximas 3 horas.
     *
     * El corte de 3 h es el mismo que usa el aviso del inbox, para que el
     * gerente y el asesor no vean dos verdades distintas sobre la misma
     * conversación.
     *
     * Solo cuenta las que siguen abiertas: una ventana ya cerrada no es algo
     * que se pueda salvar llamando a alguien.
     */
    protected function ventanasPorCerrar(int $horas): int
    {
        $limite = now()->subHours($horas);

        return (int) $this->conversaciones()
            ->whereNotNull('last_inbound_at')
            ->where('last_inbound_at', '>', $limite)
            ->where('last_inbound_at', '<=', $limite->copy()->addHours(3))
            ->count();
    }

    protected function derivacionesPorEstado(string $estado): int
    {
        return (int) $this->derivaciones()->where('state', $estado)->count();
    }

    /**
     * Conversaciones donde alguien apagó el agente y no lo volvió a encender.
     *
     * Es deuda operativa silenciosa: el asesor lo apagó para atender a mano, se
     * fue el viernes y el cliente quedó sin nadie del otro lado.
     */
    protected function conIaApagada(): int
    {
        return (int) $this->conversaciones()->where('ai_enabled', 0)->count();
    }

    /**
     * Derivaciones activas por asesor: quién está cargado y quién está libre.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function derivacionesPorAsesor(): array
    {
        return $this->derivaciones()
            ->join('users', 'whatsapp_handoffs.user_id', '=', 'users.id')
            ->where('whatsapp_handoffs.state', 'tomado')
            // `select` y no `selectRaw` para las columnas: el raw no aplica el
            // prefijo de tablas y la consulta falla con «unknown column».
            ->select('users.name as asesor')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('users.name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => ['asesor' => $r->asesor, 'total' => (int) $r->total])
            ->all();
    }

    /**
     * Las conversaciones que el usuario ve en su inbox.
     */
    protected function conversaciones()
    {
        return app(ConversacionesVisibles::class)->aplicar(DB::table('whatsapp_conversations'));
    }

    /**
     * Las derivaciones del usuario: todas si ve el total; si no, las asignadas
     * a él —son las únicas que puede tomar (D14)—.
     */
    protected function derivaciones()
    {
        $userId = app(Alcance::class)->userId();

        return DB::table('whatsapp_handoffs')
            ->when($userId !== null, fn ($q) => $q->where('whatsapp_handoffs.user_id', $userId));
    }
}
