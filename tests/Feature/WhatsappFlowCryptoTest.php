<?php

/*
|--------------------------------------------------------------------------
| Cifrado del endpoint de WhatsApp Flows
|--------------------------------------------------------------------------
| Simula al cliente de Meta. La clave AES se cifra con el binario `openssl`
| (RSA-OAEP SHA-256), una implementación independiente de FlowCrypto: si el
| OAEP hecho a mano tuviera un error, este test lo detecta.
*/

require_once __DIR__.'/../Support/FlowTestHelpers.php';

use Webkul\Whatsapp\Services\Flows\FlowCrypto;

beforeEach(function () {
    if (trim((string) shell_exec('command -v openssl')) === '') {
        $this->markTestSkipped('Falta el binario openssl para generar los vectores de prueba.');
    }

    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $this->privatePem);
    $this->publicPem = openssl_pkey_get_details($key)['key'];

    $this->crypto = new FlowCrypto;
});

it('descifra un pedido cifrado como lo hace Meta', function () {
    $payload = ['version' => '3.0', 'action' => 'INIT', 'flow_token' => 'tok-123'];
    $meta = metaEncrypt($this->publicPem, $payload);

    $result = $this->crypto->decryptRequest($meta['body'], $this->privatePem);

    expect($result['payload'])->toBe($payload)
        ->and($result['aes_key'])->toBe($meta['aes_key'])
        ->and($result['iv'])->toBe($meta['iv']);
});

it('cifra la respuesta con la misma clave y el IV invertido, y Meta la puede leer', function () {
    $meta = metaEncrypt($this->publicPem, ['version' => '3.0', 'action' => 'ping']);
    $request = $this->crypto->decryptRequest($meta['body'], $this->privatePem);

    $body = $this->crypto->encryptResponse(['data' => ['status' => 'active']], $request['aes_key'], $request['iv']);

    // Del lado de Meta: base64 → ciphertext + tag, IV invertido.
    $raw = base64_decode($body);
    $flipped = $meta['iv'] ^ str_repeat("\xFF", 16);
    $json = openssl_decrypt(substr($raw, 0, -16), 'aes-128-gcm', $meta['aes_key'], OPENSSL_RAW_DATA, $flipped, substr($raw, -16));

    expect(json_decode($json, true))->toBe(['data' => ['status' => 'active']]);
});

it('rechaza una clave cifrada con OAEP SHA-1 (el algoritmo equivocado)', function () {
    $meta = metaEncrypt($this->publicPem, ['action' => 'ping'], 'sha1');

    expect(fn () => $this->crypto->decryptRequest($meta['body'], $this->privatePem))
        ->toThrow(RuntimeException::class);
});

it('rechaza un payload adulterado (falla el tag de GCM)', function () {
    $meta = metaEncrypt($this->publicPem, ['action' => 'INIT']);
    $data = base64_decode($meta['body']['encrypted_flow_data']);
    $data[0] = chr(ord($data[0]) ^ 0x01);
    $meta['body']['encrypted_flow_data'] = base64_encode($data);

    expect(fn () => $this->crypto->decryptRequest($meta['body'], $this->privatePem))
        ->toThrow(RuntimeException::class);
});

it('rechaza un pedido cifrado para otra clave pública', function () {
    $otra = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $meta = metaEncrypt(openssl_pkey_get_details($otra)['key'], ['action' => 'INIT']);

    expect(fn () => $this->crypto->decryptRequest($meta['body'], $this->privatePem))
        ->toThrow(RuntimeException::class);
});

it('rechaza un cuerpo incompleto', function () {
    expect(fn () => $this->crypto->decryptRequest(['initial_vector' => 'x'], $this->privatePem))
        ->toThrow(RuntimeException::class);
});
