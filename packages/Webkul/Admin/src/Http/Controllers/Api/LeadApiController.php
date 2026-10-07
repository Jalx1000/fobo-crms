<?php

namespace Webkul\Admin\Http\Controllers\Api;

use Illuminate\Http\Resources\Json\JsonResource;
use Webkul\Admin\Http\Requests\LeadForm;
use Webkul\Admin\Http\Resources\Api\LeadApiResource;
use Webkul\RestApi\Http\Controllers\V1\Lead\LeadController;

/**
 * La API REST de leads del paquete krayin/rest-api, devolviendo el lead con
 * sus atributos personalizados (LeadApiResource).
 *
 * Subclase y no un parche en vendor/: el paquete se actualiza con Composer.
 * Las rutas se registran después de las del paquete (AdminServiceProvider),
 * así que GET/PUT /api/v1/leads/{id} y POST /api/v1/leads caen acá. El resto
 * de la lógica es la del paquete, sin cambios.
 */
class LeadApiController extends LeadController
{
    public function show(int $id): JsonResource
    {
        return new LeadApiResource($this->leadRepository->findOrFail($id));
    }

    public function store(LeadForm $request): JsonResource
    {
        return $this->conAtributos(parent::store($request));
    }

    public function update(LeadForm $request, int $id): JsonResource
    {
        return $this->conAtributos(parent::update($request, $id));
    }

    /**
     * Cambia el `data` de la respuesta del paquete por el lead con atributos;
     * el resto (el `message`) queda igual.
     */
    protected function conAtributos(JsonResource $respuesta): JsonResource
    {
        $cuerpo = $respuesta->resource;

        $lead = $cuerpo['data']->resource ?? null;

        if ($lead) {
            $cuerpo['data'] = new LeadApiResource($lead->fresh());
        }

        return new JsonResource($cuerpo);
    }
}
