<?php

namespace Webkul\Distribuidor\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Webkul\Distribuidor\Models\RecordatorioProxy;

/**
 * El recordatorio de recompra: preguntarle al cliente si quiere agendar el próximo
 * pedido, cuando está por quedarse sin agua.
 *
 * El ciclo completo:
 *
 *   entrega → (cadencia del cliente) → recordatorio
 *       │
 *       ├─ "sí"   → el agente toma el pedido; el ciclo se reinicia con la entrega
 *       ├─ "no"   → +2 días, hasta el tope de intentos; después se corta
 *       ├─ "baja" → no se le manda más, hasta que lo vuelva a pedir
 *       └─ silencio → se trata como un "no": +2 días y cuenta para el tope
 *
 * 🔴 El silencio cuenta como "no" a propósito. Si no, un cliente que nunca contesta
 * se queda con un recordatorio pendiente para siempre y el comando se lo manda de
 * nuevo en cada corrida: en vez de insistir tres veces, insiste cada hora.
 */
class Recordatorios
{
    /**
     * Si la tabla ya existe.
     *
     * Mismo motivo que en Bidones: el código y las migraciones se despliegan en pasos
     * distintos, y entre uno y otro confirmar una entrega no puede tirar 500 — eso es
     * el trabajo del distribuidor, el recordatorio es un extra.
     */
    protected function tablaLista(): bool
    {
        static $existe = null;

        return $existe ??= Schema::hasTable('distribuidor_recordatorios');
    }

    /**
     * ¿Están encendidos los recordatorios?
     *
     * Dos llaves y las dos tienen que estar: el .env y el interruptor del admin.
     *
     * No es redundancia. El .env es la llave del DESPLIEGUE —mientras la plantilla no
     * esté aprobada en Meta, esto no puede estar encendido en ningún lado— y el del
     * admin es la del NEGOCIO, para que quien maneja la operación pueda apagarlo sin
     * pedirle a nadie que toque el servidor.
     *
     * El valor del admin manda cuando existe la fila; si nadie la guardó todavía,
     * decide el .env.
     */
    public function habilitado(): bool
    {
        if (! (bool) (config('distribuidor.recordatorios.enabled') ?? false)) {
            return false;
        }

        $delAdmin = core()->getConfigData('distribuidor.recordatorios.enabled');

        return $delAdmin === null ? true : (bool) $delAdmin;
    }

    /**
     * Cada cuántos días compra este cliente.
     *
     * Sale del PROMEDIO entre sus entregas, no de un número fijo para todos: una
     * familia de cuatro vacía un botellón en una semana y una pareja tarda tres. Un
     * plazo único le escribe temprano a uno y tarde al otro, y las dos cosas hacen
     * que el recordatorio moleste en vez de servir.
     *
     * Con menos de dos entregas no hay ningún intervalo que promediar, así que se usa
     * el valor por defecto. No se inventa nada con una sola compra.
     */
    public function cadenciaDe(int $personId): int
    {
        $defecto = (int) (config('distribuidor.recordatorios.cadencia_defecto') ?? 7);

        if (! Schema::hasTable('distribuidor_entregas')) {
            return $defecto;
        }

        $fechas = DB::table('distribuidor_entregas')
            ->join('leads', 'leads.id', '=', 'distribuidor_entregas.lead_id')
            ->where('leads.person_id', $personId)
            ->orderBy('distribuidor_entregas.entregado_at')
            /*
             * Las últimas 6 entregas y no todas: lo que interesa es el ritmo de AHORA.
             * Un cliente que durante un año pidió cada 30 días y hace dos meses pasó a
             * cada 7 tiene un promedio histórico que no describe a nadie.
             */
            ->orderByDesc('distribuidor_entregas.id')
            ->limit(6)
            ->pluck('distribuidor_entregas.entregado_at')
            ->map(fn ($f) => Carbon::parse($f))
            ->sort()
            ->values();

        if ($fechas->count() < 2) {
            return $defecto;
        }

        $intervalos = [];

        for ($i = 1; $i < $fechas->count(); $i++) {
            $dias = $fechas[$i - 1]->diffInDays($fechas[$i]);

            /*
             * Los intervalos de 0 días se descartan: son dos entregas del mismo día —un
             * pedido partido en dos, o una carga corregida— y no un ciclo de compra. Con
             * ellos adentro el promedio se desploma y el recordatorio sale al otro día.
             */
            if ($dias > 0) {
                $intervalos[] = $dias;
            }
        }

        if (! $intervalos) {
            return $defecto;
        }

        $promedio = (int) round(array_sum($intervalos) / count($intervalos));

        // Entre los topes de config: un promedio de 1 día haría spam, y uno de 300
        // —de un cliente que compró dos veces con un año de diferencia— no sirve.
        return max(
            (int) (config('distribuidor.recordatorios.cadencia_minima') ?? 3),
            min($promedio, (int) (config('distribuidor.recordatorios.cadencia_maxima') ?? 60)),
        );
    }

    /**
     * Programa el próximo recordatorio después de una entrega.
     *
     * Se llama al confirmar la entrega. Reinicia los intentos: la insistencia se mide
     * por ciclo, y una compra cierra el ciclo anterior.
     */
    public function programarTrasEntrega(int $personId, ?Carbon $entregadoAt = null): ?object
    {
        if (! $this->tablaLista() || ! $this->habilitado()) {
            return null;
        }

        $fila = $this->filaDe($personId);

        // La baja del cliente gana sobre cualquier programación automática. Que haya
        // vuelto a comprar no significa que haya vuelto a querer los mensajes.
        if (! $fila->puedeRecibir()) {
            return $fila;
        }

        $cadencia = $this->cadenciaDe($personId);

        $fila->update([
            'cadencia_dias' => $cadencia,
            'proximo_at'    => ($entregadoAt ?? now())->copy()->addDays($cadencia),
            'intentos'      => 0,
            // La respuesta vieja deja de describir el estado actual: el cliente compró.
            'ultima_respuesta'    => null,
            'ultima_respuesta_at' => null,
        ]);

        return $fila;
    }

    /**
     * Los clientes a los que hoy les toca un recordatorio.
     *
     * @return Collection<int, object>
     */
    public function vencidos(int $limite = 100): Collection
    {
        if (! $this->tablaLista() || ! $this->habilitado()) {
            return collect();
        }

        return RecordatorioProxy::modelClass()::query()
            ->whereNotNull('proximo_at')
            ->where('proximo_at', '<=', now())
            ->where('activo', true)
            ->whereNull('baja_at')
            ->where('intentos', '<', $this->tope())
            // Los más atrasados primero: si se acumuló trabajo, lo más viejo es lo que
            // más urge, y limitar la corrida no puede dejar a alguien esperando siempre.
            ->orderBy('proximo_at')
            ->limit($limite)
            ->get();
    }

    /**
     * Deja registrado que se mandó, y cuándo volver a intentar.
     *
     * El próximo intento se programa EN EL MISMO ACTO que el envío, antes de saber qué
     * va a contestar. Es lo que cubre el silencio: sin esto, un cliente que no responde
     * queda con `proximo_at` vencido y el comando se lo manda otra vez en cada corrida.
     */
    public function registrarEnvio(int $personId): void
    {
        if (! $this->tablaLista()) {
            return;
        }

        $fila = $this->filaDe($personId);
        $intentos = $fila->intentos + 1;

        $fila->update([
            'intentos'        => $intentos,
            'ultimo_envio_at' => now(),
            // Agotado el tope se corta: `proximo_at` en null es "no hay nada
            // programado", y vuelve a haberlo cuando el cliente compre.
            'proximo_at' => $intentos >= $this->tope()
                ? null
                : now()->addDays($this->reintentoDias()),
        ]);
    }

    /**
     * Lo que contestó el cliente, tal como lo interpretó el agente.
     *
     * @param  string  $respuesta  si | no | baja
     */
    public function registrarRespuesta(int $personId, string $respuesta): ?object
    {
        if (! $this->tablaLista()) {
            return null;
        }

        $fila = $this->filaDe($personId);

        $comun = [
            'ultima_respuesta'    => $respuesta,
            'ultima_respuesta_at' => now(),
        ];

        $fila->update(match ($respuesta) {
            /*
             * "Sí": no se programa nada. El ciclo lo reinicia la ENTREGA del pedido que
             * el agente está por tomar, no esta respuesta — entre que dice que sí y que
             * le llega el agua pueden pasar días, y programar desde acá le mandaría el
             * próximo recordatorio antes de recibir éste.
             */
            'si' => array_merge($comun, ['proximo_at' => null, 'intentos' => 0]),

            // "No": el próximo intento ya quedó programado en registrarEnvio(). Acá sólo
            // se deja constancia de que contestó, que es distinto de haber callado.
            'no' => $comun,

            // "No me mandes más": la baja la pidió el cliente y pesa más que cualquier
            // interruptor del equipo. Ver Recordatorio::puedeRecibir().
            'baja' => array_merge($comun, ['baja_at' => now(), 'proximo_at' => null]),

            default => $comun,
        });

        return $fila->refresh();
    }

    /**
     * El cliente volvió a pedir los recordatorios después de haberse dado de baja.
     *
     * Explícito y en su propio método: levantar una baja no puede ser un efecto
     * secundario de otra cosa. Que alguien compre otra vez no revierte un "no me
     * mandes más".
     */
    public function reactivar(int $personId): ?object
    {
        if (! $this->tablaLista()) {
            return null;
        }

        $fila = $this->filaDe($personId);

        $fila->update([
            'baja_at'    => null,
            'activo'     => true,
            'intentos'   => 0,
            'proximo_at' => now()->addDays($this->cadenciaDe($personId)),
        ]);

        return $fila;
    }

    /** El interruptor por cliente, el que maneja el equipo desde el CRM. */
    public function activar(int $personId, bool $activo): ?object
    {
        if (! $this->tablaLista()) {
            return null;
        }

        $fila = $this->filaDe($personId);

        $fila->update(['activo' => $activo] + ($activo ? [] : ['proximo_at' => null]));

        return $fila;
    }

    public function de(int $personId): ?object
    {
        return $this->tablaLista() ? $this->filaDe($personId) : null;
    }

    /**
     * La fila del cliente, creándola si es la primera vez.
     *
     * 🔴 `activo` e `intentos` se pasan EXPLÍCITOS aunque la tabla ya los tenga por
     * defecto. `firstOrCreate` no relee la fila después de insertarla, así que los
     * defaults de la BASE no existen en el modelo que devuelve: `activo` quedaba en
     * null, que castea a false, y `puedeRecibir()` daba false para todo cliente nuevo.
     *
     * El síntoma era mudo: programar un recordatorio "funcionaba" —no tiraba ningún
     * error— y la fila quedaba sin fecha. Nadie recibía nada y no había nada que mirar.
     */
    protected function filaDe(int $personId): object
    {
        return RecordatorioProxy::modelClass()::firstOrCreate(
            ['person_id' => $personId],
            ['activo' => true, 'intentos' => 0],
        );
    }

    protected function tope(): int
    {
        return max(1, (int) (config('distribuidor.recordatorios.intentos_maximos') ?? 3));
    }

    protected function reintentoDias(): int
    {
        return max(1, (int) (config('distribuidor.recordatorios.reintento_dias') ?? 2));
    }
}
