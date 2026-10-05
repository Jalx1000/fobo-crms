<?php

namespace Webkul\Admin\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Webkul\Doctor\Repositories\SpecialtyRepository;

class ShareMeDataService
{
    /*
     * Desde config y no hardcodeado: la key estaba en el código —y por lo tanto en
     * cada commit y en cada clon— y además en dos scripts servidos desde public/.
     *
     * config() y no env(): bajo `config:cache`, env() devuelve null fuera de los
     * archivos de config, y la integración fallaría sin decir por qué.
     */
    protected $apiKey;

    protected $baseUrl;

    protected $lastResponse = null;

    public function __construct(
        protected SpecialtyRepository $specialtyRepository
    ) {
        $this->apiKey = (string) config('sharemedata.api_key');
        $this->baseUrl = (string) config('sharemedata.base_url');
    }

    /**
     * Obtener especialidades del sistema externo
     */
    public function getSpecialties()
    {
        try {
            $this->lastResponse = null;
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'apikey' => $this->apiKey,
            ])
                ->withoutVerifying()
                ->get("{$this->baseUrl}/specialties");

            $this->lastResponse = [
                'status' => $response->status(),
                'body'   => $response->json() ?: $response->body(),
            ];

            if ($response->successful()) {
                return $response->json();
            }

            return [];
        } catch (\Exception $e) {
            Log::error('ShareMeData: Excepción obteniendo especialidades: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Sincronizar especialidades con el sistema externo
     */
    public function syncSpecialties()
    {
        $specialties = $this->getSpecialties();
        $syncedCount = 0;
        $alreadyExistCount = 0;

        foreach ($specialties as $name) {
            $slug = \Illuminate\Support\Str::slug($name);
            $exists = $this->specialtyRepository->findOneWhere(['slug' => $slug]);

            if ($exists) {
                $alreadyExistCount++;
            } else {
                $this->specialtyRepository->fetchOrCreateByName($name);
                $syncedCount++;
            }
        }

        return [
            'synced'  => $syncedCount,
            'existed' => $alreadyExistCount,
            'total'   => count($specialties),
        ];
    }

    /**
     * Verificar disponibilidad de un doctor por especialidad y sucursal
     */
    public function checkAvailability($doctorExternalId, $specialty, $subsidiary, $from, $to)
    {
        try {
            $this->lastResponse = null;
            $fromFormatted = Carbon::parse($from)->format('Y-m-d\TH:i:s-04:00');
            $toFormatted = Carbon::parse($to)->format('Y-m-d\TH:i:s-04:00');

            $queryParams = [
                'where'      => "subsidiary={$subsidiary}&specialty={$specialty}",
                'from'       => $fromFormatted,
                'to'         => $toFormatted,
                'groupByDay' => 'true',
                'timeZone'   => 'America/La_Paz',
            ];

            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'apikey' => $this->apiKey,
            ])
                ->withoutVerifying()
                ->get("{$this->baseUrl}/schedule/availability", $queryParams);

            $this->lastResponse = [
                'status' => $response->status(),
                'body'   => $response->json() ?: $response->body(),
            ];

            if ($response->successful()) {
                $data = $response->json();
                foreach ($data as $item) {
                    if (isset($item['physician']['_id']) && $item['physician']['_id'] == $doctorExternalId) {
                        return $item['slots'] ?? [];
                    }
                }
            }

            return [];
        } catch (\Exception $e) {
            Log::error('ShareMeData: Error verificando disponibilidad: '.$e->getMessage());

            return [];
        }
    }

    /**
     * Obtener la última respuesta en crudo
     */
    public function getLastResponse()
    {
        return $this->lastResponse;
    }

    /**
     * Verificar si un rango de tiempo está disponible (cubierto por slots de 15 min)
     */
    public function isRangeAvailable($doctorExternalId, $specialty, $subsidiary, $start, $end)
    {
        $slots = $this->checkAvailability($doctorExternalId, $specialty, $subsidiary, $start, $end);

        if (empty($slots)) {
            return false;
        }

        $requestedStart = Carbon::parse($start);
        $requestedEnd = Carbon::parse($end);

        $requiredIntervals = [];
        $current = $requestedStart->copy();
        while ($current->lessThan($requestedEnd)) {
            $requiredIntervals[] = [
                'start' => $current->copy(),
                'end'   => $current->addMinutes(15)->copy(),
            ];
        }

        $foundCount = 0;
        foreach ($requiredIntervals as $required) {
            foreach ($slots as $daySlots) {
                foreach ($daySlots as $date => $intervals) {
                    foreach ($intervals as $interval) {
                        $apiStart = Carbon::parse($interval['start']);
                        $apiEnd = Carbon::parse($interval['end']);

                        if ($apiStart->equalTo($required['start']) && $apiEnd->equalTo($required['end'])) {
                            $foundCount++;

                            continue 3;
                        }
                    }
                }
            }
        }

        return $foundCount === count($requiredIntervals);
    }

    /**
     * Crear evento en el sistema externo
     */
    public function createEvent($data)
    {
        try {
            $this->lastResponse = null;
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
                'apiKey'       => $this->apiKey,
            ])
                ->withoutVerifying()
                ->timeout(30)
                ->post("{$this->baseUrl}/schedule/createEvent", $data);

            $this->lastResponse = [
                'status' => $response->status(),
                'body'   => $response->json() ?: $response->body(),
            ];

            if ($response->successful()) {
                return [
                    'success' => true,
                    'data'    => $response->json(),
                ];
            }

            return [
                'success' => false,
                'message' => $response->json('message') ?: $response->body(),
                'status'  => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error('ShareMeData: Excepción creando evento: '.$e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
