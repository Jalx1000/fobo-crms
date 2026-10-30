<?php

namespace Webkul\Admin\Listeners;

use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Attribute\Repositories\AttributeValueRepository;
use Webkul\Contact\Repositories\PersonRepository;
use Webkul\Lead\Repositories\LeadRepository;

class EntitySync
{
    /**
     * Create a new listener instance.
     *
     * @return void
     */
    public function __construct(
        protected AttributeRepository $attributeRepository,
        protected AttributeValueRepository $attributeValueRepository,
        protected LeadRepository $leadRepository,
        protected PersonRepository $personRepository
    ) {}

    /**
     * Sync data from Person to its active Leads.
     *
     * @param  \Webkul\Contact\Models\Person  $person
     * @return void
     */
    public function syncPersonToLeads($person)
    {
        $person->load('attribute_values');

        $ciAttr = $this->attributeRepository->findOneByField('code', 'ci_paciente');
        $seguroAttr = $this->attributeRepository->findOneByField('code', 'seguro_paciente');

        $ciPaciente = $person->getCustomAttributeValue($ciAttr);
        $seguroId = $person->getCustomAttributeValue($seguroAttr);

        // Obtener el NOMBRE del seguro para mapearlo en Leads
        $seguroName = $seguroId ? $this->attributeValueRepository->getAttributeLabel($seguroId, $seguroAttr) : null;

        // 2. Buscar Leads activos vinculados a esta persona
        $leads = $this->leadRepository->findWhere(['person_id' => $person->id, 'status' => 1]);

        foreach ($leads as $lead) {
            $data = ['entity_id' => $lead->id, 'entity_type' => 'leads'];
            $syncNeeded = false;

            if (! is_null($ciPaciente)) {
                $data['ci'] = $ciPaciente;
                $syncNeeded = true;
            }

            if (! is_null($seguroName)) {
                // Buscar el ID equivalente en los atributos de Lead
                $leadInsuranceAttr = $this->attributeRepository->findOneByField('code', 'insurance');
                $targetOption = $leadInsuranceAttr->options->where('name', $seguroName)->first();

                if ($targetOption) {
                    $data['insurance'] = [$targetOption->id];
                    $syncNeeded = true;
                }
            }

            if ($syncNeeded) {
                $this->attributeValueRepository->save($data);
            }
        }
    }

    /**
     * Sync data from Lead to its linked Person.
     *
     * @param  \Webkul\Lead\Models\Lead  $lead
     * @return void
     */
    public function syncLeadToPerson($lead)
    {
        if (! $lead->person_id) {
            return;
        }

        $lead->load('attribute_values');

        $ciLeadAttr = $this->attributeRepository->findOneByField('code', 'ci');
        $insuranceLeadAttr = $this->attributeRepository->findOneByField('code', 'insurance');

        $ci = $lead->getCustomAttributeValue($ciLeadAttr);
        $insuranceValue = $lead->getCustomAttributeValue($insuranceLeadAttr);

        // Obtener el NOMBRE del seguro (el multiselect guarda CSV de IDs)
        $seguroName = null;
        if ($insuranceValue) {
            $ids = explode(',', (string) $insuranceValue);
            $firstId = reset($ids);
            $seguroName = $this->attributeValueRepository->getAttributeLabel($firstId, $insuranceLeadAttr);
        }

        $data = ['entity_id' => $lead->person_id, 'entity_type' => 'persons'];
        $syncNeeded = false;

        if (! is_null($ci)) {
            $data['ci_paciente'] = $ci;
            $syncNeeded = true;
        }

        if (! is_null($seguroName)) {
            // Buscar el ID equivalente en los atributos de Person
            $personInsuranceAttr = $this->attributeRepository->findOneByField('code', 'seguro_paciente');
            $targetOption = $personInsuranceAttr->options->where('name', $seguroName)->first();

            if ($targetOption) {
                /**
                 * Multiselect: se envía como lista. Ojo, esto REEMPLAZA los seguros
                 * del paciente por el que viene del lead, no los acumula.
                 */
                $data['seguro_paciente'] = [$targetOption->id];
                $syncNeeded = true;
            }
        }

        if ($syncNeeded) {
            $this->attributeValueRepository->save($data);
        }
    }
}
