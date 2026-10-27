<?php

namespace Webkul\Admin\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Webkul\Activity\Repositories\ActivityRepository;
use Webkul\Admin\Services\Insurance\Contracts\InsuranceDriverInterface;
use Webkul\Admin\Services\Insurance\Drivers\AlianzaDriver;
use Webkul\Admin\Services\Insurance\Drivers\NacionalVidaDriver;
use Webkul\Admin\Services\Insurance\Drivers\OdontokingMembershipDriver;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Attribute\Repositories\AttributeValueRepository;
use Webkul\Contact\Models\PersonInsuranceStatusProxy;
use Webkul\Contact\Repositories\PersonRepository;

class InsuranceService
{
    /**
     * Opciones de `seguro_paciente` que representan la AUSENCIA de seguro.
     * Siempre en minúsculas: la comparación se hace con strtolower().
     */
    private const NON_INSURANCE_OPTIONS = ['no tiene', 'particular'];

    /**
     * Orden de "mejor cobertura" para colapsar varios seguros en un veredicto.
     * INDETERMINADO va último: un fallo de red no debe tapar un NO_REGISTRADO real.
     */
    private const STATUS_PRIORITY = [
        'VIGENTE',
        'EN_MORA',
        'VENCIDO',
        'NO_REGISTRADO',
        'SIN_SEGURO',
        'INDETERMINADO',
    ];

    protected int $cacheTtl;

    public function __construct(
        protected PersonRepository $personRepository,
        protected AttributeRepository $attributeRepository,
        protected AttributeValueRepository $attributeValueRepository,
        protected ActivityRepository $activityRepository,
    ) {
        $this->cacheTtl = (int) config('services.insurance.cache_ttl', 3600);
    }

    /**
     * Verifica el seguro del paciente delegando al driver correspondiente.
     */
    public function verify(int $personId): array
    {
        $person = $this->personRepository->find($personId);

        if (! $person) {
            return $this->indeterminate('Paciente no encontrado.');
        }

        $ciAttribute = $this->attributeRepository->findOneByField('code', 'ci_paciente');
        $seguroAttribute = $this->attributeRepository->findOneByField('code', 'seguro_paciente');

        $ci = $person->getCustomAttributeValue($ciAttribute);

        $insurances = $this->getPersonInsurances($person, $seguroAttribute);

        // Sin seguro registrado
        if (empty($insurances)) {
            $this->persistStatuses($person, []);

            return $this->withoutInsurance();
        }

        /**
         * "No tiene" / "Particular" no son aseguradoras: son la ausencia de una.
         * Si el paciente solo tiene esas, no hay nada que consultar.
         */
        $verifiable = array_values(array_filter(
            $insurances,
            fn ($insurance) => ! in_array(strtolower($insurance['name']), self::NON_INSURANCE_OPTIONS, true)
        ));

        if (empty($verifiable)) {
            $this->persistStatuses($person, []);

            return $this->withoutInsurance();
        }

        // Todos los drivers externos requieren CI
        if (empty($ci)) {
            return $this->indeterminate(
                'Por favor, completa el CI del paciente en su perfil para verificar el seguro.'
            );
        }

        /**
         * Se verifica CADA seguro contra su propio driver. Un paciente puede tener
         * Alianza vigente y una membresía vencida a la vez, y recepción necesita ver
         * ambas para saber qué cobrar.
         */
        $results = [];

        foreach ($verifiable as $insurance) {
            $results[] = $this->verifyOne($person, $insurance, (string) $ci);
        }

        $summary = $this->summarize($results);

        $this->persistStatuses($person, $results);

        Log::info('[InsuranceService] Verificación realizada', [
            'person_id' => $person->id,
            'status'    => $summary['status'],
            'seguros'   => array_map(
                fn ($r) => $r['seguro_name'].':'.$r['status'],
                $results
            ),
        ]);

        $person->insurance_status = $summary['status'] ?? null;
        $person->insurance_checked_at = now();
        $person->save();

        return $summary;
    }

    /**
     * Verifica UN seguro concreto del paciente y le adjunta su identidad,
     * para que el front pueda mostrar un resultado por aseguradora.
     *
     * @param  array{id: int, name: string}  $insurance
     */
    protected function verifyOne($person, array $insurance, string $ci): array
    {
        $driver = $this->resolveDriver($insurance['name']);

        if (! $driver) {
            $result = $this->indeterminate("Seguro '{$insurance['name']}' no tiene integración configurada.");
        } else {
            $result = $this->remember(
                $this->cacheKeyFor($person->id, $insurance['id'], $ci),
                fn () => $driver->verify($person, $ci)
            );
        }

        $result['seguro_id'] = $insurance['id'];
        $result['seguro_name'] = $insurance['name'];

        return $result;
    }

    /**
     * Guarda el estado de CADA aseguradora en `person_insurance_statuses`.
     *
     * Es un upsert por (paciente, aseguradora): la fila refleja siempre la última
     * verificación de esa aseguradora, sin que una pise el resultado de otra.
     * Además limpia las aseguradoras que el paciente ya no tiene, para que no
     * queden estados fantasma de un seguro que le quitaron.
     */
    protected function persistStatuses($person, array $results): void
    {
        $now = now();

        foreach ($results as $result) {
            PersonInsuranceStatusProxy::modelClass()::updateOrCreate(
                [
                    'person_id'           => $person->id,
                    'attribute_option_id' => $result['seguro_id'],
                ],
                [
                    'seguro_name' => $result['seguro_name'],
                    'status'      => $result['status'],
                    'badge'       => $result['badge'] ?? null,
                    'message'     => $result['message'] ?? null,
                    'payload'     => $result['data'] ?? null,
                    'checked_at'  => $now,
                ]
            );
        }

        PersonInsuranceStatusProxy::modelClass()::where('person_id', $person->id)
            ->whereNotIn('attribute_option_id', array_column($results, 'seguro_id'))
            ->delete();
    }

    /**
     * Colapsa los resultados por aseguradora en un veredicto único para el paciente.
     *
     * Gana la mejor cobertura: si CUALQUIER seguro está vigente, el paciente está
     * cubierto, sin importar que otro esté en mora. La lista completa viaja en
     * `results` para que la UI muestre el detalle por aseguradora.
     */
    protected function summarize(array $results): array
    {
        $rank = array_flip(self::STATUS_PRIORITY);

        $best = collect($results)
            ->sortBy(fn ($r) => $rank[$r['status']] ?? count($rank))
            ->first();

        $summary = $best;

        /**
         * Con varias aseguradoras el mensaje del driver ganador se queda corto:
         * lo reemplazamos por un resumen "Aseguradora: ESTADO · Aseguradora: ESTADO".
         */
        if (count($results) > 1) {
            $summary['message'] = collect($results)
                ->map(fn ($r) => $r['seguro_name'].': '.$r['status'])
                ->implode(' · ');
        }

        $summary['results'] = $results;

        return $summary;
    }

    /**
     * Veredicto general de varios resultados por aseguradora (formulario de
     * crear paciente, donde todavía no hay ficha que persistir).
     */
    public function summarizeResults(array $results): array
    {
        return $this->summarize($results);
    }

    /**
     * Clave de caché de UNA aseguradora de un paciente.
     *
     * Es por-seguro (no por el CSV completo) para que agregar un seguro nuevo no
     * invalide las respuestas ya cacheadas de los otros. Centralizado para que
     * `verifyOne`, `forceVerify` y el endpoint `clearCache` no diverjan.
     */
    public function cacheKeyFor(int $personId, int $seguroId, $ci): string
    {
        return "insurance_verify_{$personId}_{$seguroId}_".md5((string) $ci);
    }

    /**
     * Olvida la caché de TODAS las aseguradoras del paciente.
     */
    public function forgetCache($person, $seguroAttribute, $ci): void
    {
        foreach ($this->getPersonInsurances($person, $seguroAttribute) as $insurance) {
            Cache::forget($this->cacheKeyFor($person->id, $insurance['id'], $ci));
        }
    }

    /**
     * Lee el atributo multiselect `seguro_paciente` y lo devuelve como una lista
     * normalizada de seguros del paciente.
     *
     * El valor se guarda en `text_value` como CSV de ids de `attribute_options`
     * (ej. "112,118"), por eso hay que explotarlo antes de resolver etiquetas.
     *
     * @return array<int, array{id: int, name: string}> Lista en el orden en que fueron guardados
     */
    public function getPersonInsurances($person, $seguroAttribute): array
    {
        if (! $seguroAttribute) {
            return [];
        }

        $rawValue = (string) $person->getCustomAttributeValue($seguroAttribute);

        $optionIds = array_filter(array_map('intval', explode(',', $rawValue)));

        if (empty($optionIds)) {
            return [];
        }

        $options = $seguroAttribute->options->whereIn('id', $optionIds)->keyBy('id');

        // Respetamos el orden guardado, no el orden de la relación.
        return array_values(array_filter(array_map(
            fn ($id) => isset($options[$id])
                ? ['id' => $id, 'name' => trim((string) $options[$id]->name)]
                : null,
            $optionIds
        )));
    }

    /**
     * Resuelve el driver correcto según el nombre del seguro.
     * El matching es case-insensitive y por contenido parcial.
     */
    protected function resolveDriver(string $seguroName): ?InsuranceDriverInterface
    {
        $class = $this->driverClassFor($seguroName);

        return $class ? app($class) : null;
    }

    protected function driverClassFor(string $seguroName): ?string
    {
        $name = strtolower($seguroName);

        return match (true) {
            str_contains($name, 'alianza')              => AlianzaDriver::class,
            str_contains($name, 'nacional')             => NacionalVidaDriver::class,
            str_contains($name, 'membresía')            => OdontokingMembershipDriver::class,
            str_contains($name, 'membresia')            => OdontokingMembershipDriver::class,
            str_contains($name, 'odontoking')           => OdontokingMembershipDriver::class,
            str_contains($name, 'membresia odontoking') => OdontokingMembershipDriver::class,
            default                                     => null,
        };
    }

    /**
     * Verifica seguro con CI y nombre de seguro directamente, sin necesitar person_id.
     * Usado en el formulario de creación donde el paciente aún no existe en BD.
     */
    public function verifyWithParams(string $ci, string $seguroName): array
    {
        if (empty($seguroName) || strtolower($seguroName) === 'no tiene' || strtolower($seguroName) === 'particular') {
            return [
                'status'  => 'SIN_SEGURO',
                'message' => 'El paciente no cuenta con un seguro registrado.',
                'badge'   => 'warning',
                'success' => true,
                'data'    => null,
            ];
        }

        $driver = $this->resolveDriver($seguroName);

        if (! $driver) {
            return $this->indeterminate("Seguro '{$seguroName}' no tiene integración configurada.");
        }

        if (empty($ci)) {
            return $this->indeterminate('Por favor, completa el CI del paciente para verificar el seguro.');
        }

        $result = $this->remember('insurance_verify_quick_'.md5($seguroName.'|'.$ci), function () use ($driver, $ci) {
            // Pasamos un stdClass mínimo para compatibilidad con los drivers
            $fakePerson = new \stdClass;
            $fakePerson->id = 0;
            $fakePerson->name = '';

            return $driver->verify($fakePerson, $ci);
        });

        $result['seguro_name'] = $seguroName;

        Log::info('[InsuranceService] Verificación rápida (sin person_id)', [
            'ci_suffix'   => '***'.substr($ci, -4),
            'status'      => $result['status'],
            'seguro_name' => $seguroName,
        ]);

        return $result;
    }

    /**
     * Verifica la cédula del paciente contra las aseguradoras con integración
     * (todas, o solo las de `$optionIds` si declaró cuál tiene) y deja el
     * resultado en su ficha: estado por aseguradora en `person_insurance_statuses`,
     * las aseguradoras donde figura sumadas a `seguro_paciente`, y el estado general.
     *
     * @param  array<int, int>|null  $optionIds  opciones de `seguro_paciente`; null = todas
     * @return array resumen como verify(); `found` = resultados donde figura
     */
    public function verifyAll(int $personId, ?array $optionIds = null): array
    {
        $person = $this->personRepository->find($personId);
        $ciAttribute = $this->attributeRepository->findOneByField('code', 'ci_paciente');
        $ci = $person && $ciAttribute ? (string) $person->getCustomAttributeValue($ciAttribute) : '';

        if (! $person || $ci === '') {
            return $this->indeterminate('Falta la cédula del paciente para verificar el seguro.') + ['found' => [], 'results' => []];
        }

        $summary = $this->verifyCi($ci, $optionIds);

        if ($summary['results']) {
            $this->applyResults($person, $summary);
        }

        return $summary;
    }

    /**
     * Solo consulta, con la cédula: no escribe en ninguna ficha (sirve para un
     * paciente que todavía no existe en el CRM). Cada respuesta definitiva se
     * guarda 30 min por (aseguradora, cédula): el formulario de WhatsApp la
     * pide apenas el paciente elige su seguro, la muestra en el resumen
     * (peekCi) y al agendar se reusa sin volver a consultar. Un INDETERMINADO
     * no se guarda: se reintenta.
     */
    public function verifyCi(string $ci, ?array $optionIds = null): array
    {
        $results = [];

        foreach ($this->insurers($optionIds) as $insurance) {
            $key = $this->ciCacheKey($insurance['id'], $ci);
            $result = Cache::get($key);

            if (! $result) {
                try {
                    $result = app($insurance['driver'])->verify((object) ['id' => 0, 'name' => '', 'leads' => collect()], $ci);
                } catch (\Throwable $e) {
                    $result = $this->indeterminate($e->getMessage());
                }

                if ($result['status'] !== 'INDETERMINADO') {
                    Cache::put($key, $result, now()->addMinutes(30));
                } else {
                    // No se reusa (se reintenta), pero el resumen del formulario
                    // tiene que poder decir "no pudimos verificar" en vez de
                    // quedarse en "verificando…".
                    Cache::put($key.':failed', $result, now()->addMinutes(10));
                }
            }

            $results[] = $result + ['seguro_id' => $insurance['id'], 'seguro_name' => $insurance['name']];
        }

        return $this->ciSummary($results);
    }

    /**
     * El resultado de verifyCi si ya está listo para TODAS las aseguradoras
     * pedidas, sin consultar nada. Null = todavía se está verificando.
     */
    public function peekCi(string $ci, ?array $optionIds = null): ?array
    {
        $results = [];

        foreach ($this->insurers($optionIds) as $insurance) {
            $key = $this->ciCacheKey($insurance['id'], $ci);
            $result = Cache::get($key) ?? Cache::get($key.':failed');

            if (! $result) {
                return null;
            }

            $results[] = $result + ['seguro_id' => $insurance['id'], 'seguro_name' => $insurance['name']];
        }

        return $results ? $this->ciSummary($results) : null;
    }

    /**
     * Las aseguradoras a consultar: una opción por driver ("Membresia
     * Odontoking" y una variante con tilde serían la misma consulta dos veces).
     *
     * @return array<int, array{id:int, name:string, driver:string}>
     */
    protected function insurers(?array $optionIds = null): array
    {
        $seguroAttribute = $this->attributeRepository->findOneByField('code', 'seguro_paciente');
        $insurers = [];

        foreach ($seguroAttribute?->options ?? [] as $option) {
            if ($optionIds !== null && ! in_array((int) $option->id, array_map('intval', $optionIds), true)) {
                continue;
            }

            $driver = $this->driverClassFor((string) $option->name);

            if ($driver && ! isset($insurers[$driver])) {
                $insurers[$driver] = ['id' => (int) $option->id, 'name' => trim((string) $option->name), 'driver' => $driver];
            }
        }

        return array_values($insurers);
    }

    protected function ciCacheKey(int $optionId, string $ci): string
    {
        return 'insurance_ci:'.$optionId.':'.md5(strtoupper(preg_replace('/[^A-Za-z0-9\-]/', '', $ci)));
    }

    protected function ciSummary(array $results): array
    {
        if (! $results) {
            return $this->indeterminate('No hay aseguradoras con integración.') + ['found' => [], 'results' => []];
        }

        $summary = $this->summarize($results);
        $summary['found'] = array_values(array_filter($results, fn ($r) => in_array($r['status'], ['VIGENTE', 'EN_MORA', 'VENCIDO'], true)));

        return $summary;
    }

    /**
     * Escribe en la ficha el resultado de verifyCi.
     */
    protected function applyResults($person, array $summary): void
    {
        // Estado por aseguradora (sin borrar otras: esto no reemplaza lo cargado).
        foreach ($summary['results'] as $result) {
            PersonInsuranceStatusProxy::modelClass()::updateOrCreate(
                ['person_id' => $person->id, 'attribute_option_id' => $result['seguro_id']],
                [
                    'seguro_name' => $result['seguro_name'],
                    'status'      => $result['status'],
                    'badge'       => $result['badge'] ?? null,
                    'message'     => $result['message'] ?? null,
                    'payload'     => $result['data'] ?? null,
                    'checked_at'  => now(),
                ]
            );
        }

        $seguroAttribute = $this->attributeRepository->findOneByField('code', 'seguro_paciente');

        if ($summary['found'] && $seguroAttribute) {
            $this->addInsurances($person, $seguroAttribute, array_column($summary['found'], 'seguro_id'));
        }

        // El estado general en el perfil (`estado_seguro_paciente`). Antes lo
        // escribía cada driver al consultar y el último pisaba a los demás.
        $this->saveEstadoAttribute((int) $person->id, (string) $summary['status']);

        $person->insurance_status = $summary['status'] ?? null;
        $person->insurance_checked_at = now();
        $person->save();

        if (in_array($summary['status'], ['VIGENTE', 'EN_MORA'], true)) {
            $this->createNoteActivity($person->id, $summary);
        }

        Log::info('[InsuranceService] Verificación de seguros aplicada a la ficha', [
            'person_id' => $person->id,
            'status'    => $summary['status'],
            'seguros'   => array_map(fn ($r) => $r['seguro_name'].':'.$r['status'], $summary['results']),
        ]);
    }

    /**
     * Mismo criterio que los drivers: Vigente por nombre; En mora y "Problema"
     * por los ids fijos de las opciones de `estado_seguro_paciente`.
     */
    protected function saveEstadoAttribute(int $personId, string $status): void
    {
        $attribute = \Illuminate\Support\Facades\DB::table('attributes')
            ->where('code', 'estado_seguro_paciente')->where('entity_type', 'persons')->first();

        if (! $attribute) {
            return;
        }

        $optionId = match ($status) {
            'VIGENTE' => \Illuminate\Support\Facades\DB::table('attribute_options')->where('attribute_id', $attribute->id)->where('name', 'Vigente')->value('id'),
            'EN_MORA' => AlianzaDriver::OPTION_ID_EN_MORA,
            default   => AlianzaDriver::OPTION_ID_PROBLEMA,
        };

        if ($optionId) {
            $this->attributeValueRepository->save([
                'entity_id'              => $personId,
                'entity_type'            => 'persons',
                'estado_seguro_paciente' => (int) $optionId,
            ]);
        }
    }

    /**
     * Suma aseguradoras al multiselect `seguro_paciente` (CSV de option ids) y
     * saca "No tiene" / "Particular", que dejan de ser ciertos.
     */
    protected function addInsurances($person, $seguroAttribute, array $optionIds): void
    {
        $current = array_filter(array_map('intval', explode(',', (string) $person->getCustomAttributeValue($seguroAttribute))));
        $nonInsurance = $seguroAttribute->options
            ->filter(fn ($o) => in_array(strtolower(trim((string) $o->name)), self::NON_INSURANCE_OPTIONS, true))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        $ids = array_values(array_unique(array_merge(array_diff($current, $nonInsurance), $optionIds)));

        if ($ids == $current) {
            return;
        }

        \Illuminate\Support\Facades\DB::table('attribute_values')->updateOrInsert(
            ['attribute_id' => $seguroAttribute->id, 'entity_id' => $person->id, 'entity_type' => 'persons'],
            ['text_value' => implode(',', $ids)],
        );
    }

    /**
     * Fuerza una nueva verificación eliminando la caché del paciente.
     */
    public function forceVerify(int $personId): array
    {
        $person = $this->personRepository->find($personId);

        if ($person) {
            $ciAttribute = $this->attributeRepository->findOneByField('code', 'ci_paciente');
            $seguroAttribute = $this->attributeRepository->findOneByField('code', 'seguro_paciente');
            $ci = $person->getCustomAttributeValue($ciAttribute);

            $this->forgetCache($person, $seguroAttribute, $ci);
        }

        return $this->verify($personId);
    }

    /**
     * Crea una nota de actividad con el resultado de la verificación.
     * La nota se vincula a la tabla person_activities (visible en perfil del paciente)
     * y a lead_activities (visible en cada cita/lead relacionado).
     */
    public function createNoteActivity(int $personId, array $result): void
    {
        if (empty($result['data'])) {
            return;
        }

        $data = $result['data'];
        $seguroName = $result['seguro_name'] ?? ($data['CONTRATANTE'] ?? 'N/A');
        $status = $result['status'];

        $comment = "Seguro: {$seguroName}\n |";
        $comment .= " Estado: {$status}\n |";

        if (! empty($data['NOMBRE'])) {
            $comment .= ' Paciente: '.$data['NOMBRE']."\n|";
        }

        if (! empty($data['ESTADO'])) {
            $comment .= ' Detalle: '.$data['ESTADO']."\n|";
        }

        if (isset($data['VIGENCIA HASTA'])) {
            $comment .= ' Vigencia hasta: '.$data['VIGENCIA HASTA']."\n|";
        }

        if (isset($data['COASEGURO ODONTOLOGICO'])) {
            $comment .= ' Coaseguro: '.$data['COASEGURO ODONTOLOGICO']."\n|";
            $comment .= ' Clínica: '.($data['CLINICA ODONTOLOGICA'] ?? 'N/A')."\n|";
            $comment .= ' Cobertura: '.($data['COBERTURA ADICIONAL ODONTOLOGICA'] ?? 'N/A')."\n|";
        }

        if (! empty($data['TELEFONO'])) {
            $comment .= ' Teléfono: '.$data['TELEFONO']."\n|";
        }

        if (isset($data['EDAD'])) {
            $comment .= ' Edad: '.$data['EDAD']."\n|";
        }

        if (! empty($data['CODIGO'])) {
            $comment .= ' Código cliente: '.$data['CODIGO']."\n|";
        }

        $activity = $this->activityRepository->create([
            'type'    => 'note',
            'title'   => "Verificación de Seguro: {$seguroName} - {$status}",
            'comment' => $comment,
            'is_done' => 1,
            'user_id' => auth()->guard('user')->id() ?? 1,
        ]);

        // Vincular al perfil del paciente (person_activities - es la tabla que lee el módulo de personas)
        $activity->persons()->attach($personId);

        // Vincular a cada lead/cita relacionado con el paciente
        $person = $this->personRepository->find($personId);
        if ($person && $person->leads->count() > 0) {
            $activity->leads()->attach($person->leads->pluck('id')->toArray());
        }
    }

    /**
     * Verifica el seguro usando los parámetros provistos directamente (para uso desde la API REST).
     * Equivalente a verify() pero sin leer los atributos del perfil del paciente.
     */
    public function verifyDirect(int $personId, string $ci, string $seguroName): array
    {
        $person = $this->personRepository->find($personId);

        if (! $person) {
            return $this->indeterminate('Paciente no encontrado.');
        }

        if (empty($seguroName) || strtolower($seguroName) === 'no tiene') {
            return [
                'status'  => 'SIN_SEGURO',
                'message' => 'El tipo de seguro indicado no tiene integración configurada.',
                'badge'   => 'warning',
                'success' => true,
                'data'    => null,
            ];
        }

        $driver = $this->resolveDriver($seguroName);

        if (! $driver) {
            return $this->indeterminate("Seguro '{$seguroName}' no tiene integración configurada.");
        }

        $result = $driver->verify($person, $ci);
        $result['seguro_name'] = $seguroName;

        Log::info('[InsuranceService] Verificación directa (API)', [
            'person_id'   => $person->id,
            'ci'          => $ci,
            'seguro_name' => $seguroName,
            'status'      => $result['status'],
        ]);

        if (in_array($result['status'], ['VIGENTE', 'EN_MORA'])) {
            $this->createNoteActivity($personId, $result);
        }

        return $result;
    }

    protected function indeterminate(string $message): array
    {
        return [
            'status'  => 'INDETERMINADO',
            'message' => $message,
            'badge'   => null,
            'success' => false,
            'data'    => null,
        ];
    }

    protected function withoutInsurance(): array
    {
        return [
            'status'  => 'SIN_SEGURO',
            'message' => 'El paciente no cuenta con un seguro registrado en su perfil.',
            'badge'   => 'warning',
            'success' => true,
            'data'    => null,
        ];
    }

    protected function remember(string $key, \Closure $callback): array
    {
        if (! $this->cacheEnabled()) {
            return $callback();
        }

        return Cache::remember($key, $this->cacheTtl, $callback);
    }

    protected function cacheEnabled(): bool
    {
        return filter_var(config('services.insurance.cache_enabled', true), FILTER_VALIDATE_BOOLEAN);
    }
}
