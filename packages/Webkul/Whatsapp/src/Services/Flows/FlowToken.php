<?php

namespace Webkul\Whatsapp\Services\Flows;

/**
 * `flow_token` firmado: `{conversation_id}.{flow}.{nonce}.{hmac}`.
 *
 * El token viaja en cada pedido del Flow y es lo único que dice a qué
 * conversación (y por ende a qué responsable) y a qué formulario pertenece.
 * Sin firma, cualquiera que conociera un id podría operar sobre otra
 * conversación. Los tokens viejos de 3 partes (`{conv}.{nonce}.{hmac}`) se
 * siguen aceptando como "paciente_tercero".
 */
class FlowToken
{
    public const DEFAULT_FLOW = 'paciente_tercero';

    public static function issue(int $conversationId, string $flow = self::DEFAULT_FLOW): string
    {
        $base = $conversationId.'.'.$flow.'.'.bin2hex(random_bytes(6));

        return $base.'.'.self::sign($base);
    }

    /**
     * El id de conversación si el token es auténtico; null si no.
     */
    public static function conversationId(?string $token): ?int
    {
        return self::parse($token)['conversation_id'] ?? null;
    }

    /**
     * Qué formulario es ("paciente_tercero", "cancelar_cita"…); null si el
     * token no es auténtico.
     */
    public static function flow(?string $token): ?string
    {
        return self::parse($token)['flow'] ?? null;
    }

    /**
     * @return array{conversation_id:int, flow:string}|null
     */
    protected static function parse(?string $token): ?array
    {
        $parts = explode('.', (string) $token);

        if (count($parts) === 3) {
            [$conv, $nonce, $sig] = $parts;
            $flow = self::DEFAULT_FLOW;
            $base = $conv.'.'.$nonce;
        } elseif (count($parts) === 4) {
            [$conv, $flow, $nonce, $sig] = $parts;
            $base = $conv.'.'.$flow.'.'.$nonce;
        } else {
            return null;
        }

        if (! ctype_digit($conv) || ! preg_match('/^[a-z_]+$/', $flow) || ! hash_equals(self::sign($base), $sig)) {
            return null;
        }

        return ['conversation_id' => (int) $conv, 'flow' => $flow];
    }

    protected static function sign(string $base): string
    {
        return substr(hash_hmac('sha256', 'whatsapp-flow|'.$base, (string) config('app.key')), 0, 24);
    }
}
