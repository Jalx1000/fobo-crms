<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Diagnóstico rápido de a qué está conectado el CRM (SMD, Dropbox, BD,
 * número de WhatsApp y Flows) y si es coherente. Solo lectura: se puede correr
 * cualquier día, por ejemplo después de cambiar el .env.
 *
 *   php artisan integraciones:check            # entorno + SMD/Dropbox live
 *   php artisan integraciones:check --solo-entorno
 *   php artisan integraciones:check --agendar   # + una cita que QUEDA creada por doctor
 *
 * Desde el host: DB_HOST=172.17.0.1 php artisan integraciones:check
 */
class IntegracionesCheck extends Command
{
    protected $signature = 'integraciones:check
        {--solo-entorno : Solo tests/Live/EntornoPruebasTest.php (sin la batería SMD/Dropbox)}
        {--agendar : Además agenda una cita de prueba con cada doctor disponible (quedan creadas en SMD y en el CRM)}';

    protected $description = 'Verifica que SMD, Dropbox, la BD y WhatsApp/Flows apunten al entorno esperado (solo lectura)';

    public function handle(): int
    {
        $paths = ['tests/Live/EntornoPruebasTest.php'];

        if (! $this->option('solo-entorno')) {
            $paths[] = 'tests/Live/SmdLiveTest.php';
        }

        if ($this->option('agendar')) {
            $paths[] = 'tests/LiveBooking';
        }

        $this->info('BD: '.config('database.connections.mysql.database')
            .' · SMD: '.config('services.sharemedata.base_url')
            .' · WhatsApp: '.config('whatsapp.cloud.phone_number_id'));

        // SMD_LIVE_WRITE=0: la batería de SMD nunca crea citas desde acá.
        $process = new Process(
            [PHP_BINARY, 'artisan', 'test', ...$paths],
            base_path(),
            [
                'ENTORNO_PRUEBAS_TESTS' => '1',
                'SMD_LIVE_TESTS'        => '1',
                'SMD_LIVE_WRITE'        => '0',
                'AGENDAR_TODOS_TESTS'   => $this->option('agendar') ? '1' : '0',
            ],
            null,
            null,
        );

        $code = $process->run(fn ($type, $buffer) => $this->output->write($buffer));

        $code === 0
            ? $this->info('Integraciones OK.')
            : $this->error('Hay chequeos fallando: el mensaje de cada uno dice qué revisar.');

        return $code === 0 ? self::SUCCESS : self::FAILURE;
    }
}
