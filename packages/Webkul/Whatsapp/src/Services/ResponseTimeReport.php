<?php

namespace Webkul\Whatsapp\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cuánto tarda en contestarse un mensaje de WhatsApp, separando al agente IA de
 * los asesores.
 *
 * El tablero ya tenía una tarjeta "Tiempo en responder", pero mide otra cosa: el
 * ciclo de vida del pedido, de created_at a la etapa donde está hoy. Eso es
 * cuánto tarda en cerrarse una venta, no cuánto tarda alguien en contestar.
 *
 * Y mezclarlos no servía. Medido sobre 30 días:
 *
 *   Agente IA   5.511 respuestas   mediana   0,1 min   p90    0,2 min
 *   Asesor        333 respuestas   mediana 303,5 min   p90 1.085,8 min
 *
 * Un promedio conjunto da ~17 minutos: un número que no describe a nadie y que
 * esconde que un cliente derivado puede esperar 18 horas. De ahí que la unidad
 * de medida sea la MEDIANA y el p90, y no el promedio: con una distribución así
 * de torcida, el promedio lo domina la cola.
 */
class ResponseTimeReport
{
    /** Quién mandó el saliente, tal como lo guarda la tabla. */
    private const AGENTE = 'ia';

    private const ASESOR = 'human';

    /** @var array<int, int>|null */
    private ?array $soloConversaciones = null;

    public function __construct(
        private Carbon $desde,
        private Carbon $hasta,
    ) {}

    /**
     * Acota el reporte a ciertas conversaciones.
     *
     * Sirve para mirar un caso concreto —"¿por qué esta charla aparece lenta?"—
     * y es lo que permite medirlo en un test sin que el tráfico real de la base
     * se mezcle con los datos de prueba.
     *
     * @param  array<int, int>  $ids
     */
    public function soloConversaciones(array $ids): self
    {
        $this->soloConversaciones = $ids;

        return $this;
    }

    /**
     * @return array{
     *     agente: array{respuestas: int, mediana: float, p90: float, promedio: float},
     *     asesor: array{respuestas: int, mediana: float, p90: float, promedio: float},
     *     por_asesor: array<int, array{nombre: string, respuestas: int, mediana: float, p90: float}>
     * }
     */
    public function build(): array
    {
        $latencias = $this->latencias();

        return [
            'agente'     => $this->resumen($latencias->where('sender', self::AGENTE)->pluck('minutos')->all()),
            'asesor'     => $this->resumen($latencias->where('sender', self::ASESOR)->pluck('minutos')->all()),
            'por_asesor' => $this->porAsesor($latencias->where('sender', self::ASESOR)),
        ];
    }

    /**
     * Minutos entre el último mensaje DEL CLIENTE y la respuesta que le siguió.
     *
     * Se toma el último entrante anterior a la respuesta, no el primero: si el
     * cliente escribió tres mensajes seguidos, el reloj arranca en el último
     * —es cuando terminó de hablar— y no en el primero, que daría una espera
     * inflada por su propia conversación.
     *
     * Los fallados quedan fuera: no son una respuesta, son un intento.
     */
    private function latencias()
    {
        // Sin prefijo a mano: el query builder ya lo agrega, y ponerlo acá
        // producía `kl_kl_whatsapp_messages`.
        $filas = DB::table('whatsapp_messages as m')
            ->leftJoin('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.direction', 'outbound')
            ->whereIn('m.sender', [self::AGENTE, self::ASESOR])
            ->where('m.status', '<>', 'failed')
            ->whereBetween('m.created_at', [$this->desde, $this->hasta])
            ->when($this->soloConversaciones, fn ($q, $ids) => $q->whereIn('m.conversation_id', $ids))
            ->select('m.sender', 'm.created_at', 'u.id as user_id', 'u.name as user_name')
            ->selectSub(
                DB::table('whatsapp_messages as i')
                    /*
                     * El alias también lleva prefijo: el query builder lo
                     * convierte en `kl_i`, así que un MAX(i.created_at) crudo
                     * no encuentra la columna.
                     */
                    ->selectRaw('MAX('.DB::getTablePrefix().'i.created_at)')
                    ->whereColumn('i.conversation_id', 'm.conversation_id')
                    ->where('i.direction', 'inbound')
                    ->whereColumn('i.id', '<', 'm.id'),
                'entrante'
            )
            ->get();

        return $filas
            ->filter(fn ($f) => $f->entrante !== null)
            ->map(function ($f) {
                return (object) [
                    'sender'     => $f->sender,
                    'user_id'    => $f->user_id,
                    'user_name'  => $f->user_name,
                    'minutos'    => (strtotime($f->created_at) - strtotime($f->entrante)) / 60,
                ];
            })
            // Un negativo sería un desorden de relojes, no una respuesta instantánea.
            ->filter(fn ($f) => $f->minutos >= 0)
            ->values();
    }

    /**
     * @param  array<int, float>  $minutos
     * @return array{respuestas: int, mediana: float, p90: float, promedio: float}
     */
    private function resumen(array $minutos): array
    {
        if (! $minutos) {
            return ['respuestas' => 0, 'mediana' => 0.0, 'p90' => 0.0, 'promedio' => 0.0];
        }

        sort($minutos);

        return [
            'respuestas' => count($minutos),
            'mediana'    => round($this->percentil($minutos, 0.5), 1),
            'p90'        => round($this->percentil($minutos, 0.9), 1),
            'promedio'   => round(array_sum($minutos) / count($minutos), 1),
        ];
    }

    /**
     * @return array<int, array{nombre: string, respuestas: int, mediana: float, p90: float}>
     */
    private function porAsesor($latencias): array
    {
        return $latencias
            ->filter(fn ($f) => $f->user_name !== null)
            ->groupBy('user_name')
            ->map(function ($suyas, $nombre) {
                $m = $suyas->pluck('minutos')->all();
                sort($m);

                return [
                    'nombre'     => $nombre,
                    'respuestas' => count($m),
                    'mediana'    => round($this->percentil($m, 0.5), 1),
                    'p90'        => round($this->percentil($m, 0.9), 1),
                ];
            })
            // El que tarda más, primero: es a quien hay que mirar.
            ->sortByDesc('mediana')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, float>  $ordenados
     */
    private function percentil(array $ordenados, float $p): float
    {
        $n = count($ordenados);

        if ($n === 0) {
            return 0.0;
        }

        return (float) $ordenados[min($n - 1, (int) floor($n * $p))];
    }
}
