<?php

/*
| Helpers de WhatsApp Flows: simulan al cliente de Meta (cifrado del pedido).
| Se cargan con require_once: Pest no comparte funciones entre archivos de test.
*/

/** Lo que hace Meta: AES-128-GCM al payload y RSA-OAEP-SHA256 (vía openssl CLI) a la clave. */
function metaEncrypt(string $publicPem, array $payload, ?string $oaepDigest = 'sha256'): array
{
    $aesKey = random_bytes(16);
    $iv = random_bytes(16);
    $tag = '';
    $ciphertext = openssl_encrypt(json_encode($payload), 'aes-128-gcm', $aesKey, OPENSSL_RAW_DATA, $iv, $tag, '', 16);

    $dir = sys_get_temp_dir().'/flowtest-'.uniqid();
    mkdir($dir);
    file_put_contents("$dir/pub.pem", $publicPem);
    file_put_contents("$dir/key.bin", $aesKey);

    $cmd = sprintf(
        'openssl pkeyutl -encrypt -pubin -inkey %s -in %s -pkeyopt rsa_padding_mode:oaep -pkeyopt rsa_oaep_md:%s -pkeyopt rsa_mgf1_md:%s',
        escapeshellarg("$dir/pub.pem"), escapeshellarg("$dir/key.bin"), $oaepDigest, $oaepDigest
    );
    $encryptedKey = shell_exec($cmd);
    array_map('unlink', glob("$dir/*"));
    rmdir($dir);

    return [
        'body' => [
            'encrypted_aes_key'   => base64_encode($encryptedKey),
            'encrypted_flow_data' => base64_encode($ciphertext.$tag),
            'initial_vector'      => base64_encode($iv),
        ],
        'aes_key' => $aesKey,
        'iv'      => $iv,
    ];
}

function flowConversation(int $personId): \Webkul\Whatsapp\Models\Conversation
{
    return \Webkul\Whatsapp\Models\Conversation::create([
        'gateway'   => 'cloud_api',
        'wa_phone'  => '5916'.rand(1000000, 9999999),
        'status'    => 'open',
        'person_id' => $personId,
    ]);
}

/**
 * Pedido de Meta al endpoint. Devuelve [status HTTP, respuesta descifrada|null].
 */
function flowCall(string $publicPem, array $payload, ?string $signature = 'auto', ?string $encryptForPem = null): array
{
    $meta = metaEncrypt($encryptForPem ?? $publicPem, $payload);
    $body = json_encode($meta['body']);

    $headers = ['CONTENT_TYPE' => 'application/json'];

    if ($signature !== null) {
        $headers['HTTP_X-Hub-Signature-256'] = $signature === 'auto'
            ? 'sha256='.hash_hmac('sha256', $body, 'flow-app-secret')
            : $signature;
    }

    $response = test()->call('POST', '/api/v1/whatsapp/flows/endpoint', [], [], [], $headers, $body);

    if ($response->status() !== 200) {
        return [$response->status(), null];
    }

    $raw = base64_decode($response->getContent());
    $flipped = $meta['iv'] ^ str_repeat("\xFF", 16);
    $json = openssl_decrypt(substr($raw, 0, -16), 'aes-128-gcm', $meta['aes_key'], OPENSSL_RAW_DATA, $flipped, substr($raw, -16));

    return [200, json_decode($json, true)];
}

function step(string $publicPem, string $token, string $screen, array $data): array
{
    return flowCall($publicPem, ['version' => '3.0', 'action' => 'data_exchange', 'flow_token' => $token, 'screen' => $screen, 'data' => $data])[1];
}

/**
 * Deja el endpoint de Flows listo para un test: par de claves temporal, firma
 * de Meta y clave privada configurada. Devuelve [publicPem, keyPath].
 */
function bootFlowEndpoint(): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $privatePem);
    $keyPath = tempnam(sys_get_temp_dir(), 'flowkey');
    file_put_contents($keyPath, $privatePem);

    config([
        'whatsapp.flows.private_key_path'       => $keyPath,
        'whatsapp.flows.private_key_passphrase' => null,
        'whatsapp.cloud.app_secret'             => 'flow-app-secret',
    ]);

    return [openssl_pkey_get_details($key)['key'], $keyPath];
}
