<?php

namespace Webkul\Distribuidor\Services;

use Illuminate\Support\Facades\Log;
use Webkul\Contact\Models\PersonProxy;
use Webkul\Whatsapp\Services\EnvioDePlantilla;

/**
 * Manda el recordatorio de recompra a un cliente.
 *
 * Separado de `Recordatorios` a propósito: aquél lleva el ESTADO —a quién le toca,
 * cuántas veces se intentó, quién se dio de baja— y éste hace el ENVÍO. Mezclarlos
 * obliga a tener WhatsApp levantado para poder probar el ciclo, que es justamente la
 * parte con más reglas.
 */
class RecordatorioDeRecompra
{
    public function __construct(
        protected Recordatorios $recordatorios,
        protected EnvioDePlantilla $envio,
    ) {}

    /**
     * Le manda el recordatorio a una persona. Devuelve si salió.
     */
    public function enviarA(int $personId): bool
    {
        $config = (array) config('distribuidor.recordatorios');

        if (! ($config['enabled'] ?? false)) {
            return false;
        }

        $estado = $this->recordatorios->de($personId);

        // Se vuelve a chequear acá y no sólo al armar la lista: entre que el comando
        // consultó los vencidos y llega a esta fila, alguien pudo darlo de baja.
        if (! $estado || ! $estado->puedeRecibir()) {
            return false;
        }

        $persona = PersonProxy::modelClass()::find($personId);

        if (! $persona) {
            return false;
        }

        $telefono = $this->telefonoDe($persona);

        if (! $telefono) {
            /*
             * Sin teléfono no hay a quién escribirle, y el estado quedaría vencido para
             * siempre haciendo que el comando lo reintente en cada corrida. Se apaga el
             * recordatorio de este cliente: cuando alguien le cargue el número, una
             * entrega lo vuelve a programar.
             */
            Log::info('Recordatorio: cliente sin teléfono, se apaga su recordatorio', [
                'person_id' => $personId,
            ]);

            $this->recordatorios->activar($personId, false);

            return false;
        }

        $mensaje = $this->envio->enviar(
            telefono: $telefono,
            nombre: $persona->name,
            template: (string) $config['template'],
            language: (string) $config['language'],
            variables: [trim((string) $persona->name) ?: 'cliente'],
            imagen: (string) (config('whatsapp.bienvenida.logo') ?? ''),
            motivo: 'el recordatorio de recompra',
        );

        /*
         * 🔴 El intento se registra SÓLO si el mensaje se encoló.
         *
         * Si la plantilla no está aprobada, `enviar()` devuelve null y no salió nada.
         * Contar eso como intento gastaría los tres del ciclo sin que el cliente haya
         * recibido una sola pregunta, y el cliente quedaría en silencio por un problema
         * de configuración nuestro.
         */
        if (! $mensaje) {
            return false;
        }

        $this->recordatorios->registrarEnvio($personId);

        return true;
    }

    protected function telefonoDe($persona): ?string
    {
        $numeros = (array) $persona->contact_numbers;

        return $numeros ? ($numeros[0]['value'] ?? null) : null;
    }
}
