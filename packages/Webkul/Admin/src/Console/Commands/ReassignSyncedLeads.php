<?php

namespace Webkul\Admin\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReassignSyncedLeads extends Command
{
    protected $signature = 'leads:reassign-synced-to-unassigned
        {--apply : Ejecuta la reasignación (sin este flag corre en dry-run)}
        {--rollback : Revierte usando lead_reassignment_log y restaura el dueño previo}';

    protected $description = 'Reasigna a la cuenta "Sin asignar" los leads de la sync (smd_synced_events) que hoy pertenecen a un admin';

    /** Motivo registrado en lead_reassignment_log para poder revertir. */
    private const REASON = 'reassign-synced-to-unassigned';

    public function handle(): int
    {
        if ($this->option('rollback')) {
            return $this->rollback();
        }

        $apply = (bool) $this->option('apply');

        $unassigned = DB::table('users')
            ->where('email', config('dashboard.unassigned_user.email'))
            ->first();

        if (! $unassigned) {
            $this->error('No existe la cuenta "Sin asignar" ('.config('dashboard.unassigned_user.email').'). Corre las migraciones primero.');

            return self::FAILURE;
        }

        // Ids de roles admin (cubre "Administrator"/"Administrador").
        $adminRoleIds = DB::table('roles')->where('name', 'like', 'Admin%')->pluck('id')->toArray();

        // Leads que vienen de la sync (están en smd_synced_events) y hoy pertenecen
        // a un admin. Los leads creados manualmente por un admin NO entran aquí.
        $query = DB::table('leads')
            ->join('smd_synced_events', 'smd_synced_events.lead_id', '=', 'leads.id')
            ->join('users', 'leads.user_id', '=', 'users.id')
            ->whereIn('users.role_id', $adminRoleIds)
            ->where('leads.user_id', '!=', $unassigned->id)
            ->select('leads.id as lead_id', 'leads.user_id as old_user_id')
            ->distinct();

        $targets = $query->get();
        $count = $targets->count();

        if ($count === 0) {
            $this->info('No hay leads de la sync con dueño admin pendientes de reasignar.');

            return self::SUCCESS;
        }

        $this->line('Leads a reasignar: '.$count.' -> "Sin asignar" (id '.$unassigned->id.')');
        $this->line('Ejemplos (lead_id:old_user_id): '.$targets->take(10)->map(fn ($t) => $t->lead_id.':'.$t->old_user_id)->implode(', ').($count > 10 ? ' ...' : ''));

        if (! $apply) {
            $this->warn('DRY-RUN: no se modificó nada. Corre con --apply para ejecutar.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($targets, $unassigned) {
            $now = now();

            foreach ($targets as $t) {
                DB::table('lead_reassignment_log')->insert([
                    'lead_id'     => $t->lead_id,
                    'old_user_id' => $t->old_user_id,
                    'new_user_id' => $unassigned->id,
                    'reason'      => self::REASON,
                    'created_at'  => $now,
                ]);

                DB::table('leads')->where('id', $t->lead_id)->update([
                    'user_id'    => $unassigned->id,
                    'updated_at' => $now,
                ]);
            }
        });

        Log::info('[ReassignSyncedLeads] Reasignados '.$count.' leads de la sync al usuario "Sin asignar" (id '.$unassigned->id.').');
        $this->info('Listo: '.$count.' leads reasignados a "Sin asignar". Auditoría en lead_reassignment_log.');

        return self::SUCCESS;
    }

    /**
     * Restaura el dueño previo de cada lead desde lead_reassignment_log y elimina
     * las filas de log revertidas.
     */
    private function rollback(): int
    {
        $logs = DB::table('lead_reassignment_log')->where('reason', self::REASON)->get();

        if ($logs->isEmpty()) {
            $this->info('No hay reasignaciones registradas para revertir.');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');

        $this->line('Reasignaciones a revertir: '.$logs->count());

        if (! $apply) {
            $this->warn('DRY-RUN: no se modificó nada. Corre con --rollback --apply para revertir.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($logs) {
            foreach ($logs as $log) {
                DB::table('leads')->where('id', $log->lead_id)->update([
                    'user_id'    => $log->old_user_id,
                    'updated_at' => now(),
                ]);

                DB::table('lead_reassignment_log')->where('id', $log->id)->delete();
            }
        });

        Log::info('[ReassignSyncedLeads] Rollback de '.$logs->count().' reasignaciones.');
        $this->info('Listo: '.$logs->count().' leads restaurados a su dueño previo.');

        return self::SUCCESS;
    }
}
