<?php

namespace Webkul\Admin\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;
use Webkul\Admin\Mail\AvisoContactoNuevo;
use Webkul\Admin\Mail\BienvenidaContacto;
use Webkul\Admin\Services\DestinatariosContactoNuevo;
use Webkul\Contact\Models\PersonProxy;

/**
 * Los correos de un contacto recién registrado: la bienvenida al cliente y el
 * aviso al equipo.
 *
 * Lee el contacto al correr (no lo serializa): se va a la cola `whatsapp`, la
 * única con worker. Un contacto avisa UNA vez aunque el alta se dispare dos
 * veces; si el SMTP falla se libera la marca y el reintento vuelve a probar.
 */
class AvisarContactoNuevo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public int $personId) {}

    public function handle(DestinatariosContactoNuevo $destinatarios): void
    {
        $person = PersonProxy::modelClass()::find($this->personId);

        if (! $person) {
            return;
        }

        $marca = "contacto-nuevo:avisado:{$person->id}";

        if (! Cache::add($marca, true, now()->addDays(30))) {
            return;
        }

        try {
            $para = $destinatarios->para($person);

            foreach ($para['cliente'] as $email) {
                Mail::to($email)->send(new BienvenidaContacto($person));
            }

            foreach ($para['equipo'] as $asesor) {
                Mail::to($asesor->email)->send(new AvisoContactoNuevo($person, $asesor, $para['ciudad']));
            }

            // La copia fija ve el mismo aviso y a quiénes se les designó.
            $designados = $para['equipo']->pluck('name')->all();

            foreach ($this->copias($para['equipo']) as $email) {
                Mail::to($email)->send(new AvisoContactoNuevo($person, null, $para['ciudad'], $designados));
            }

            Log::info('Contacto nuevo: correos enviados', [
                'copias'    => count($this->copias($para['equipo'])),
                'person_id' => $person->id,
                'cliente'   => count($para['cliente']),
                'equipo'    => $para['equipo']->pluck('id')->all(),
            ]);
        } catch (Throwable $e) {
            Cache::forget($marca);

            throw $e;
        }
    }

    /**
     * Los correos de MAIL_CONTACTO_NUEVO_COPIA, sin los que ya recibieron el
     * aviso como asesores (nadie recibe dos).
     *
     * @return array<int, string>
     */
    protected function copias($equipo): array
    {
        $yaAvisados = $equipo->pluck('email')->map(fn ($email) => strtolower($email))->all();

        return collect(explode(',', (string) config('mail.contacto_nuevo_copia')))
            ->map(fn ($email) => strtolower(trim($email)))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->reject(fn ($email) => in_array($email, $yaAvisados, true))
            ->unique()
            ->values()
            ->all();
    }
}
