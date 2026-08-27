<?php

namespace Webkul\Inmobiliaria\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Webkul\Inmobiliaria\Database\Seeders\InmueblesDemo;
use Webkul\Inmobiliaria\Repositories\InmuebleRepository;
use Webkul\User\Repositories\UserRepository;

/**
 * Carga los inmuebles de prueba para poder ejercitar el flujo del agente.
 *
 * Idempotente por `codigo`: si la ficha ya existe la reporta y sigue, nunca la
 * pisa. Un asesor pudo haberla editado desde la UI después de la primera carga
 * y machacarla sería peor que no hacer nada.
 */
class SeedInmuebles extends Command
{
    protected $signature = 'crm:seed-inmuebles
                            {--dry-run : Simula sin escribir nada}
                            {--asesor= : Id del usuario titular; por defecto, el primer usuario con rol Asesor}';

    protected $description = 'Carga inmuebles de prueba de CENTURY 21 en el catálogo';

    public function __construct(
        protected InmuebleRepository $inmuebles,
        protected UserRepository $usuarios,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $simular = (bool) $this->option('dry-run');

        $titular = $this->resolverTitular();

        if (! $titular) {
            $this->error('No hay ningún usuario con rol Asesor y no se pasó --asesor.');
            $this->line('Todo inmueble necesita un titular: es quien recibe el handoff del agente.');

            return self::FAILURE;
        }

        $this->info($simular ? 'SIMULACIÓN — no se escribe nada' : 'Cargando inmuebles');
        $this->line("Titular: {$titular->name} (id {$titular->id})");
        $this->newLine();

        $creados = $existentes = 0;

        foreach (InmueblesDemo::fichas() as $ficha) {
            $codigo = $ficha['codigo'];

            if ($this->inmuebles->findOneWhere(['codigo' => $codigo])) {
                $this->line("  <fg=yellow>ya existía</>  {$codigo} — {$ficha['titulo']}");
                $existentes++;

                continue;
            }

            if (! $simular) {
                DB::transaction(fn () => $this->inmuebles->create($ficha + [
                    'user_id'      => $titular->id,
                    'publicado_at' => now(),
                ]));
            }

            $this->line("  <fg=green>creado</>      {$codigo} — {$ficha['titulo']}");
            $creados++;
        }

        $this->newLine();
        $this->info("Creados: {$creados} · Ya existían: {$existentes}");

        if ($creados === 0 && ! $simular) {
            $this->comment('Sin cambios — el catálogo ya estaba cargado.');
        }

        if (! $simular && $creados > 0) {
            $this->newLine();
            $this->comment('Las fichas no tienen fotos: la galería se carga desde la UI del CRM.');
        }

        return self::SUCCESS;
    }

    /**
     * El titular por defecto es un usuario con rol Asesor, no cualquiera.
     *
     * Un administrador puede administrar el CRM sin ser quien muestra casas; el
     * handoff va siempre al asesor que captó el inmueble.
     */
    protected function resolverTitular()
    {
        if ($id = $this->option('asesor')) {
            return $this->usuarios->find((int) $id);
        }

        return $this->usuarios->getModel()->newQuery()
            ->whereHas('role', fn ($q) => $q->where('name', 'Asesor'))
            ->orderBy('id')
            ->first();
    }
}
