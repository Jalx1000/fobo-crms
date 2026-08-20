#!/bin/bash
#
# Diagnostico de un contacto de Messenger: por que no tiene nombre ni foto.
#
#   ./check_messenger_contact.sh          # usa el person 239
#   ./check_messenger_contact.sh 412      # cualquier otro
#
# Volca el Person, sus atributos, las conversaciones ligadas y la conexion de
# la pagina; despues golpea Graph EN VIVO con el PSID real de ese contacto,
# probando tres juegos de campos por separado para aislar cual falla.
#
# Solo lectura: no escribe nada en la base ni en Meta.

set -euo pipefail

PERSON_ID="${1:-240}" php artisan tinker <<'PHP'
$id = (int) getenv('PERSON_ID');

$p = Webkul\Contact\Models\Person::with('attribute_values')->find($id);

if (! $p) {
    echo "No existe el person {$id}\n";

    return;
}

echo "=== PERSON {$id} ===\n";
echo 'name ........ '.var_export($p->name, true)."\n";
echo 'unique_id ... '.var_export($p->unique_id, true)."\n";
echo 'org_id ...... '.var_export($p->organization_id, true)."\n";
echo "creado ...... {$p->created_at}\n";

// Un nombre tipo "Messenger 4f8a2c" es el respaldo que arma ConversationResolver
// cuando la busqueda de perfil no devolvio nada. Si aparece asi, Graph fallo
// en el momento en que entro este contacto.
if (preg_match('/^(Messenger|WhatsApp) [0-9a-z]{4,8}$/i', (string) $p->name)) {
    echo "            ^^^ es el NOMBRE DE RESPALDO: el perfil no se pudo leer\n";
}

echo "\n=== ATRIBUTOS ===\n";

// Sobre el modelo, no sobre el trait: PHP 8.3 deprecio leer una propiedad
// estatica directamente desde el trait.
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

$convs = Webkul\Whatsapp\Models\Conversation::where('person_id', $id)->get();

if ($convs->isEmpty()) {
    echo "NINGUNA - el person no esta ligado a ningun hilo.\n";
    echo "El nombre y la foto de Messenger viven en la conversacion, asi que\n";
    echo "sin hilo asociado no hay de donde sacarlos.\n";

    return;
}

foreach ($convs as $c) {
    echo "\nconversation_id .......... {$c->id}\n";
    echo "gateway .................. {$c->gateway}\n";
    echo 'wa_name .................. '.var_export($c->wa_name, true)."   <-- el NOMBRE real\n";
    echo 'avatar_url ............... '.var_export($c->avatar_url, true)."   <-- la FOTO\n";
    echo 'wa_phone ................. '.var_export($c->wa_phone, true)."\n";
    echo 'provider_conversation_id . '.var_export($c->provider_conversation_id, true)."   <-- el PSID\n";
    echo 'provider_account_id ...... '.var_export($c->provider_account_id, true)."   <-- la pagina\n";
    echo 'lead_id .................. '.var_export($c->lead_id, true)."\n";
    echo "status ................... {$c->status}\n";
    echo 'mensajes ................. '.$c->messages()->count()."\n";

    if ($c->gateway !== 'messenger') {
        echo "(no es Messenger, no hay perfil que buscar)\n";

        continue;
    }

    // La conexion que deberia haber servido este hilo. El fallback a la primera
    // existe para instalaciones de una sola pagina, donde provider_account_id
    // puede venir vacio en hilos viejos.
    $conn = Webkul\Messenger\Models\SocialConnection::where('channel', 'messenger')
        ->where('account_id', $c->provider_account_id)
        ->first()
        ?? Webkul\Messenger\Models\SocialConnection::where('channel', 'messenger')->first();

    echo "\n--- CONEXION ---\n";

    if (! $conn) {
        echo "NINGUNA conexion de Messenger cargada. Corre: php artisan messenger:connect-page\n";

        continue;
    }

    echo "connection_id ... {$conn->id}\n";
    echo 'account_id ...... '.var_export($conn->account_id, true)."\n";
    echo 'coincide? ....... '.($conn->account_id === (string) $c->provider_account_id ? 'SI' : '*** NO - token de otra pagina ***')."\n";
    echo 'tiene token? .... '.($conn->access_token ? 'si' : '*** NO ***')."\n";

    if (! $conn->access_token || ! $c->provider_conversation_id) {
        continue;
    }

    // Los tres juegos por separado: si first_name,last_name pasa pero agregarle
    // profile_pic da 400, el campo de la foto esta tumbando la request entera y
    // por eso se pierde tambien el nombre.
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
