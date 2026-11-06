<?php

namespace Webkul\Distribuidor\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Webkul\Distribuidor\Models\EntregaProxy;

/**
 * Marca un pedido como entregado con todo lo que eso implica: la etapa, la fila de
 * `distribuidor_entregas` (cuándo, quién y la foto), los bidones y el recordatorio
 * de recompra.
 *
 * Es un servicio y no parte del controlador del Punto porque también se entrega desde
 * el chat de WhatsApp, y quien atiende el chat puede no tener acceso al Punto. Dos
 * copias de este flujo terminarían registrando entregas distintas según la pantalla.
 *
 * Quién puede entregar y qué pedido lo decide quien llama: acá ya está decidido.
 */
class RegistroDeEntrega
{
    public function __construct(
        protected PuntoPedido $pedidos,
        protected Bidones $bidones,
        protected Recordatorios $recordatorios,
    ) {}

    public function registrar(
        int $leadId,
        ?int $personId,
        ?UploadedFile $foto = null,
        int $bidonesEntregados = 0,
        int $bidonesRetirados = 0,
    ): void {
        $this->pedidos->avanzar($leadId, config('distribuidor.etapas.entregado'));

        /*
         * La entrega se registra acá y no se deduce de la etapa: `updated_at` del
         * lead se mueve con cualquier edición posterior, así que no sirve para
         * contestar "¿cuándo se lo entregaron?" — que es exactamente lo que se
         * pregunta cuando un cliente dice que no le llegó.
         */
        EntregaProxy::modelClass()::create([
            'lead_id'      => $leadId,
            'user_id'      => auth()->guard('user')->id(),
            'entregado_at' => now(),
            // Nombre aleatorio: la ruta de la foto viaja al navegador, y un
            // nombre predecible dejaría ver la entrega de otro pedido.
            // Null-safe: la foto es opcional y sin el ?-> esto reventaba.
            'foto_path'    => $foto?->store('distribuidor/entregas', 'public'),
        ]);

        if (! $personId) {
            return;
        }

        /*
         * Los bidones se cuentan contra el CLIENTE y no contra el pedido: el
         * saldo que importa es "cuántos tiene en su casa", y eso atraviesa
         * todos sus pedidos. El lead queda igual en el movimiento, para poder
         * reconstruir en qué visita pasó.
         */
        $this->bidones->registrar($personId, $leadId, $bidonesEntregados, $bidonesRetirados);

        /*
         * El recordatorio de recompra se programa acá, al entregar: es el
         * único momento en que sabemos que el cliente YA TIENE el agua, y la
         * cuenta regresiva hasta que se le acabe empieza ahí. Programarlo al
         * tomar el pedido lo adelantaría todos los días que tarde el reparto.
         *
         * En try/catch y sin dejar que nada se propague: entregar es el
         * trabajo, el recordatorio es un extra, y una entrega que no se puede
         * confirmar porque falló algo de WhatsApp deja al distribuidor
         * trabado en la puerta del cliente. Mismo criterio que la bienvenida.
         */
        try {
            $this->recordatorios->programarTrasEntrega($personId);
        } catch (\Throwable $e) {
            Log::warning('No se pudo programar el recordatorio de recompra', [
                'person_id' => $personId,
                'lead_id'   => $leadId,
                'error'     => $e->getMessage(),
            ]);
        }
    }
}
