<?php

namespace Webkul\Admin\Listeners;

use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Attribute\Repositories\AttributeValueRepository;
use Webkul\Contact\Repositories\PersonRepository;

/**
 * Mantiene el NIT al día entre la cotización y la persona, en los dos sentidos.
 *
 * El NIT es EL dato para facturar en Bolivia, y el flujo de venta lo pide dos
 * veces (PASO 4 y el cierre). Antes no tenía campo y terminaba como texto libre
 * dentro de la descripción de la cotización, donde nadie lo puede filtrar,
 * exportar ni usar para emitir nada.
 *
 * Ahora vive en los dos lados, y este listener los concilia:
 *
 *   persona TIENE  · cotización NO  → la cotización lo hereda
 *   persona NO     · cotización SÍ  → la persona lo aprende
 *   los dos, iguales                → nada que hacer
 *   los dos, distintos              → NO se toca nada (ver abajo)
 *
 * Corre en `quote.create.after` y `quote.update.after`, que disparan tanto el
 * CRM como la API REST — así el `register_cotizacion` del agente pasa por el
 * mismo camino que un asesor cargando a mano.
 */
class Nit
{
    public function __construct(
        protected PersonRepository $persons,
        protected AttributeRepository $attributes,
        protected AttributeValueRepository $values,
    ) {}

    public function sincronizar($quote): void
    {
        if (! $quote?->person_id) {
            return;
        }

        $person = $this->persons->find($quote->person_id);

        if (! $person) {
            return;
        }

        $deLaCotizacion = $this->limpiar($quote->nit ?? null);
        $deLaPersona = $this->limpiar($person->nit ?? null);

        if ($deLaCotizacion === $deLaPersona) {
            return;
        }

        if ($deLaCotizacion === null) {
            // La cotización hereda. Es el caso normal: el asesor abre una
            // cotización de un cliente que ya cotizó antes y no vuelve a
            // escribir el NIT.
            $this->guardar('quotes', $quote->id, $deLaPersona);

            return;
        }

        if ($deLaPersona === null) {
            // La persona aprende. Es el PASO 4 del flujo: el cliente da su NIT
            // por WhatsApp, el agente crea la cotización, y el dato queda además
            // en la ficha del contacto para la próxima vez.
            $this->guardar('persons', $person->id, $deLaCotizacion);

            return;
        }

        /*
        | Los dos tienen NIT y son distintos: NO se toca ninguno.
        |
        | Pisar el de la persona propagaría un error de tipeo de una cotización
        | a todas las futuras. Pisar el de la cotización cambiaría un documento
        | que ya se emitió. Que queden distintos es visible y corregible a mano;
        | que se pisen en silencio, no.
        */
    }

    /**
     * Un NIT en blanco y uno ausente son lo mismo: no hay dato.
     *
     * Sin esto, una cotización con el campo vacío (cadena '') se leería como
     * «tiene un NIT distinto al de la persona» y caería en el caso de conflicto,
     * que justamente no hace nada — y la herencia nunca ocurriría.
     */
    protected function limpiar(mixed $valor): ?string
    {
        $valor = trim((string) ($valor ?? ''));

        return $valor === '' ? null : $valor;
    }

    /**
     * Escribe SOLO el atributo `nit`.
     *
     * Se le pasa la lista de atributos acotada a propósito: `save()` sin ella
     * recorre todos los de la entidad y los que no vengan en `$data` los
     * reescribiría con lo que haya, que acá sería nada.
     */
    protected function guardar(string $entityType, int $entityId, ?string $nit): void
    {
        $attribute = $this->attributes
            ->findWhere(['entity_type' => $entityType, 'code' => 'nit'])
            ->first();

        if (! $attribute) {
            return;
        }

        $this->values->save([
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'nit'         => $nit,
        ], collect([$attribute]));
    }
}
