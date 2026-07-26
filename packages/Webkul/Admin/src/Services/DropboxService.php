<?php

namespace Webkul\Admin\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DropboxService
{
    private string $folder;

    private const TOKEN_CACHE_KEY = 'dropbox_access_token';

    private const TOKEN_TTL = 12600; // 3h30m — refresca antes de las 4h de expiración

    public function __construct()
    {
        $this->folder = config('smd.dropbox.appointments_folder') ?? '/smd-events';
    }

    /**
     * Token de acceso, renovado via refresh_token y cacheado.
     *
     * Solo se cachea un token renovado con exito: si el refresh falla se devuelve
     * el fallback sin guardarlo, porque cachear el fallo dejaria el sync caido
     * durante TOKEN_TTL aun despues de que Dropbox se recupere.
     */
    private function token(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);

        if ($cached) {
            return $cached;
        }

        $refreshToken = config('smd.dropbox.refresh_token');
        $appKey = config('smd.dropbox.app_key');
        $appSecret = config('smd.dropbox.app_secret');

        if (! $refreshToken || ! $appKey || ! $appSecret) {
            return config('smd.dropbox.access_token') ?? '';
        }

        try {
            $response = Http::asForm()->post('https://api.dropboxapi.com/oauth2/token', [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id'     => $appKey,
                'client_secret' => $appSecret,
            ]);
        } catch (\Throwable $e) {
            Log::error('[Dropbox] Excepcion refrescando token', ['error' => $e->getMessage()]);

            return config('smd.dropbox.access_token') ?? '';
        }

        if (! $response->successful()) {
            Log::error('[Dropbox] Error refrescando token', ['body' => $response->body()]);

            return config('smd.dropbox.access_token') ?? '';
        }

        $newToken = $response->json('access_token');

        if (! $newToken) {
            Log::error('[Dropbox] El refresh no devolvio access_token', ['body' => $response->body()]);

            return config('smd.dropbox.access_token') ?? '';
        }

        Cache::put(self::TOKEN_CACHE_KEY, $newToken, self::TOKEN_TTL);
        Log::info('[Dropbox] Token renovado correctamente');

        return $newToken;
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
     *
     * Pagina hasta agotar la carpeta. `list_folder` corta alrededor de 2000
     * entradas y avisa con `has_more` + un cursor; la versión anterior leía sólo
     * la primera página y descartaba el resto SIN error y SIN log, así que una
     * carpeta grande se sincronizaba a medias y esas citas no existían para el
     * CRM. Es la clase de pérdida de datos que nadie nota hasta que falta una
     * cita puntual.
     *
     * Devuelve array de entries de Dropbox o [] si hay error.
     */
    public function listFilesForDate(string $date): array
    {
        $path = rtrim($this->folder, '/').'/'.$date;

        $entries = [];
        $cursor = null;
        $page = 0;

        do {
            $result = $cursor === null
                ? $this->listFolderPage($path)
                : $this->listChanges($cursor);

            $entries = array_merge($entries, $result['entries'] ?? []);
            $cursor = $result['cursor'] ?? null;
            $hasMore = (bool) ($result['has_more'] ?? false);

            // Cortafuegos: sin cursor no hay forma de pedir la página siguiente,
            // y seguir el bucle sería infinito.
            if ($hasMore && ! $cursor) {
                Log::warning('[Dropbox] has_more sin cursor, se corta la paginación', [
                    'path'  => $path,
                    'hasta' => count($entries),
                ]);

                break;
            }

            $page++;
        } while ($hasMore && $page < 100);

        if ($page > 1) {
            Log::info('[Dropbox] Carpeta paginada', [
                'path'     => $path,
                'paginas'  => $page,
                'entradas' => count($entries),
            ]);
        }

        return collect($entries)
            ->filter(fn ($e) => ($e['.tag'] ?? '') === 'file' && str_ends_with($e['name'] ?? '', '.json'))
            ->values()
            ->toArray();
    }

    /**
     * Primera página de una carpeta. Devuelve entries + cursor + has_more para
     * que listFilesForDate() pueda seguir pidiendo el resto.
     *
     * @return array{entries: array, cursor: ?string, has_more: bool}
     */
    private function listFolderPage(string $path): array
    {
        $vacio = ['entries' => [], 'cursor' => null, 'has_more' => false];

        return $this->retryWithFreshToken(function (string $token) use ($path, $vacio) {
            try {
                $response = Http::withToken($token)
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->post('https://api.dropboxapi.com/2/files/list_folder', [
                        'path'      => $path,
                        'recursive' => false,
                    ]);

                // 409 = la carpeta del día todavía no existe. Es normal.
                if ($response->status() === 409) {
                    return $vacio;
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

                    return $vacio;
                }

                return [
                    'entries'  => $response->json('entries', []),
                    'cursor'   => $response->json('cursor'),
                    'has_more' => (bool) $response->json('has_more', false),
                ];
            } catch (\Throwable $e) {
                Log::error('[Dropbox] Excepcion listando carpeta', ['error' => $e->getMessage()]);

                return $vacio;
            }
        }, default: $vacio);
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

            $cursor = $response->json('cursor');
            $hasMore = $response->json('has_more', false);

            while ($hasMore) {
                $next = $this->listChanges($cursor);
                $cursor = $next['cursor'] ?? $cursor;
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
        return $this->retryWithFreshToken(function (string $token) use ($cursor) {
            try {
                $response = Http::withToken($token)
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->post('https://api.dropboxapi.com/2/files/list_folder/continue', [
                        'cursor' => $cursor,
                    ]);

                if ($response->status() === 401) {
                    return ['_expired' => true];
                }

                // Cursor inválido/reseteado: Dropbox responde con error `reset`.
                // Se señala para que quien llama re-inicialice el cursor en vez de
                // reintentar en bucle con un cursor que ya no sirve.
                if ($response->status() === 409 && str_contains($response->body(), 'reset')) {
                    Log::warning('[Dropbox] Cursor reseteado por Dropbox, requiere reinicialización', [
                        'body' => $response->body(),
                    ]);

                    return ['entries' => [], 'cursor' => null, 'has_more' => false, 'reset' => true];
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
        }, default: ['entries' => [], 'cursor' => $cursor, 'has_more' => false]);
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

    /**
     * Descarga varios JSON a la vez.
     *
     * La descarga de a uno era el cuello de botella real: un round-trip HTTP por
     * archivo, en serie, con miles de archivos en una carga masiva. El tiempo lo
     * domina la latencia, no el ancho de banda ni el CPU, así que basta con
     * pedirlos en paralelo.
     *
     * El tamaño del lote no se sube más porque Dropbox limita la concurrencia
     * por app y responde 429; con `SMD_DROPBOX_CONCURRENCY` se puede ajustar sin
     * tocar código si el límite cambia.
     *
     * Los que fallan salen del resultado en vez de propagar el error: quien
     * llama los trata como no procesados y el siguiente ciclo los reintenta.
     *
     * @param  array<int, string>  $paths
     * @return array<string, array> ruta => payload decodificado
     */
    public function downloadManyJson(array $paths, int $intento = 1): array
    {
        $paths = array_values(array_unique(array_filter($paths)));

        if (! $paths) {
            return [];
        }

        $concurrency = max(1, (int) config('smd.dropbox.concurrency', 10));
        $decoded = [];
        $expired = false;

        foreach (array_chunk($paths, $concurrency) as $chunk) {
            $token = $this->token();

            try {
                $responses = Http::pool(fn ($pool) => array_map(
                    fn ($path) => $pool->as($path)
                        ->withToken($token)
                        ->withHeaders(['Dropbox-API-Arg' => json_encode(['path' => $path])])
                        ->withBody('', 'text/plain')
                        ->post('https://content.dropboxapi.com/2/files/download'),
                    $chunk,
                ));
            } catch (\Throwable $e) {
                Log::error('[Dropbox] Excepcion en descarga paralela', ['error' => $e->getMessage()]);

                continue;
            }

            foreach ($chunk as $path) {
                $response = $responses[$path] ?? null;

                // Una conexión rota llega como excepción en lugar de respuesta.
                if (! $response instanceof \Illuminate\Http\Client\Response) {
                    Log::warning('[Dropbox] Descarga fallida', ['path' => $path]);

                    continue;
                }

                if ($response->status() === 401) {
                    $expired = true;

                    continue;
                }

                if (! $response->successful()) {
                    Log::warning('[Dropbox] Error descargando archivo', [
                        'path'   => $path,
                        'status' => $response->status(),
                    ]);

                    continue;
                }

                $json = json_decode($response->body(), true);

                if (is_array($json)) {
                    $decoded[$path] = $json;
                }
            }
        }

        // El token venció a mitad del lote: se renueva y se reintenta sólo lo
        // que quedó sin bajar, no todo de nuevo. Un solo reintento — si el token
        // nuevo también da 401 el problema son las credenciales, y reintentar en
        // bucle sólo convertiría un error de config en una tormenta de requests.
        if ($expired && $intento === 1) {
            Cache::forget(self::TOKEN_CACHE_KEY);
            Log::info('[Dropbox] Token expirado durante la descarga paralela, reintentando lo pendiente');

            $faltantes = array_values(array_diff($paths, array_keys($decoded)));

            if ($faltantes) {
                $decoded += $this->downloadManyJson($faltantes, 2);
            }
        } elseif ($expired) {
            Log::error('[Dropbox] Token sigue vencido tras renovar; quedan archivos sin bajar', [
                'pendientes' => count($paths) - count($decoded),
            ]);
        }

        return $decoded;
    }
}
