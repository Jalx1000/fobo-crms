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
     * Camino inverso: dado un lead_pipeline_stage_id del CRM, el string de
     * `status` que hay que mandarle a SMD al editar la cita (PATCH). Es lo que
     * cierra el círculo con toLeadStageId() cuando la sync vuelve a leer el
     * evento. Una etapa que no está mapeada (o Consultas) manda status vacío.
     */
    public function smdStatusForStage(?int $stageId): string
    {
        if (! $stageId) {
            return (string) config('smd.smd_status.default', '');
        }

        foreach (['confirmed', 'completed', 'cancelled', 'no_show', 'surgery'] as $key) {
            if ($stageId === (int) config('smd.stage_map.'.$key)) {
                return (string) config('smd.smd_status.'.$key, '');
            }
        }

        return (string) config('smd.smd_status.default', '');
    }

    /**
     * Retorna true si el status implica cancelar/cerrar el lead.
     *
     * Solo las canceladas. "missed"/"no-show" NO son cancelaciones: la cita
     * existió y el paciente faltó, así que debe crearse como lead en la etapa
     * "Cita Perdida" (stage_map.no_show), no descartarse. Antes caían en el
     * mismo grupo que las canceladas y, en un sync desde cero, quedaban
     * archivadas sin lead -la cita perdida nunca aparecía en el CRM-.
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

    /**
     * Color del tag que devuelve getTag(). Vive acá para que el status siga
     * teniendo un solo dueño: quien sincroniza no decide de qué color pinta.
     */
    public function getTagColor(?string $smdStatus): string
    {
        return match ($this->normalize($smdStatus)) {
            'canceled', 'cancelled' => '#ef4444',
            'no-show', 'missed'     => '#f97316',
            'surgery'               => '#8b5cf6',
            default                 => '#6b7280',
        };
    }

    /**
     * Todos los tags que este mapper puede llegar a poner.
     *
     * Los administra la sincronización, no el usuario: cuando una cita cambia
     * de status hay que sacarle el tag anterior, si no una cita que fue
     * "Cita perdida" y después se reprograma queda marcada como perdida para
     * siempre. Sin esta lista no hay forma de distinguir un tag nuestro de uno
     * que puso una persona a mano -esos no se tocan-.
     *
     * @return array<int, string>
     */
    public function managedTags(): array
    {
        return ['Cancelado', 'Cita perdida', 'Cirugía'];
    }
}
