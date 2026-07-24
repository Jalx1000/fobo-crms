<?php

namespace Webkul\Admin\Services;

class SmdStatusMapper
{
    /**
     * Normaliza el status de SMD (puede venir en cualquier case).
     *
     * Vacío se deja vacío: por decisión de negocio va a Consultas (la etapa
     * default), no a Cita confirmada. Es el status más frecuente que manda SMD.
     */
    public function normalize(?string $smdStatus): string
    {
        return strtolower(trim($smdStatus ?? ''));
    }

    /**
     * Mapea el campo status de SMD a un lead_pipeline_stage_id del CRM.
     * "canceled" y "cancelled" se tratan igual (SMD usa "CANCELED").
     */
    public function toLeadStageId(?string $smdStatus): ?int
    {
        return match ($this->normalize($smdStatus)) {
            'confirmed'             => (int) config('smd.stage_map.confirmed'),
            'completed'             => (int) config('smd.stage_map.completed'),
            'canceled', 'cancelled' => (int) config('smd.stage_map.cancelled'),
            // SMD manda "MISSED" para la cita perdida; "no-show" queda por
            // compatibilidad con payloads viejos.
            'no-show', 'missed'     => (int) config('smd.stage_map.no_show'),
            'surgery'               => (int) config('smd.stage_map.surgery'),
            // Status vacío (el más común en SMD) → Consultas.
            ''                      => (int) config('smd.stage_map.default'),
            default                 => null,
        };
    }

    /**
     * Retorna true si el status implica cancelar/cerrar el lead.
     *
     * Solo las canceladas. "missed"/"no-show" NO son cancelaciones: la cita
     * existió y el paciente faltó, así que debe crearse como lead en la etapa
     * "Cita Perdida" (stage_map.no_show), no descartarse. Antes caían en el
     * mismo grupo que las canceladas y, en un sync desde cero, quedaban
     * archivadas sin lead —la cita perdida nunca aparecía en el CRM—.
     */
    public function isCancelled(?string $smdStatus): bool
    {
        return in_array($this->normalize($smdStatus), ['canceled', 'cancelled']);
    }

    /**
     * Retorna el tag a agregar al Lead según el status.
     */
    public function getTag(?string $smdStatus): ?string
    {
        return match ($this->normalize($smdStatus)) {
            'canceled', 'cancelled' => 'Cancelado',
            'no-show', 'missed'     => 'Cita perdida',
            'surgery'               => 'Cirugía',
            default                 => null,
        };
    }
}
