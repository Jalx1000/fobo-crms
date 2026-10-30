<?php

namespace Webkul\Whatsapp\Console\Commands;

use Illuminate\Console\Command;
use Throwable;
use Webkul\Whatsapp\Services\TemplatePublisher;

/**
 * Da de alta en Meta las plantillas de config('whatsapp-templates') y borra las
 * de config('whatsapp.templates.remove') (hello_world) si están en la cuenta.
 *
 * Idempotente: las que ya existen (en cualquier estado) se informan y no se
 * tocan, así que se puede correr de nuevo tras agregar una al archivo.
 */
class CreateTemplates extends Command
{
    protected $signature = 'whatsapp:templates-create
        {names?* : Solo estas plantillas (por defecto, todas las del archivo)}
        {--dry-run : Validar y mostrar lo que se enviaría, sin llamar a Meta}
        {--yes : No pedir confirmación}';

    protected $description = 'Crea en Meta las plantillas de WhatsApp definidas en Config/templates.php';

    public function handle(TemplatePublisher $publisher): int
    {
        $definitions = config('whatsapp-templates', []);
        $names = $this->argument('names') ?: array_keys($definitions);

        if ($unknown = array_diff($names, array_keys($definitions))) {
            $this->error('No están en Config/templates.php: '.implode(', ', $unknown));

            return self::FAILURE;
        }

        // Todo el archivo tiene que ser válido antes de mandar nada.
        $invalid = false;

        foreach ($names as $name) {
            foreach ($publisher->validate($name, $definitions[$name]) as $error) {
                $this->error("{$name}: {$error}");
                $invalid = true;
            }
        }

        if ($invalid) {
            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            foreach ($names as $name) {
                $this->line("<info>{$name}</info>");
                $this->line(json_encode($publisher->payload($name, $definitions[$name]), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                $this->newLine();
            }

            if ($remove = $this->toRemove()) {
                $this->line('Si están en la cuenta, se eliminan: '.implode(', ', $remove));
            }

            $this->info(count($names).' plantilla(s) válida(s). Nada se envió a Meta.');

            return self::SUCCESS;
        }

        try {
            $existing = $publisher->existing();
        } catch (Throwable $e) {
            $this->error('No se pudo leer la cuenta de Meta: '.$e->getMessage());

            return self::FAILURE;
        }

        $pending = [];
        $rows = [];

        foreach ($names as $name) {
            $current = $existing[$name.'|'.$definitions[$name]['language']] ?? null;

            if ($current) {
                $rows[] = [$name, 'ya existe: '.$current['status'].($current['reason'] ? " ({$current['reason']})" : '')];
            } else {
                $pending[] = $name;
                $rows[] = [$name, 'se crea'];
            }
        }

        // Las que no son nuestras: cualquier idioma cuenta como detectada.
        $existingNames = array_map(fn ($key) => strstr($key, '|', true), array_keys($existing));
        $removals = array_values(array_intersect($this->toRemove(), $existingNames));

        foreach ($removals as $name) {
            $rows[] = [$name, 'se elimina'];
        }

        $this->table(['Plantilla', 'Estado'], $rows);

        if (! $pending && ! $removals) {
            $this->info('Nada para hacer.');

            return self::SUCCESS;
        }

        $account = config('whatsapp.gateways.cloud_api.waba_id');
        $plan = array_filter([
            $pending ? 'crear '.count($pending).' plantilla(s)' : null,
            $removals ? 'eliminar '.implode(', ', $removals) : null,
        ]);

        if (! $this->option('yes') && ! $this->confirm('¿'.ucfirst(implode(' y ', $plan))." en la cuenta {$account}?")) {
            $this->warn('Cancelado.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($removals as $name) {
            try {
                $publisher->delete($name);
                $this->info("{$name}: eliminada.");
            } catch (Throwable $e) {
                // P. ej. hello_world: Meta no deja borrar sus plantillas de
                // muestra. El chat ya no la ofrece, que es lo que importa.
                $this->warn("{$name}: Meta no la deja eliminar ({$e->getMessage()}). Queda oculta en el chat.");
            }
        }

        foreach ($pending as $name) {
            try {
                $status = $publisher->create($name, $definitions[$name]);
                $this->info("{$name}: creada ({$status}).");
            } catch (Throwable $e) {
                $this->error("{$name}: {$e->getMessage()}");
                $failed = true;
            }
        }

        if ($pending) {
            $this->line('Meta las revisa (minutos a horas). Ver aprobadas: php artisan whatsapp:templates --fresh');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Las que hay que borrar, salvo que el archivo las defina como propias.
     *
     * @return array<int, string>
     */
    protected function toRemove(): array
    {
        return array_values(array_diff(
            (array) config('whatsapp.templates.remove', []),
            array_keys(config('whatsapp-templates', [])),
        ));
    }
}
