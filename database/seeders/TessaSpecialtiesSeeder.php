<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Webkul\Doctor\Models\Specialty;
use Webkul\Product\Models\Product;

/**
 * Especialidades = categorías / líneas de tratamiento de Tessa, y asignación
 * de cada servicio (product) a su línea.
 *
 * Idempotente: updateOrCreate por slug / por sku. Requiere que los servicios
 * ya existan (ver TessaMockCatalogSeeder).
 */
class TessaSpecialtiesSeeder extends Seeder
{
    public function run(): void
    {
        $lines = [
            'depilacion-laser' => [
                'name'        => 'Depilación láser',
                'description' => 'Reducción progresiva del vello mediante láser, en todo el cuerpo y rostro.',
                'skus'        => ['DEPILA-AXILAS', 'DEPILA-BIKINI', 'DEPILA-BOZO', 'DEPILA-ROSTRO', 'DEPILA-MEDIAS-PIERNAS', 'DEPILA-PIERNAS', 'DEPILA-BRAZOS', 'DEPILA-ESPALDA', 'DEPILA-AXILAS-BIKINI'],
            ],
            'faciales-rejuvenecimiento' => [
                'name'        => 'Faciales de rejuvenecimiento',
                'description' => 'Tratamientos faciales que estimulan colágeno y elastina (HIFU, Dermapen).',
                'skus'        => ['HIFU-ROSTRO', 'HIFU-ROSTRO-CUELLO', 'DERMAPEN-FACIAL'],
            ],
            'reduccion-grasa-localizada' => [
                'name'        => 'Reducción de grasa localizada',
                'description' => 'Tratamientos corporales para grasa localizada y modelado (criolipólisis, ultracavitación, vacuum RF, maderoterapia, masoterapia).',
                'skus'        => ['CRIO-ZONA', 'CRIO-PAQUETE-15', 'ULTRACAV', 'VACUUM-RF', 'MADERO', 'MASO'],
            ],
        ];

        foreach ($lines as $slug => $data) {
            $specialty = Specialty::updateOrCreate(
                ['slug' => $slug],
                ['name' => $data['name'], 'description' => $data['description']]
            );

            Product::whereIn('sku', $data['skus'])->update(['specialty_id' => $specialty->id]);
        }

        $this->command?->info('Tessa: 3 líneas (especialidades) y categorización de servicios aplicadas.');
    }
}
