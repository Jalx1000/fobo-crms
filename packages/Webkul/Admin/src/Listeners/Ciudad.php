<?php

namespace Webkul\Admin\Listeners;

use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Attribute\Repositories\AttributeValueRepository;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Lead\Repositories\LeadRepository;
use Webkul\Product\Support\ListaPrecios;

/**
 * Mantiene la ciudad del cliente al día entre el contacto, la oportunidad y la
 * cotización, en los dos sentidos.
 *
 * La ciudad dejó de ser un dato descriptivo: decide la zona del precio y, desde
 * el round robin, QUIÉN atiende. Escribirla tres veces a mano garantizaba que
 * tarde o temprano las tres no coincidieran.
 *
 *   contacto TIENE · documento NO  → el documento la hereda
 *   contacto NO    · documento SÍ  → el contacto la aprende
 *   los dos, iguales               → nada que hacer
 *   los dos, distintos             → NO se toca nada
 *
 * Lo último es deliberado, igual que en [[Nit]]: pisar la del contacto
 * propagaría el error de una cotización a todas las futuras, y pisar la del
 * documento cambiaría algo que ya se emitió. Distintas es visible y corregible;
 * pisadas en silencio, no.
 *
 * La ciudad se copia POR NOMBRE, no por id: cada entidad tiene su propio juego
 * de opciones, así que el id 40 de `persons` y el 51 de `quotes` pueden ser la
 * misma ciudad. El nombre es lo único común.
 */
class Ciudad
{
    public function __construct(
        protected PersonRepository $persons,
        protected LeadRepository $leads,
        protected AttributeRepository $attributes,
        protected AttributeValueRepository $values,
    ) {}

    /**
     * Cotización: se concilia con su contacto y, si la tiene, con su
     * oportunidad — las tres terminan con la misma ciudad.
     */
    public function deCotizacion($quote): void
    {
        if (! $quote?->person_id) {
            return;
        }

        $this->conciliar('quotes', $quote, $quote->person_id);

        foreach ($quote->leads ?? [] as $lead) {
            $this->conciliar('leads', $lead, $lead->person_id);
        }
    }

    /**
     * Oportunidad: se concilia con su contacto.
     */
    public function deOportunidad($lead): void
    {
        $lead = is_numeric($lead) ? $this->leads->find($lead) : $lead;

        if (! $lead?->person_id) {
            return;
        }

        $this->conciliar('leads', $lead, $lead->person_id);
    }

    /**
     * El grueso: documento contra contacto.
     */
    protected function conciliar(string $entityType, $documento, int $personId): void
    {
        $person = $this->persons->find($personId);

        if (! $person) {
            return;
        }

        $delDocumento = $this->nombreDe($entityType, $documento->ciudad ?? null);
        $delContacto = $this->nombreDe('persons', $person->ciudad ?? null);

        if ($delDocumento === null && $delContacto !== null) {
            $this->guardar($entityType, $documento->id, $delContacto);

            return;
        }

        if ($delContacto === null && $delDocumento !== null) {
            $this->guardar('persons', $person->id, $delDocumento);
        }
    }

    /**
     * El nombre de la ciudad a partir de lo guardado, que en un select es el id
     * de la opción. Devuelve null cuando no hay dato.
     */
    public function nombreDe(string $entityType, mixed $valor): ?string
    {
        $valor = trim((string) ($valor ?? ''));

        if ($valor === '') {
            return null;
        }

        $attribute = $this->atributo($entityType);

        if (! $attribute) {
            return null;
        }

        // Puede venir ya como nombre (la API del agente manda texto): si no es
        // un id de opción, se toma tal cual si existe entre las opciones.
        $opcion = $attribute->options->firstWhere('id', (int) $valor)
            ?? $attribute->options->first(fn ($o) => ListaPrecios::comparable((string) $o->name) === ListaPrecios::comparable($valor));

        return $opcion?->name;
    }

    /**
     * Escribe SOLO el atributo `ciudad`, traduciendo el nombre al id de la
     * opción de ESA entidad.
     *
     * Se le pasa la lista de atributos acotada a propósito: `save()` sin ella
     * recorre todos los de la entidad y los que no vengan en `$data` los
     * reescribiría con lo que haya, que acá sería nada.
     */
    protected function guardar(string $entityType, int $entityId, string $ciudad): void
    {
        $attribute = $this->atributo($entityType);

        $opcion = $attribute?->options->first(
            fn ($o) => ListaPrecios::comparable((string) $o->name) === ListaPrecios::comparable($ciudad)
        );

        if (! $opcion) {
            // Una ciudad que no está entre las opciones de esa entidad no se
            // inventa: se deja sin dato antes que con uno que nadie eligió.
            return;
        }

        $this->values->save([
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'ciudad'      => $opcion->id,
        ], collect([$attribute]));
    }

    protected function atributo(string $entityType)
    {
        return $this->attributes
            ->findWhere(['entity_type' => $entityType, 'code' => 'ciudad'])
            ->first()
            ?->load('options');
    }
}
