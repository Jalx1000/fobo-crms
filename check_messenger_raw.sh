#!/bin/bash
#
# En crudo: imprime la consulta exacta a Graph y la respuesta tal cual.
# Sin interpretacion.
#
#   ./check_messenger_raw.sh                    # primera conexion, ultimo psid
#   ./check_messenger_raw.sh 2                  # conexion puntual
#   ./check_messenger_raw.sh 2 27511332815232251  # + psid puntual
#
# OJO: imprime el token de pagina completo en el curl equivalente.
# No pegues esa linea en un chat, ticket ni captura.

set -euo pipefail

CONN_ID="${1:-}" PSID="${2:-}" php artisan tinker <<'PHP'
$connId = getenv('CONN_ID') ?: null;
$psid   = getenv('PSID') ?: null;

$conn = $connId ? Webkul\Messenger\Models\SocialConnection::find((int) $connId) : Webkul\Messenger\Models\SocialConnection::where('channel', 'messenger')->orderBy('id')->first();

if (! $conn || ! $conn->access_token) {
    echo "Sin conexion o sin token.\n";

    return;
}

if (! $psid) {
    $conv = Webkul\Whatsapp\Models\Conversation::where('gateway', 'messenger')
        ->where('provider_account_id', $conn->account_id)
        ->whereNotNull('provider_conversation_id')
        ->latest('id')->first();

    $psid = $conv?->provider_conversation_id;
}

if (! $psid) {
    echo "No hay psid para probar.\n";

    return;
}

$token   = $conn->access_token;
$version = config('messenger.graph_version');
$url     = "https://graph.facebook.com/{$version}/{$psid}";
$query   = ['fields' => 'first_name,last_name,profile_pic'];

echo "################ CONSULTA ################\n";
echo "conexion ...... #{$conn->id}\n";
echo "page_id ....... {$conn->account_id}\n";
echo "psid .......... {$psid}\n";
echo "url ........... {$url}\n";
echo 'query ......... '.json_encode($query)."\n";
echo 'token (largo) . '.strlen($token)."\n";
echo 'token (inicio)  '.substr($token, 0, 12)."...\n";
echo "auth .......... Authorization: Bearer <token>\n";

$response = null;

try {
    $response = Illuminate\Support\Facades\Http::timeout(20)
        ->withToken($token)
        ->acceptJson()
        ->get($url, $query);
} catch (Throwable $e) {
    echo "\n################ EXCEPCION ################\n";
    echo $e->getMessage()."\n";

    return;
}

echo "\n################ RESPUESTA ################\n";
echo 'status ........ '.$response->status()."\n";

echo "\n--- headers ---\n";

foreach ($response->headers() as $name => $values) {
    echo $name.': '.implode(', ', $values)."\n";
}

echo "\n--- body crudo ---\n";
echo $response->body()."\n";

echo "\n--- body formateado ---\n";
echo json_encode($response->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

echo "\n################ CURL EQUIVALENTE ################\n";
echo "# contiene el token completo: no lo compartas\n";
echo "curl -s -i -G '{$url}' \\\n";
echo "  --data-urlencode 'fields=first_name,last_name,profile_pic' \\\n";
echo "  --data-urlencode 'access_token={$token}'\n";
PHP
