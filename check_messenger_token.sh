#!/bin/bash
#
# Identifica QUE es cada token guardado: si es de pagina o de usuario, que app
# lo emitio, y con que permisos. Es lo que distingue las causas que quedan
# cuando el error 100/33 persiste con la app en Live y el permiso aprobado.
#
#   ./check_messenger_token.sh                 # usa los app id del .env
#   ./check_messenger_token.sh 1738xxxxxxxxx   # probar un app id puntual
#
# Solo lectura.

set -euo pipefail

APP_ID="${1:-}" php artisan tinker <<'PHP'
$version = config('messenger.graph_version');
$base    = "https://graph.facebook.com/{$version}";

$get = function (string $url, array $query) {
    try {
        $r = Illuminate\Support\Facades\Http::timeout(15)->acceptJson()->get($url, $query);

        return [$r->status(), $r->json() ?: $r->body()];
    } catch (Throwable $e) {
        return ['EXC', $e->getMessage()];
    }
};

// Candidatos de app id que hay en el entorno. debug_token exige que el app
// token corresponda a la MISMA app que emitio el token de pagina.
$appSecret = config('messenger.app_secret');
$appIds    = array_filter([
    getenv('APP_ID') ?: null,
    env('MESSENGER_APP_ID'),
    env('META_APP_ID'),
    env('FACEBOOK_APP_ID'),
    env('INSTAGRAM_APP_ID'),
    env('BUSINESS_PLATFORM_API_APP_ID'),
]);

echo 'graph_version .. '.$version."\n";
echo 'app_secret ..... '.($appSecret ? 'presente' : '*** AUSENTE ***')."\n";
echo 'app_ids probar . '.(implode(', ', $appIds) ?: 'ninguno en el .env')."\n";

foreach (Webkul\Messenger\Models\SocialConnection::where('channel', 'messenger')->orderBy('id')->get() as $conn) {
    echo "\n".str_repeat('=', 70)."\n";
    echo "CONEXION #{$conn->id}   page_id={$conn->account_id}   guardado={$conn->updated_at}\n";
    echo str_repeat('=', 70)."\n";

    if (! $conn->access_token) {
        echo "*** SIN TOKEN ***\n";

        continue;
    }

    // 1. QUE es este token. Un token de pagina devuelve la pagina; uno de
    //    usuario devuelve a la persona. Distingue el error mas comun al
    //    generarlo desde el Graph API Explorer.
    [$status, $body] = $get("{$base}/me", ['fields' => 'id,name', 'access_token' => $conn->access_token]);

    echo "\n[1] GET /me -> {$status}\n    ".json_encode($body)."\n";

    if ($status === 200 && isset($body['id'])) {
        $esPagina = $body['id'] === (string) $conn->account_id;
        echo '    tipo: '.($esPagina ? 'TOKEN DE PAGINA (correcto)' : '*** TOKEN DE USUARIO o de OTRA pagina - por eso falla ***')."\n";
    }

    // 2. Quien emitio el token y con que permisos.
    echo "\n[2] debug_token\n";

    if (! $appSecret || ! $appIds) {
        echo "    Omitido: falta app_secret o app_id en el .env\n";
    } else {
        foreach ($appIds as $appId) {
            [$status, $body] = $get("{$base}/debug_token", [
                'input_token'  => $conn->access_token,
                'access_token' => $appId.'|'.$appSecret,
            ]);

            $data = $body['data'] ?? null;

            if (! $data) {
                echo "    app {$appId}: {$status}  ".json_encode($body)."\n";

                continue;
            }

            echo "    app {$appId}: {$status}\n";
            echo '      emitido por app ... '.($data['app_id'] ?? '?')."\n";
            echo '      tipo .............. '.($data['type'] ?? '?')."\n";
            echo '      valido ............ '.(($data['is_valid'] ?? false) ? 'si' : '*** NO ***')."\n";
            echo '      expira ............ '.(($data['expires_at'] ?? 0) === 0 ? 'nunca' : date('Y-m-d H:i', $data['expires_at']))."\n";

            $scopes = $data['scopes'] ?? [];

            echo '      scopes ............ '.(implode(', ', $scopes) ?: '(ninguno)')."\n";
            echo '      pages_messaging ... '.(in_array('pages_messaging', $scopes, true) ? 'SI' : '*** NO - el token no lo lleva ***')."\n";

            break; // el primer app id que responde es el correcto
        }
    }

    // 3. Que apps estan suscritas a la pagina. Si la app que emitio el token
    //    no figura, la pagina nunca autorizo a esa app.
    echo "\n[3] GET /{page}/subscribed_apps\n";
    [$status, $body] = $get("{$base}/{$conn->account_id}/subscribed_apps", ['access_token' => $conn->access_token]);
    echo "    {$status}  ".json_encode($body)."\n";
}
PHP
