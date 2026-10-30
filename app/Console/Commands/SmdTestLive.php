<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Batería de pruebas de la integración SMD / Dropbox contra PRODUCCIÓN.
 *
 * Pensada para correrse a mano los domingos, cuando la clínica no atiende:
 *   1. la suite offline (SmdEndpointsAuditTest, con fakes), como precondición;
 *   2. las pruebas live (tests/Live) contra SMD y Dropbox reales.
 *
 * Los tests corren con el .env de producción (misma BD, con rollback por
 * DatabaseTransactions). Hay que correrlo DENTRO del contenedor: desde el host
 * el DB_HOST del .env no resuelve.
 */
class SmdTestLive extends Command
{
    protected $signature = 'smd:test-live
        {--write : Incluye escritura: crea una cita de prueba en SMD y la cancela}
        {--skip-offline : No correr antes la suite con fakes}
        {--force : Correr aunque hoy no sea domingo}';

    protected $description = 'Prueba los endpoints integrados con ShareMeData y Dropbox contra producción (domingos)';

    public function handle(): int
    {
        $now = now(config('app.timezone', 'America/La_Paz'));

        if (! $now->isSunday() && ! $this->option('force')) {
            if (! $this->input->isInteractive() || ! $this->confirm("Hoy es {$now->locale('es')->dayName}: estas pruebas están pensadas para el domingo. ¿Correrlas igual?")) {
                $this->warn('Cancelado. Usar --force para correrlas fuera del domingo.');

                return self::FAILURE;
            }
        }

        if ($this->option('write') && ! $this->confirmWrite()) {
            return self::FAILURE;
        }

        if (! $this->option('skip-offline')) {
            $this->info('1/2 Suite offline (fakes, sin red)');

            if ($this->runTests(['tests/Feature/SmdEndpointsAuditTest.php'], []) !== 0) {
                $this->error('La suite offline falló: se corta antes de tocar producción.');

                return self::FAILURE;
            }
        }

        $this->info('2/2 Pruebas live contra SMD y Dropbox de producción'.($this->option('write') ? ' (CON escritura)' : ' (solo lectura)'));

        $code = $this->runTests(['tests/Live'], $this->liveEnv());

        Log::log($code === 0 ? 'info' : 'warning', '[smd:test-live] Resultado', [
            'ok'    => $code === 0,
            'write' => (bool) $this->option('write'),
        ]);

        $code === 0
            ? $this->info('Integración SMD/Dropbox OK.')
            : $this->error('Hay pruebas live fallando: revisar arriba antes del lunes.');

        return $code === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function confirmWrite(): bool
    {
        if (! env('SMD_LIVE_DOCTOR_ID') || ! env('SMD_LIVE_PATIENT_PHONE')) {
            $this->error('Para --write hay que definir en .env SMD_LIVE_DOCTOR_ID (doctor de prueba) y SMD_LIVE_PATIENT_PHONE (paciente de prueba).');

            return false;
        }

        $this->warn('Esto crea en SMD de PRODUCCIÓN ('.config('services.sharemedata.base_url').') una cita "PRUEBA CRM - NO ATENDER"');
        $this->warn('para el doctor '.env('SMD_LIVE_DOCTOR_ID').' y el paciente de prueba con teléfono '.env('SMD_LIVE_PATIENT_PHONE').' (debe existir en SMD).');
        $this->warn('La cita se cancela al final aunque falle alguna verificación.');
        $this->warn('El sync puede traerla al CRM antes de la cancelación: queda como cita cancelada de prueba.');

        return $this->confirm('¿Continuar?');
    }

    /**
     * Solo las banderas: la suite ya corre con el .env de producción, así que
     * las credenciales reales de SMD y Dropbox salen de ahí.
     *
     * @return array<string, string>
     */
    private function liveEnv(): array
    {
        return [
            'SMD_LIVE_TESTS' => '1',
            'SMD_LIVE_WRITE' => $this->option('write') ? '1' : '0',
        ];
    }

    private function runTests(array $paths, array $env): int
    {
        $process = new Process([PHP_BINARY, 'artisan', 'test', ...$paths], base_path(), $env, null, null);

        return $process->run(fn ($type, $buffer) => $this->output->write($buffer));
    }
}
