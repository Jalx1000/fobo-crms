#!/bin/bash
#
# Confirma si los PSID viejos murieron al cambiar de app.
#
# Pedile a alguien que mande un mensaje NUEVO a la pagina, y despues corre esto.
# Compara las conversaciones mas recientes con las anteriores al cambio de app:
# si las nuevas resuelven el perfil y las viejas no, el diagnostico es ese.
#
#   ./check_messenger_nuevo.sh        # ultimas 5
#   ./check_messenger_nuevo.sh 10     # ultimas 10
#
# Solo lectura.

set -euo pipefail

LIMITE="${1:-5}" php artisan tinker <<'PHP'
$limite = (int) (getenv('LIMITE') ?: 5);

$convs = Webkul\Whatsapp\Models\Conversation::where('gateway', 'messenger')
    ->whereNotNull('provider_conversation_id')
    ->latest('id')
    ->limit($limite)
    ->get();

if ($convs->isEmpty()) {
    echo "No hay conversaciones de Messenger.\n";

    return;
}

$conns = Webkul\Messenger\Models\SocialConnection::where('channel', 'messenger')->get();

echo "Conexiones y cuando se guardo su token:\n";

foreach ($conns as $cn) {
    echo "  #{$cn->id}  page={$cn->account_id}  token guardado: {$cn->updated_at}\n";
}

echo "\n".str_repeat('=', 78)."\n";

$ok = $fail = 0;
$testValido = false;   // hubo alguna conversacion creada DESPUES del token de SU pagina?

foreach ($convs as $c) {
    $conn = $conns->firstWhere('account_id', (string) $c->provider_account_id);
    $conn = $conn ?: $conns->first();

    echo "\nconv #{$c->id}   creada: {$c->created_at}\n";
    echo '  psid ....... '.$c->provider_conversation_id."\n";
    echo '  page ....... '.var_export($c->provider_account_id, true)."\n";
    echo '  wa_name .... '.var_export($c->wa_name, true)."\n";
    echo '  person_id .. '.var_export($c->person_id, true)."\n";

    if (! $conn || ! $conn->access_token) {
        echo "  -> sin token para esa pagina\n";

        continue;
    }

    // Solo es prueba valida si el PSID y el token pueden ser de la misma app:
    // conversacion creada DESPUES de guardar el token de ESA pagina.
    $posterior = $c->created_at && $c->created_at->gt($conn->updated_at);

    echo '  token pag .. guardado '.$conn->updated_at."\n";
    echo '  prueba ..... '.($posterior ? 'VALIDA (psid y token de la misma app)' : 'NO CONCLUYENTE (psid anterior al token actual)')."\n";

    if ($posterior) {
        $testValido = true;
    }

    try {
        $r = Illuminate\Support\Facades\Http::timeout(15)
            ->withToken($conn->access_token)
            ->acceptJson()
            ->get('https://graph.facebook.com/'.config('messenger.graph_version').'/'.$c->provider_conversation_id,
                  ['fields' => 'first_name,last_name,profile_pic']);

        echo '  GRAPH ...... '.$r->status().'  '.mb_substr($r->body(), 0, 200)."\n";

        if ($r->status() === 200) {
            $ok++;
        } else {
            $fail++;
        }
    } catch (Throwable $e) {
        echo '  GRAPH ...... EXCEPCION '.$e->getMessage()."\n";
        $fail++;
    }
}

echo "\n".str_repeat('=', 78)."\n";
echo "Resuelven: {$ok}   Fallan: {$fail}\n\n";

if ($ok > 0) {
    echo "El token resuelve al menos un perfil. Corre el backfill:\n";
    echo "  php artisan messenger:backfill-profiles --dry-run\n";
} elseif (! $testValido) {
    echo "INCONCLUYENTE. Ninguna de estas conversaciones es posterior al token de\n";
    echo "su propia pagina, asi que sus PSID son de la app anterior y no prueban\n";
    echo "nada sobre el token actual.\n\n";
    echo "Para tener una prueba valida:\n";
    echo "  1. Reconecta TODAS las paginas con token de la app actual:\n";

    foreach ($conns as $cn) {
        echo "       php artisan messenger:connect-page {$cn->account_id} <TOKEN_NUEVO>\n";
    }

    echo "  2. Pedi un mensaje NUEVO a la pagina.\n";
    echo "  3. Volve a correr este script.\n";
} else {
    echo "Fallan incluso conversaciones POSTERIORES al token de su pagina.\n";
    echo "No es el cambio de app: corresponde abrir caso con Meta.\n";
}
PHP
