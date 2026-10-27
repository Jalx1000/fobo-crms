<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\Contact\Models\Branch;

/**
 * Sucursales de Tessa. El rollout es Santa Cruz primero, luego Asunción, y en
 * ~6 meses una segunda en Santa Cruz. Cada una con su moneda y zona horaria.
 * Idempotente: updateOrCreate por slug.
 */
class TessaBranchesSeeder extends Seeder
{
    public function run(): void
    {
        $branches = [
            [
                'name'     => 'Tessa Santa Cruz',
                'slug'     => 'santa-cruz',
                'city'     => 'Santa Cruz de la Sierra',
                'country'  => 'Bolivia',
                'currency' => 'BOB',
                'timezone' => 'America/La_Paz',
            ],
            [
                'name'     => 'Tessa Asunción',
                'slug'     => 'asuncion',
                'city'     => 'Asunción',
                'country'  => 'Paraguay',
                'currency' => 'PYG',
                'timezone' => 'America/Asuncion',
            ],
        ];

        foreach ($branches as $data) {
            Branch::updateOrCreate(['slug' => $data['slug']], $data);
        }

        $this->command?->info('Tessa: sucursales (Santa Cruz, Asunción) cargadas.');
    }
}
