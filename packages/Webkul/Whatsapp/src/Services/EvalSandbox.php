<?php

namespace Webkul\Whatsapp\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Whatsapp\Models\Conversation;

/**
 * Reglas del entorno de evals del agente (config whatsapp.eval).
 *
 * Todo lo de evals pasa por allowed(): con el flag apagado, o con el CRM
 * apuntando a la base de producción, no hay sandbox, ni siembra, ni CI de
 * prueba, aunque alguien los pida.
 */
class EvalSandbox
{
    public static function allowed(): bool
    {
        if (! config('whatsapp.eval.enabled')) {
            return false;
        }

        $database = (string) DB::connection()->getDatabaseName();

        return $database !== '' && $database !== (string) config('whatsapp.eval.production_database');
    }

    /**
     * ¿Los mensajes de esta conversación van al canal sandbox?
     */
    public static function isSandboxed(?Conversation $conversation): bool
    {
        return $conversation && $conversation->gateway === 'sandbox' && self::allowed();
    }

    /**
     * Respuesta fija de /api/v1/insurance/verify para un CI de prueba, o null
     * si el CI no es de prueba (o evals está apagado): entonces se consulta la
     * aseguradora real como siempre.
     */
    public static function insuranceFixture(string $ci, string $seguro): ?array
    {
        $vigente = self::fixtureMatches($ci, $seguro);

        if ($vigente === null) {
            return null;
        }

        if (! $vigente) {
            return ['has_insurance' => false, 'patient_found' => false];
        }

        return [
            'has_insurance'    => true,
            'insurance_name'   => $seguro,
            'policy_number'    => 'EVAL-'.$ci,
            'coverage_type'    => 'dental',
            'valid_until'      => now()->addYear()->format('Y-m-d'),
            'covered_services' => [],
            'patient_name'     => 'Paciente Eval '.$ci,
            'raw'              => ['eval' => true],
        ];
    }

    /**
     * Lo mismo para POST /api/insurance/verify (InsuranceService::verifyDirect),
     * con la forma que devuelven los drivers: VIGENTE o NO_REGISTRADO.
     */
    public static function insuranceStatusFixture(string $ci, string $seguro): ?array
    {
        $vigente = self::fixtureMatches($ci, $seguro);

        if ($vigente === null) {
            return null;
        }

        return $vigente
            ? [
                'status'      => 'VIGENTE',
                'message'     => '¡Todo en orden! El seguro está activo y el paciente puede ser atendido.',
                'badge'       => 'success',
                'success'     => true,
                'data'        => ['CI' => $ci, 'NOMBRE' => 'Paciente Eval '.$ci, 'CONTRATANTE' => $seguro, 'ESTADO' => 'VIGENTE', 'VIGENCIA HASTA' => now()->addYear()->format('d/m/Y'), 'EVAL' => true],
                'seguro_name' => $seguro,
            ]
            : [
                'status'      => 'NO_REGISTRADO',
                'message'     => 'El paciente no figura en la base de datos de esta aseguradora.',
                'badge'       => 'warning',
                'success'     => true,
                'data'        => null,
                'seguro_name' => $seguro,
            ];
    }

    /**
     * null: el CI no es de prueba (o evals está apagado). true/false: si el CI
     * de prueba da vigente con el seguro pedido (sin tildes ni mayúsculas).
     */
    protected static function fixtureMatches(string $ci, string $seguro): ?bool
    {
        $fixtures = (array) config('whatsapp.eval.insurance', []);
        $ci = trim($ci);

        if (! array_key_exists($ci, $fixtures) || ! self::allowed()) {
            return null;
        }

        $fixture = $fixtures[$ci];

        return (bool) $fixture && str_contains(Str::lower(Str::ascii($seguro)), Str::lower(Str::ascii($fixture['match'])));
    }
}
