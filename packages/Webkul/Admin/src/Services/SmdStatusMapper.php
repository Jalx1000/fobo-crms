<?php

namespace Webkul\Admin\Services;

class SmdStatusMapper
{
    /**
     * Normaliza el status de SMD (puede venir en cualquier case).
     * Vacío se trata como "confirmed" según decisión de negocio.
     */
    public function normalize(?string $smdStatus): string
    {
        $normalized = strtolower(trim($smdStatus ?? ''));

        return $normalized === '' ? 'confirmed' : $normalized;
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
            'no-show'               => (int) config('smd.stage_map.no_show'),
            default                 => null,
        };
    }

    /**
     * Retorna true si el status implica cancelar/cerrar el lead.
     */
    public function isCancelled(?string $smdStatus): bool
    {
        return in_array($this->normalize($smdStatus), ['canceled', 'cancelled', 'no-show']);
    }

    /**
     * Retorna el tag a agregar al Lead según el status.
     */
    public function getTag(?string $smdStatus): ?string
    {
        return match ($this->normalize($smdStatus)) {
            'canceled', 'cancelled' => 'Cancelado',
            'no-show'               => 'No-show',
            default                 => null,
        };
    }
}
