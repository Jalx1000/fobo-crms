<?php

namespace Webkul\Admin\Services\Insurance;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Padrones de asegurados que viven en Google Sheets (Nacional Vida, Membresía
 * Odontoking). Reemplaza al webhook de n8n, que solo leía la planilla y
 * devolvía las filas: devuelve lo mismo, `{success, data: [filas]}`, con cada
 * fila como [encabezado => valor], así la lógica de cada driver no cambia.
 *
 * Acceso con una cuenta de servicio de Google (solo lectura): la planilla se
 * comparte con su email. El token se firma acá (JWT RS256 con openssl), sin
 * SDK. Las filas se cachean unos minutos: la planilla se edita a mano y
 * leerla entera en cada verificación sería lento y gastaría cuota.
 *
 * config('services.insurance_sheets.<padrón>') = spreadsheet_id, range, ci_columns
 */
class GoogleSheetsInsuranceSource
{
    protected const SCOPE = 'https://www.googleapis.com/auth/spreadsheets.readonly';

    /**
     * Filas del padrón con esa cédula.
     *
     * @return array{success: bool, data: array<int, array<string, string>>}
     *
     * @throws RuntimeException si el padrón no está configurado o Google falla
     */
    public function lookup(string $roster, string $ci): array
    {
        $config = (array) config("services.insurance_sheets.{$roster}");
        $target = $this->normalizeCi($ci);

        if ($target === '') {
            return ['success' => false, 'data' => []];
        }

        $rows = $this->rows($roster, $config);
        $columns = (array) ($config['ci_columns'] ?? ['CI']);

        $exact = [];
        $sameNumber = [];

        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $value = $this->normalizeCi((string) ($row[$column] ?? ''));

                if ($value === '') {
                    continue;
                }

                if ($value === $target) {
                    $exact[] = $row;
                } elseif ($this->number($value) === $this->number($target)) {
                    $sameNumber[] = $row;
                }
            }
        }

        // Exacta primero. Si no, el mismo número sin/con otro complemento o
        // sigla ("1234567" ↔ "1234567 LP"), pero solo si no hay ambigüedad.
        $matches = $exact ?: (count($sameNumber) === 1 ? $sameNumber : []);

        return ['success' => (bool) $matches, 'data' => $matches];
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function rows(string $roster, ?array $config = null): array
    {
        $config ??= (array) config("services.insurance_sheets.{$roster}");

        if (empty($config['spreadsheet_id'])) {
            throw new RuntimeException("Padrón de seguros '{$roster}' sin spreadsheet_id configurado.");
        }

        $minutes = max(1, (int) config('services.google_sheets.cache_minutes', 10));

        return Cache::remember('insurance_sheet:'.$roster.':'.md5(json_encode($config)), now()->addMinutes($minutes), function () use ($config) {
            $range = (string) ($config['range'] ?? 'A:ZZ');

            $response = Http::withToken($this->accessToken())
                ->acceptJson()
                ->timeout(20)
                ->get('https://sheets.googleapis.com/v4/spreadsheets/'.rawurlencode($config['spreadsheet_id']).'/values/'.rawurlencode($range));

            if (! $response->successful()) {
                throw new RuntimeException('Google Sheets respondió '.$response->status().': '.mb_substr($response->body(), 0, 200));
            }

            $values = (array) $response->json('values', []);
            $headers = array_map(fn ($h) => trim((string) $h), (array) array_shift($values));

            return array_values(array_filter(array_map(function ($row) use ($headers) {
                $assoc = [];

                foreach ($headers as $i => $header) {
                    if ($header !== '') {
                        $assoc[$header] = trim((string) ($row[$i] ?? ''));
                    }
                }

                return array_filter($assoc, fn ($v) => $v !== '') ? $assoc : null;
            }, $values)));
        });
    }

    protected function accessToken(): string
    {
        $credentials = $this->credentials();

        return Cache::remember('google_sheets:token:'.md5($credentials['client_email']), now()->addMinutes(50), function () use ($credentials) {
            $now = time();
            $segments = [
                $this->base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
                $this->base64url(json_encode([
                    'iss'   => $credentials['client_email'],
                    'scope' => self::SCOPE,
                    'aud'   => 'https://oauth2.googleapis.com/token',
                    'iat'   => $now,
                    'exp'   => $now + 3600,
                ])),
            ];

            if (! openssl_sign(implode('.', $segments), $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('No se pudo firmar el token de Google: revisá la private_key de la cuenta de servicio.');
            }

            $response = Http::asForm()->timeout(15)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => implode('.', $segments).'.'.$this->base64url($signature),
            ]);

            if (! $response->successful() || ! $response->json('access_token')) {
                throw new RuntimeException('Google rechazó la cuenta de servicio: '.mb_substr($response->body(), 0, 200));
            }

            return (string) $response->json('access_token');
        });
    }

    /**
     * @return array{client_email: string, private_key: string}
     */
    protected function credentials(): array
    {
        $path = (string) config('services.google_sheets.credentials_path');
        $json = is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (empty($json['client_email']) || empty($json['private_key'])) {
            throw new RuntimeException("Falta la cuenta de servicio de Google en {$path}.");
        }

        return ['client_email' => $json['client_email'], 'private_key' => $json['private_key']];
    }

    /**
     * Mayúsculas, sin espacios ni puntos, sin la sigla de expedición
     * ("1.234.567 LP" → "1234567"); conserva el complemento ("1234567-1A").
     */
    public function normalizeCi(string $ci): string
    {
        $ci = strtoupper(trim($ci));
        $ci = preg_replace('/\s*\b(LP|SC|CB|OR|PT|CH|TJ|BE|PD|PA)\.?$/', '', $ci);

        return preg_replace('/[^A-Z0-9\-]/', '', $ci);
    }

    /** El número de la cédula, sin complemento. */
    protected function number(string $normalized): string
    {
        return preg_replace('/\D/', '', explode('-', $normalized)[0]);
    }

    protected function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
