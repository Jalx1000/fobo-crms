<?php

namespace Webkul\Whatsapp\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Crea o actualiza en Meta un WhatsApp Flow desde su JSON versionado en
 * Resources/flows/{flow}.json, con el endpoint apuntando a este CRM.
 *
 *   php artisan whatsapp:flows:publish                 # crea/actualiza en BORRADOR
 *   php artisan whatsapp:flows:publish --publish       # y lo publica
 *
 * La primera vez imprime el id del Flow: hay que guardarlo en la variable
 * que indica (WHATSAPP_FLOW_PATIENT_ID / _CANCEL_ID / _RESCHEDULE_ID) para que
 * las siguientes corridas lo actualicen en vez de crear otro. Un Flow
 * publicado no se puede editar: para cambiarlo se crea uno nuevo.
 *
 * https://developers.facebook.com/docs/whatsapp/flows/reference/flowsapi
 */
class FlowsPublish extends Command
{
    protected $signature = 'whatsapp:flows:publish
        {flow=paciente_tercero : paciente_tercero | cancelar_cita | reprogramar_cita}
        {--publish : Publicar (sin esto queda en borrador, se puede probar con mode=draft)}';

    protected $description = 'Crea/actualiza en Meta el WhatsApp Flow desde su JSON del repo';

    public function handle(): int
    {
        $flow = (string) $this->argument('flow');

        if (! \Webkul\Whatsapp\Services\Flows\FlowDefinitions::exists($flow)) {
            $this->error('Formulario desconocido. Opciones: '.implode(', ', \Webkul\Whatsapp\Services\Flows\FlowDefinitions::keys()));

            return self::FAILURE;
        }

        $file = __DIR__.'/../../Resources/flows/'.$flow.'.json';

        if (! is_readable($file)) {
            $this->error("No existe {$file}");

            return self::FAILURE;
        }

        $json = (string) file_get_contents($file);

        if (! is_array(json_decode($json, true))) {
            $this->error('El JSON del Flow no es válido.');

            return self::FAILURE;
        }

        $config = \Webkul\Whatsapp\Services\Flows\FlowDefinitions::config($flow);
        $envId = \Webkul\Whatsapp\Services\Flows\FlowDefinitions::envId($flow);
        $endpoint = route('whatsapp.flows.endpoint');
        $this->line("Endpoint: {$endpoint}");

        $id = $config['id'] ?? null;

        if (! $id) {
            $response = $this->graph()->post($this->url(config('whatsapp.cloud.waba_id').'/flows'), [
                'name'         => $config['name'],
                'categories'   => ['APPOINTMENT_BOOKING'],
                'flow_json'    => $json,
                'endpoint_uri' => $endpoint,
            ]);

            if (! $response->successful()) {
                $this->error('Meta no creó el Flow: '.$response->body());

                return self::FAILURE;
            }

            $id = $response->json('id');
            $this->info("Flow creado: {$id}");
            $this->warn("Agregá al .env: {$envId}={$id}");
            $this->reportValidation($response->json('validation_errors', []));
        } else {
            $assets = $this->graph()
                ->attach('file', $json, 'flow.json', ['Content-Type' => 'application/json'])
                ->post($this->url("{$id}/assets"), ['name' => 'flow.json', 'asset_type' => 'FLOW_JSON']);

            if (! $assets->successful()) {
                $this->error('Meta no aceptó el JSON: '.$assets->body());

                return self::FAILURE;
            }

            $this->graph()->post($this->url($id), ['name' => $config['name'], 'endpoint_uri' => $endpoint])->throw();
            $this->info("Flow {$id} actualizado.");
            $this->reportValidation($assets->json('validation_errors', []));
        }

        if (! $this->option('publish')) {
            $this->warn('Quedó en BORRADOR. Revisalo en WhatsApp Manager y corré con --publish.');

            return self::SUCCESS;
        }

        $publish = $this->graph()->post($this->url("{$id}/publish"));

        if (! $publish->successful()) {
            $this->error('Meta no publicó el Flow: '.$publish->body());

            return self::FAILURE;
        }

        $this->info('Flow publicado.');

        return self::SUCCESS;
    }

    protected function reportValidation(array $errors): void
    {
        foreach ($errors as $error) {
            $this->error('Validación: '.($error['message'] ?? json_encode($error)).(isset($error['pointers']) ? ' '.json_encode($error['pointers']) : ''));
        }

        if (! $errors) {
            $this->info('Sin errores de validación.');
        }
    }

    protected function graph()
    {
        return Http::withToken((string) config('whatsapp.cloud.token'))->acceptJson();
    }

    protected function url(string $path): string
    {
        return sprintf('https://graph.facebook.com/%s/%s', config('whatsapp.cloud.api_version', 'v21.0'), $path);
    }
}
