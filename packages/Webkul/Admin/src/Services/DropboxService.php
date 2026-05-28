<?php

namespace Webkul\Admin\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DropboxService
{
    private string $folder;

    private const TOKEN_CACHE_KEY = 'dropbox_access_token';
    private const TOKEN_TTL       = 13500; // 3h45m — refresca antes de las 4h de expiración

    public function __construct()
    {
        $this->folder = config('smd.dropbox.appointments_folder') ?? '/smd-events';
    }

    private function token(): string
    {
        return Cache::remember(self::TOKEN_CACHE_KEY, self::TOKEN_TTL, function () {
            $refreshToken = config('smd.dropbox.refresh_token');
            $appKey       = config('smd.dropbox.app_key');
            $appSecret    = config('smd.dropbox.app_secret');

            if (! $refreshToken || ! $appKey || ! $appSecret) {
                return config('smd.dropbox.access_token') ?? '';
            }

            $response = Http::asForm()->post('https://api.dropboxapi.com/oauth2/token', [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id'     => $appKey,
                'client_secret' => $appSecret,
            ]);

            if (! $response->successful()) {
                Log::error('[Dropbox] Error refrescando token', ['body' => $response->body()]);
                return config('smd.dropbox.access_token') ?? '';
            }

            $newToken = $response->json('access_token');
            Log::info('[Dropbox] Token renovado correctamente');

            return $newToken;
        });
    }

    /**
     * Ejecuta $call con el token actual. Si devuelve ['_expired' => true],
     * renueva el token y reintenta una vez. Nunca devuelve el marcador _expired.
     * $default es el valor a retornar si ambos intentos fallan con token expirado.
     */
    private function retryWithFreshToken(callable $call, mixed $default = null): mixed
    {
        $result = $call($this->token());

        if (is_array($result) && isset($result['_expired'])) {
            Cache::forget(self::TOKEN_CACHE_KEY);
            Log::info('[Dropbox] Token expirado, renovando y reintentando...');
            $result = $call($this->token());
        }

        // Si después del retry sigue siendo el marcador, devolver default
        if (is_array($result) && isset($result['_expired'])) {
            Log::error('[Dropbox] Token expirado incluso después de renovar.');
            return $default;
        }

        return $result;
    }

    /**
     * Lista archivos JSON en la carpeta del día dado (YYYY-MM-DD).
     * Devuelve array de entries de Dropbox o [] si hay error.
     */
    public function listFilesForDate(string $date): array
    {
        $path = rtrim($this->folder, '/') . '/' . $date;

        return $this->retryWithFreshToken(function (string $token) use ($path) {
            try {
                $response = Http::withToken($token)
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->post('https://api.dropboxapi.com/2/files/list_folder', [
                        'path'      => $path,
                        'recursive' => false,
                    ]);

                if ($response->status() === 409) {
                    return [];
                }

                if ($response->status() === 401) {
                    return ['_expired' => true];
                }

                if (! $response->successful()) {
                    Log::warning('[Dropbox] Error listando carpeta', [
                        'path'   => $path,
                        'status' => $response->status(),
                        'body'   => $response->body(),
                    ]);

                    return [];
                }

                return collect($response->json('entries', []))
                    ->filter(fn ($e) => str_ends_with($e['name'] ?? '', '.json'))
                    ->values()
                    ->toArray();

            } catch (\Throwable $e) {
                Log::error('[Dropbox] Excepcion listando carpeta', ['error' => $e->getMessage()]);

                return [];
            }
        }, default: []);
    }

    /**
     * Obtiene el cursor inicial para una carpeta (para inicializar el webhook).
     */
    public function getInitialCursor(string $folderPath): ?string
    {
        try {
            $response = Http::withToken($this->token())
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post('https://api.dropboxapi.com/2/files/list_folder', [
                    'path'                           => $folderPath,
                    'recursive'                      => true,
                    'include_non_downloadable_files' => false,
                ]);

            if (! $response->successful()) {
                Log::warning('[Dropbox] No se pudo obtener cursor inicial', ['body' => $response->body()]);

                return null;
            }

            $cursor  = $response->json('cursor');
            $hasMore = $response->json('has_more', false);

            while ($hasMore) {
                $next    = $this->listChanges($cursor);
                $cursor  = $next['cursor']   ?? $cursor;
                $hasMore = $next['has_more'] ?? false;
            }

            return $cursor;

        } catch (\Throwable $e) {
            Log::error('[Dropbox] Excepcion obteniendo cursor inicial', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Lista los cambios desde el cursor guardado.
     * Devuelve ['entries' => [...], 'cursor' => '...', 'has_more' => bool]
     */
    public function listChanges(string $cursor): array
    {
        $result = $this->retryWithFreshToken(function (string $token) use ($cursor) {
            try {
                $response = Http::withToken($token)
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->post('https://api.dropboxapi.com/2/files/list_folder/continue', [
                        'cursor' => $cursor,
                    ]);

                if ($response->status() === 401) {
                    return ['_expired' => true];
                }

                if (! $response->successful()) {
                    Log::warning('[Dropbox] Error en list_folder/continue', [
                        'status' => $response->status(),
                        'body'   => $response->body(),
                    ]);

                    return ['entries' => [], 'cursor' => $cursor, 'has_more' => false];
                }

                return [
                    'entries'  => collect($response->json('entries', []))
                        ->filter(fn ($e) => ($e['.tag'] ?? '') === 'file' && str_ends_with($e['name'] ?? '', '.json'))
                        ->values()
                        ->toArray(),
                    'cursor'   => $response->json('cursor', $cursor),
                    'has_more' => $response->json('has_more', false),
                ];

            } catch (\Throwable $e) {
                Log::error('[Dropbox] Excepcion en listChanges', ['error' => $e->getMessage()]);

                return ['entries' => [], 'cursor' => $cursor, 'has_more' => false];
            }
        });

        return $result;
    }

    /**
     * Descarga y parsea un archivo JSON de Dropbox.
     * Devuelve el array decodificado o null si hay error.
     */
    public function downloadJson(string $pathLower): ?array
    {
        return $this->retryWithFreshToken(function (string $token) use ($pathLower) {
            try {
                $response = Http::withToken($token)
                    ->withHeaders([
                        'Dropbox-API-Arg' => json_encode(['path' => $pathLower]),
                    ])
                    ->withBody('', 'text/plain')
                    ->post('https://content.dropboxapi.com/2/files/download');

                if ($response->status() === 401) {
                    return ['_expired' => true];
                }

                if (! $response->successful()) {
                    Log::warning('[Dropbox] Error descargando archivo', [
                        'path'   => $pathLower,
                        'status' => $response->status(),
                    ]);

                    return null;
                }

                return json_decode($response->body(), true);

            } catch (\Throwable $e) {
                Log::error('[Dropbox] Excepcion descargando archivo', ['error' => $e->getMessage()]);

                return null;
            }
        }, default: null);
    }
}
