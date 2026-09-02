<?php

namespace Webkul\Whatsapp\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;
use Webkul\User\Models\UserProxy;
use Webkul\Whatsapp\Mail\HandoffSinTomar;
use Webkul\Whatsapp\Models\HandoffProxy;

/**
 * Avisa al supervisor de las derivaciones que nadie tomó a tiempo.
 *
 * Es la red de contención del handoff: al derivar, el agente deja de responder,
 * así que una derivación sin tomar es un cliente esperando en silencio. El aviso
 * al asesor sale por mail al momento, pero un mail puede rebotar, demorarse o
 * quedar sin leer — y nadie más se entera.
 *
 * Notifica UNA sola vez por derivación (`escalated_at`): repetir el aviso cada
 * cinco minutos enseña a ignorarlo, que es lo contrario de lo que se busca.
 */
class EscalarHandoffs extends Command
{
    protected $signature = 'crm:escalar-handoffs {--dry-run : Muestra a quién se avisaría sin mandar nada}';

    protected $description = 'Notifica al supervisor las derivaciones sin tomar';

    public function handle(): int
    {
        $simular = (bool) $this->option('dry-run');

        $supervisores = $this->supervisores();

        if ($supervisores->isEmpty()) {
            $this->error('No hay a quién escalar: ni supervisor configurado ni administradores con email.');

            return self::FAILURE;
        }

        $minutos = (int) config('inmobiliaria.inmuebles.handoff.escalar_tras_minutos', 15);

        $vencidas = HandoffProxy::modelClass()::query()
            ->with(['conversation', 'asesor'])
            ->where('state', 'abierto')          // 'tomado' ya tiene quien la atienda
            ->whereNull('escalated_at')
            ->where('opened_at', '<=', now()->subMinutes($minutos))
            ->get();

        if ($vencidas->isEmpty()) {
            $this->info('Ninguna derivación sin tomar pasó los '.$minutos.' minutos.');

            return self::SUCCESS;
        }

        $nombres = $supervisores->pluck('name')->implode(', ');

        $this->info($simular
            ? "SIMULACIÓN — se avisaría a {$nombres} de {$vencidas->count()} derivación(es)"
            : "Avisando a {$nombres} de {$vencidas->count()} derivación(es)");

        foreach ($vencidas as $handoff) {
            $espera = (int) $handoff->opened_at->diffInMinutes(now());

            $this->line("  #{$handoff->id} · conversación {$handoff->conversation_id}"
                ." · {$handoff->asesor?->name} · {$espera} min sin tomar");

            if ($simular) {
                continue;
            }

            /*
            | Un envío que falla no puede tumbar la corrida.
            |
            | El correo de esta instancia puede estar caído —hoy mismo lo está,
            | por credenciales— y sin este corte la primera derivación fallida
            | dejaría a las siguientes sin siquiera intentarse.
            |
            | No se marca `escalated_at` cuando falla: así el aviso sale solo en
            | cuanto el correo vuelva, en vez de perderse para siempre. El costo
            | es que se reintenta cada cinco minutos mientras tanto, acotado por
            | la cantidad de derivaciones sin tomar, que siempre es chica.
            */
            try {
                Mail::to($supervisores->pluck('email')->all())->send(new HandoffSinTomar($handoff));

                $handoff->forceFill(['escalated_at' => now()])->save();
            } catch (Throwable $e) {
                $this->error('     no se pudo avisar: '.$e->getMessage());

                Log::warning('WhatsApp: no se pudo escalar una derivación.', [
                    'handoff_id' => $handoff->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }

    /**
     * A quién avisar.
     *
     * Si hay un supervisor configurado, ese. Si no, todos los administradores
     * activos: es preferible que el aviso llegue a varios a que no llegue a
     * nadie por una configuración que quedó vacía. Un handoff sin tomar es un
     * cliente esperando en silencio, no un detalle de higiene.
     */
    protected function supervisores()
    {
        $configurado = config('inmobiliaria.inmuebles.handoff.supervisor_user_id');

        $query = UserProxy::modelClass()::query()->whereNotNull('email');

        if ($configurado) {
            return $query->where('id', $configurado)->get();
        }

        return $query
            ->where('status', 1)
            ->whereHas('role', fn ($r) => $r->where('name', 'Administrador'))
            ->get();
    }
}
