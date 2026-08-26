#!/bin/bash
#
# Diagnostico de un contacto de Messenger: por que no tiene nombre ni foto.
#
#   ./check_messenger_contact.sh 239               # por id de person
#   ./check_messenger_contact.sh "Messenger 341809" # por nombre
#   ./check_messenger_contact.sh 341809             # por sufijo del PSID
#
# Volca el Person, sus atributos, las conversaciones ligadas y la conexion de
# la pagina; despues golpea Graph EN VIVO con el PSID real de ese contacto,
# probando tres juegos de campos por separado para aislar cual falla.
#
# Solo lectura: no escribe nada en la base ni en Meta.

set -euo pipefail

TARGET="${1:-239}" php artisan tinker <<'PHP'
$target = trim((string) getenv('TARGET'));

$personModel = Webkul\Contact\Models\Person::class;
$convModel   = Webkul\Whatsapp\Models\Conversation::class;

$p = null;

// 1. Id de person exacto.
if (ctype_digit($target) && strlen($target) <= 9) {
    $p = $personModel::with('attribute_values')->find((int) $target);
}

// 2. Nombre exacto o parcial ("Messenger 341809").
if (! $p) {
    $p = $personModel::with('attribute_values')->where('name', 'like', '%'.$target.'%')->first();
}

// 3. Sufijo del PSID: se busca la conversacion y de ahi el person.
if (! $p) {
    $conv = $convModel::where('gateway', 'messenger')
        ->where('provider_conversation_id', 'like', '%'.preg_replace('/\D+/', '', $target))
        ->first();

    if ($conv?->person_id) {
        $p = $personModel::with('attribute_values')->find($conv->person_id);
    } elseif ($conv) {
        echo "Conversacion #{$conv->id} encontrada, pero NO tiene person asociado.\n";
        echo 'psid: '.var_export($conv->provider_conversation_id, true)."\n";
    }
}

if (! $p) {
    echo "No se encontro nada para: {$target}\n";
    echo "Proba con el id de person, el nombre completo, o los ultimos digitos del PSID.\n";

    return;
}

$id = $p->id;

echo "=== PERSON {$id} ===\n";
echo 'name ........ '.var_export($p->name, true)."\n";
echo 'unique_id ... '.var_export($p->unique_id, true)."\n";
echo 'org_id ...... '.var_export($p->organization_id, true)."\n";
echo "creado ...... {$p->created_at}\n";

if (preg_match('/^(Messenger|WhatsApp) [0-9a-z]{4,8}$/i', (string) $p->name)) {
    echo "            ^^^ es el NOMBRE DE RESPALDO: el perfil no se pudo leer\n";
}

echo "\n=== ATRIBUTOS ===\n";

$typeFields = Webkul\Contact\Models\Person::$attributeTypeFields;

foreach ($p->attribute_values as $v) {
    $a = Webkul\Attribute\Models\Attribute::find($v->attribute_id);

    if (! $a) {
        continue;
    }

    $column = $typeFields[$a->type] ?? 'text_value';

    echo str_pad($a->code, 22).'('.str_pad($a->type, 10).') = '.json_encode($v->$column)."\n";
}

echo "\n=== CONVERSACIONES ===\n";

$convs = $convModel::where('person_id', $id)->get();

if ($convs->isEmpty()) {
    echo "NINGUNA - el person no esta ligado a ningun hilo.\n";

    return;
}

foreach ($convs as $c) {
    echo "\nconversation_id .......... {$c->id}\n";
    echo "gateway .................. {$c->gateway}\n";
    echo 'wa_name .................. '.var_export($c->wa_name, true)."   <-- el NOMBRE real\n";
    echo 'avatar_url ............... '.var_export($c->avatar_url, true)."   <-- la FOTO\n";
    echo 'provider_conversation_id . '.var_export($c->provider_conversation_id, true)."   <-- el PSID\n";
    echo 'provider_account_id ...... '.var_export($c->provider_account_id, true)."   <-- la pagina\n";
    echo 'lead_id .................. '.var_export($c->lead_id, true)."\n";
    echo "status ................... {$c->status}\n";
    echo 'mensajes ................. '.$c->messages()->count()."\n";

    if ($c->gateway !== 'messenger') {
        echo "(no es Messenger, no hay perfil que buscar)\n";

        continue;
    }

    $conn = Webkul\Messenger\Models\SocialConnection::where('channel', 'messenger')->where('account_id', $c->provider_account_id)->first();
    $conn = $conn ?: Webkul\Messenger\Models\SocialConnection::where('channel', 'messenger')->first();

    echo "\n--- CONEXION ---\n";

    if (! $conn) {
        echo "NINGUNA conexion de Messenger cargada.\n";

        continue;
    }

    echo "connection_id ... {$conn->id}\n";
    echo 'account_id ...... '.var_export($conn->account_id, true)."\n";
    echo 'coincide? ....... '.($conn->account_id === (string) $c->provider_account_id ? 'SI' : '*** NO - token de otra pagina ***')."\n";
    echo 'tiene token? .... '.($conn->access_token ? 'si' : '*** NO ***')."\n";
    echo "token guardado .. {$conn->updated_at}   <-- anterior a la aprobacion = token viejo\n";

    if (! $conn->access_token || ! $c->provider_conversation_id) {
        continue;
    }

    echo "\n--- GRAPH EN VIVO ---\n";

    $url = 'https://graph.facebook.com/'.config('messenger.graph_version').'/'.$c->provider_conversation_id;

    foreach (['first_name,last_name,profile_pic', 'first_name,last_name', 'name'] as $fields) {
        try {
            $r = Illuminate\Support\Facades\Http::timeout(10)
                ->withToken($conn->access_token)
                ->acceptJson()
                ->get($url, ['fields' => $fields]);

            echo str_pad($fields, 36).' -> '.$r->status().'  '.$r->body()."\n";
        } catch (Throwable $e) {
            echo str_pad($fields, 36).' -> EXCEPCION  '.$e->getMessage()."\n";
        }
    }
}
PHP
