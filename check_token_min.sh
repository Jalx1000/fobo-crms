#!/bin/bash
# Version minima, sin set -e y con salida cruda de todo.
php artisan tinker <<'PHP'
$conn = Webkul\Messenger\Models\SocialConnection::where('channel','messenger')->first();
var_dump($conn ? $conn->id : 'SIN CONEXION');
var_dump($conn ? $conn->account_id : null);
var_dump($conn ? (string) $conn->updated_at : null);
var_dump($conn && $conn->access_token ? 'token len '.strlen($conn->access_token) : 'SIN TOKEN');
var_dump(config('messenger.graph_version'));

$conv = Webkul\Whatsapp\Models\Conversation::where('gateway','messenger')
    ->whereNotNull('provider_conversation_id')->latest('id')->first();
var_dump($conv ? $conv->id : 'SIN CONVERSACION');
var_dump($conv ? $conv->provider_conversation_id : null);
var_dump($conv ? $conv->wa_name : null);

if ($conn && $conn->access_token && $conv) {
    $r = Illuminate\Support\Facades\Http::timeout(15)->get(
        'https://graph.facebook.com/'.config('messenger.graph_version').'/'.$conv->provider_conversation_id,
        ['fields' => 'first_name,last_name,profile_pic', 'access_token' => $conn->access_token]
    );
    var_dump($r->status());
    var_dump($r->body());
}
PHP
