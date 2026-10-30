<?php

namespace Webkul\Whatsapp\Services\Flows;

use Illuminate\Support\Facades\Cache;

/**
 * Lo elegido en un Flow (paciente, especialidad, doctor, día, hora), guardado
 * del lado del servidor y atado al flow_token.
 *
 * El cliente solo manda la opción de la pantalla actual: a quién se le agenda
 * o con qué doctor sale de acá, no de datos que viajan por el teléfono y que
 * se podrían alterar.
 */
class FlowSession
{
    protected const TTL_MINUTES = 120;

    public static function get(string $token): array
    {
        return (array) Cache::get(self::key($token), []);
    }

    public static function merge(string $token, array $values): array
    {
        $session = array_merge(self::get($token), $values);
        Cache::put(self::key($token), $session, now()->addMinutes(self::TTL_MINUTES));

        return $session;
    }

    public static function forget(string $token): void
    {
        Cache::forget(self::key($token));
    }

    protected static function key(string $token): string
    {
        return 'whatsapp:flow:session:'.hash('sha256', $token);
    }
}
