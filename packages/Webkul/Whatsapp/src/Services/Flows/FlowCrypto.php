<?php

namespace Webkul\Whatsapp\Services\Flows;

use RuntimeException;

/**
 * Cifrado del endpoint de WhatsApp Flows.
 *
 * Meta manda {encrypted_aes_key, encrypted_flow_data, initial_vector}:
 *  - la clave AES de 128 bits viene cifrada con NUESTRA clave pública, con
 *    RSA/ECB/OAEPWithSHA-256AndMGF1Padding (SHA-256 también en MGF1);
 *  - el payload viene en AES-128-GCM, con el tag de 16 bytes al final;
 *  - la respuesta va en AES-128-GCM con la MISMA clave y el IV invertido
 *    (cada byte XOR 0xFF), tag al final, todo en base64 como texto plano.
 *
 * https://developers.facebook.com/docs/whatsapp/flows/guides/implementingyourflowendpoint
 *
 * El OAEP con SHA-256 se decodifica acá a mano (RFC 8017 §7.1.2) sobre RSA
 * crudo: openssl_private_decrypt() de PHP solo hace OAEP con SHA-1, y así no
 * hace falta sumar una dependencia. El test lo valida contra el binario
 * `openssl`, que es una implementación independiente.
 */
class FlowCrypto
{
    /**
     * @return array{payload: array, aes_key: string, iv: string}
     *
     * @throws RuntimeException si no se puede descifrar (el endpoint responde 421)
     */
    public function decryptRequest(array $body, string $privateKeyPem, ?string $passphrase = null): array
    {
        $encryptedKey = base64_decode((string) ($body['encrypted_aes_key'] ?? ''), true);
        $encryptedData = base64_decode((string) ($body['encrypted_flow_data'] ?? ''), true);
        $iv = base64_decode((string) ($body['initial_vector'] ?? ''), true);

        if (! $encryptedKey || ! $encryptedData || ! $iv || strlen($encryptedData) <= 16) {
            throw new RuntimeException('Pedido de Flow incompleto o mal codificado.');
        }

        $aesKey = $this->rsaOaepSha256Decrypt($encryptedKey, $privateKeyPem, $passphrase);

        $tag = substr($encryptedData, -16);
        $ciphertext = substr($encryptedData, 0, -16);

        $json = openssl_decrypt($ciphertext, $this->cipherFor($aesKey), $aesKey, OPENSSL_RAW_DATA, $iv, $tag);

        if ($json === false) {
            throw new RuntimeException('No se pudo descifrar el payload del Flow (AES-GCM).');
        }

        $payload = json_decode($json, true);

        if (! is_array($payload)) {
            throw new RuntimeException('El payload del Flow no es JSON válido.');
        }

        return ['payload' => $payload, 'aes_key' => $aesKey, 'iv' => $iv];
    }

    /**
     * Respuesta cifrada lista para devolver como cuerpo text/plain.
     */
    public function encryptResponse(array $response, string $aesKey, string $requestIv): string
    {
        $flippedIv = $requestIv ^ str_repeat("\xFF", strlen($requestIv));
        $tag = '';

        $ciphertext = openssl_encrypt(
            json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $this->cipherFor($aesKey),
            $aesKey,
            OPENSSL_RAW_DATA,
            $flippedIv,
            $tag,
            '',
            16,
        );

        if ($ciphertext === false) {
            throw new RuntimeException('No se pudo cifrar la respuesta del Flow.');
        }

        return base64_encode($ciphertext.$tag);
    }

    /**
     * RSA crudo + decodificado OAEP (SHA-256 en el hash y en MGF1, label vacío).
     */
    protected function rsaOaepSha256Decrypt(string $ciphertext, string $privateKeyPem, ?string $passphrase): string
    {
        $key = openssl_pkey_get_private($privateKeyPem, $passphrase ?? '');

        if ($key === false) {
            throw new RuntimeException('Clave privada del Flow inválida o passphrase incorrecta.');
        }

        $k = (int) (openssl_pkey_get_details($key)['bits'] / 8);

        if (strlen($ciphertext) !== $k || ! openssl_private_decrypt($ciphertext, $em, $key, OPENSSL_NO_PADDING)) {
            throw new RuntimeException('No se pudo descifrar la clave AES del Flow (RSA).');
        }

        $hLen = 32;
        $em = str_pad($em, $k, "\0", STR_PAD_LEFT);

        $y = ord($em[0]);
        $maskedSeed = substr($em, 1, $hLen);
        $maskedDb = substr($em, 1 + $hLen);

        $seed = $maskedSeed ^ $this->mgf1($maskedDb, $hLen);
        $db = $maskedDb ^ $this->mgf1($seed, $k - $hLen - 1);

        $lHash = hash('sha256', '', true);
        $rest = substr($db, $hLen);
        $separator = strpos($rest, "\x01");

        // Se evalúan todas las condiciones juntas para no filtrar cuál falló.
        $valid = ($y === 0)
            & hash_equals($lHash, substr($db, 0, $hLen))
            & ($separator !== false)
            & ($separator === false || trim(substr($rest, 0, $separator), "\0") === '');

        if (! $valid) {
            throw new RuntimeException('Padding OAEP inválido en la clave AES del Flow.');
        }

        return substr($rest, $separator + 1);
    }

    protected function mgf1(string $seed, int $length): string
    {
        $mask = '';

        for ($counter = 0; strlen($mask) < $length; $counter++) {
            $mask .= hash('sha256', $seed.pack('N', $counter), true);
        }

        return substr($mask, 0, $length);
    }

    protected function cipherFor(string $aesKey): string
    {
        return match (strlen($aesKey)) {
            16      => 'aes-128-gcm',
            24      => 'aes-192-gcm',
            32      => 'aes-256-gcm',
            default => throw new RuntimeException('Largo de clave AES inesperado: '.strlen($aesKey)),
        };
    }
}
