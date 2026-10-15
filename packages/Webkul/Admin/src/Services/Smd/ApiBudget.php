<?php

namespace Webkul\Admin\Services\Smd;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Webkul\Admin\Mail\SmdUsageAlert;

/**
 * Medidor de consultas a la API de ShareMeData.
 *
 * OdontoKing paga 10.000 consultas por mes, y el 2026-10-12 un proceso
 * programado hizo ~5.700 en 17 h sin que nadie lo viera: SMD cortó el acceso.
 * Desde entonces cada consulta se cuenta (por día, por mes y por origen) y
 * queda una línea en el log, así el consumo se puede mostrar con números
 * (`php artisan smd:usage`). Ver planning/15-consumo-api-smd.
 *
 * Cuenta el intento, no la respuesta: una consulta que falla también la cobra SMD.
 */
class ApiBudget
{
    /** Los contadores viven 45 días: alcanza para cerrar el mes y compararlo. */
    protected const TTL_DAYS = 45;

    /**
     * Registra `$count` consultas hechas desde `$source` (ej. "AvailabilityResolverService::resolve")
     * contra `$endpoint` (el método de ShareMeDataService que la hizo).
     */
    public function record(string $endpoint, string $source, int $count = 1): void
    {
        if ($count < 1) {
            return;
        }

        $day = now(config('app.timezone'))->toDateString();
        $month = substr($day, 0, 7);
        $ttl = now()->addDays(self::TTL_DAYS);

        $this->increment("smd:usage:day:{$day}", $count, $ttl);
        $this->increment("smd:usage:month:{$month}", $count, $ttl);
        $this->increment("smd:usage:day:{$day}:src:".md5($source.'|'.$endpoint), $count, $ttl);

        // Índice de orígenes del día, para poder listarlos en el reporte.
        $index = Cache::get("smd:usage:day:{$day}:sources", []);

        if (! isset($index[$source.'|'.$endpoint])) {
            $index[$source.'|'.$endpoint] = true;
            Cache::put("smd:usage:day:{$day}:sources", $index, $ttl);
        }

        Log::info('[SMD API] consulta', ['endpoint' => $endpoint, 'origen' => $source, 'n' => $count]);

        $this->alertIfNeeded($day);
    }

    /**
     * Correo al personal de OdontoKing y Sofopolis al cruzar el aviso (300) o el
     * peligro (500) del día. Uno por nivel y por día; si el día salta los dos
     * de golpe, solo sale el de peligro.
     */
    protected function alertIfNeeded(string $day): void
    {
        $config = (array) config('smd.api_budget.alerts', []);
        $today = $this->day($day);

        foreach (['danger' => 'danger_daily', 'warning' => 'warning_daily'] as $level => $key) {
            $threshold = (int) ($config[$key] ?? 0);

            if ($threshold <= 0 || $today < $threshold) {
                continue;
            }

            if (! Cache::add("smd:usage:alert:{$day}:{$level}", true, now()->addDays(2))) {
                return;
            }

            // El de peligro ya cubre al aviso: no mandar los dos.
            if ($level === 'danger') {
                Cache::add("smd:usage:alert:{$day}:warning", true, now()->addDays(2));
            }

            $context = ['dia' => $day, 'consultas' => $today, 'limite' => $threshold];
            $level === 'danger'
                ? Log::error('[SMD API] PELIGRO: consumo diario sobre el límite', $context)
                : Log::warning('[SMD API] Aviso: consumo diario sobre el límite', $context);

            $emails = (array) ($config['emails'] ?? []);

            if ($emails) {
                try {
                    Mail::to($emails)->queue(new SmdUsageAlert($level, $day, $today, $threshold, $this->month(), $this->bySource($day)));
                } catch (\Throwable $e) {
                    // Un correo que no sale nunca debe frenar la consulta a SMD.
                    Log::error('[SMD API] No se pudo encolar la alerta de consumo', ['error' => $e->getMessage()]);
                }
            }

            return;
        }
    }

    /**
     * Antes de cada consulta: la registra si puede salir; si no, lanza
     * QuotaExceeded sin contarla (no llega a SMD, no la cobran).
     *
     * Pasado el tope diario solo salen las esenciales (config smd.api_budget):
     * crear o editar citas y la ficha de pacientes nuevos de Dropbox.
     */
    public function authorize(string $endpoint, string $source, int $count = 1): void
    {
        $cap = (int) config('smd.api_budget.daily_cap', 800);
        $day = now(config('app.timezone'))->toDateString();

        if ($cap > 0 && $this->day($day) + $count > $cap) {
            if (! $this->isEssential($endpoint, $source)) {
                $this->increment("smd:usage:day:{$day}:blocked", $count, now()->addDays(self::TTL_DAYS));
                $this->warnOnce("cap:{$day}", '[SMD API] Se llegó al tope diario: solo salen las consultas esenciales', [
                    'tope' => $cap, 'primera_bloqueada' => "{$source} → {$endpoint}",
                ]);

                throw new QuotaExceeded("Tope diario de consultas a SMD alcanzado ({$cap}): {$source} → {$endpoint}");
            }

            Log::warning('[SMD API] Consulta esencial por encima del tope diario', ['endpoint' => $endpoint, 'origen' => $source, 'tope' => $cap]);
        }

        $this->record($endpoint, $source, $count);

        $alert = (int) config('smd.api_budget.monthly_alert', 8000);

        if ($alert > 0 && $this->month() >= $alert) {
            $this->warnOnce("month:{$day}", '[SMD API] El consumo del mes pasó el aviso: revisar antes de llegar a las 10.000 contratadas', [
                'mes' => $this->month(), 'aviso' => $alert,
            ]);
        }
    }

    public function isEssential(string $endpoint, string $source): bool
    {
        if (in_array($endpoint, (array) config('smd.api_budget.essential_endpoints', []), true)) {
            return true;
        }

        foreach ((array) config('smd.api_budget.essential_sources', []) as $prefix) {
            if (str_starts_with($source, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Consultas no hechas por el tope en el día. */
    public function blocked(string $date): int
    {
        return (int) Cache::get("smd:usage:day:{$date}:blocked", 0);
    }

    /** Un aviso por clave (y por día): sin inundar el log en cada consulta. */
    protected function warnOnce(string $key, string $message, array $context): void
    {
        if (Cache::add("smd:usage:warned:{$key}", true, now()->addDay())) {
            Log::warning($message, $context);
        }
    }

    public function today(): int
    {
        return $this->day(now(config('app.timezone'))->toDateString());
    }

    public function day(string $date): int
    {
        return (int) Cache::get("smd:usage:day:{$date}", 0);
    }

    public function month(?string $month = null): int
    {
        return (int) Cache::get('smd:usage:month:'.($month ?? now(config('app.timezone'))->format('Y-m')), 0);
    }

    /**
     * Consultas del día por origen, de mayor a menor.
     *
     * @return array<string, int> "origen → endpoint" => cantidad
     */
    public function bySource(string $date): array
    {
        $out = [];

        foreach (array_keys(Cache::get("smd:usage:day:{$date}:sources", [])) as $key) {
            [$source, $endpoint] = explode('|', $key, 2) + [1 => ''];
            $out["{$source} → {$endpoint}"] = (int) Cache::get("smd:usage:day:{$date}:src:".md5($key), 0);
        }

        arsort($out);

        return $out;
    }

    /**
     * Quién pidió la consulta: el primer método fuera de ShareMeDataService y
     * de este medidor (ej. "AvailabilityResolverService::resolve").
     */
    public static function callerOutside(string ...$classes): string
    {
        $classes[] = self::class;

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15) as $frame) {
            $class = $frame['class'] ?? null;

            if ($class && ! in_array($class, $classes, true) && ! str_starts_with($class, 'Illuminate\\')) {
                return class_basename($class).'::'.($frame['function'] ?? '?');
            }
        }

        return 'desconocido';
    }

    protected function increment(string $key, int $by, $ttl): void
    {
        // add() crea la clave con su vencimiento; increment() solo suma.
        Cache::add($key, 0, $ttl);
        Cache::increment($key, $by);
    }
}
