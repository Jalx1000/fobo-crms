<?php

namespace Webkul\Whatsapp\Services\Flows;

/**
 * Los formularios de WhatsApp (Flows) que tiene el CRM: su clave, su config
 * (nombre/id en Meta, botón, modo), el texto con que se envían y quién los
 * atiende en el endpoint. Un solo lugar para el endpoint, la herramienta del
 * agente y el comando de publicación.
 */
class FlowDefinitions
{
    public const FLOWS = [
        'paciente_tercero' => [
            'config'  => 'patient',
            'handler' => PatientFlow::class,
            'env_id'  => 'WHATSAPP_FLOW_PATIENT_ID',
            'text'    => 'Para agendar tu cita, completá este formulario.',
        ],
        'cancelar_cita' => [
            'config'  => 'cancel',
            'handler' => CancelFlow::class,
            'env_id'  => 'WHATSAPP_FLOW_CANCEL_ID',
            'text'    => 'Para cancelar una cita, elegila en este formulario.',
        ],
        'reprogramar_cita' => [
            'config'  => 'reschedule',
            'handler' => RescheduleFlow::class,
            'env_id'  => 'WHATSAPP_FLOW_RESCHEDULE_ID',
            'text'    => 'Para cambiar el día u hora de tu cita, completá este formulario.',
        ],
    ];

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::FLOWS);
    }

    public static function exists(?string $key): bool
    {
        return $key !== null && isset(self::FLOWS[$key]);
    }

    /**
     * @return array{name:?string, id:?string, cta:string, mode:string}
     */
    public static function config(string $key): array
    {
        return (array) config('whatsapp.flows.'.self::FLOWS[$key]['config']);
    }

    public static function handler(string $key): string
    {
        return self::FLOWS[$key]['handler'];
    }

    public static function defaultText(string $key): string
    {
        return self::FLOWS[$key]['text'];
    }

    public static function envId(string $key): string
    {
        return self::FLOWS[$key]['env_id'];
    }
}
