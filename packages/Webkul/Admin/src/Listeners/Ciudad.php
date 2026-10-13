<?php

namespace Webkul\Admin\Listeners;

use Webkul\Admin\Services\CiudadCliente;
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
 * La última que se guarda manda (decisión del 13/10/2026): se copia al
 * contacto y a todos sus pedidos y cotizaciones (Services\CiudadCliente). Si
 * la que se guarda no tiene ciudad, toma la del contacto. Antes, con dos
 * distintas no se tocaba ninguna y quedaban distintas para siempre.
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
     * Cotización guardada: su ciudad pasa al contacto y a todo lo del cliente
     * (si no tiene, toma la del contacto).
     */
    public function deCotizacion($quote): void
    {
        if ($quote?->id) {
            app(CiudadCliente::class)->sincronizarDesde('quotes', (int) $quote->id);
        }
    }

    /**
     * Pedido guardado: igual que la cotización.
     */
    public function deOportunidad($lead): void
    {
        $id = is_numeric($lead) ? (int) $lead : (int) ($lead?->id ?? 0);

        if ($id) {
            app(CiudadCliente::class)->sincronizarDesde('leads', $id);
        }
    }

    /**
     * Contacto guardado: su ciudad pasa a todos sus pedidos y cotizaciones.
     */
    public function dePersona($person): void
    {
        $id = is_numeric($person) ? (int) $person : (int) ($person?->id ?? 0);

        if ($id) {
            app(CiudadCliente::class)->sincronizarDesde('persons', $id);
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
