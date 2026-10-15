<?php

namespace Webkul\Admin\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Admin\Jobs\FetchSpecialtyAvailability;

/**
 * Lo reservable desde el formulario de WhatsApp: especialidades, doctores y
 * horarios libres.
 *
 * Los horarios se piden a SMD SOLO cuando el paciente elige una especialidad:
 * 1 consulta por especialidad (SMD devuelve a todos sus doctores juntos), que
 * se cruza con los turnos y citas del CRM y se guarda 5 minutos para el
 * próximo que elija lo mismo. Antes había un catálogo que consultaba SMD cada
 * 10 minutos por cada doctor (~5.700 consultas en 17 h) y SMD cortó el acceso
 * (planning/15-consumo-api-smd).
 *
 * SMD tarda de 2 a 24 s y Meta espera ~10 s cada pantalla: la consulta corre
 * en segundo plano (FetchSpecialtyAvailability) y la pantalla la espera un
 * rato; si no llega, el paciente vuelve a tocar "Siguiente" y la toma ya lista,
 * sin otra consulta.
 */
class BookingOptions
{
    /** Cuánto se reusa la respuesta de SMD de una especialidad. */
    protected const TTL_MINUTES = 5;

    public function __construct(
        protected AvailabilityResolverService $availability,
        protected ShareMeDataService $smd,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Sin consultar SMD: lo que sale del CRM
    |--------------------------------------------------------------------------
    */

    /**
     * Especialidades con al menos un doctor reservable (activo, vinculado a SMD
     * y con turnos en los próximos días).
     *
     * @return array<int, array{id:int, name:string}>
     */
    public function specialties(): array
    {
        $from = $this->today();

        return DB::table('specialties')
            ->join('doctor_specialty', 'doctor_specialty.specialty_id', '=', 'specialties.id')
            ->whereIn('doctor_specialty.doctor_id', $this->bookableDoctorIds($from, $this->days()))
            ->select('specialties.id', 'specialties.name')
            ->distinct()
            ->orderBy('specialties.name')
            ->get()
            ->map(fn ($s) => ['id' => (int) $s->id, 'name' => trim((string) $s->name)])
            ->all();
    }

    /**
     * Las especialidades "generales" reservables (config `general_specialties`):
     * "General", "Odontología General"… En el formulario se muestran como UNA
     * sola opción, "General / No sé qué necesito", que junta a sus doctores.
     * Antes eran opciones separadas y "No sé" elegía solo la primera, aunque
     * no tuviera horarios.
     *
     * @return array<int, int>
     */
    public function generalSpecialtyIds(): array
    {
        $names = array_map(fn ($n) => $this->normalizeName($n), (array) config('whatsapp.flows.booking.general_specialties', []));

        return collect($this->specialties())
            ->filter(fn ($s) => in_array($this->normalizeName($s['name']), $names, true))
            ->pluck('id')
            ->values()
            ->all();
    }

    /** La primera especialidad general (compatibilidad). */
    public function generalSpecialtyId(): ?int
    {
        return $this->generalSpecialtyIds()[0] ?? null;
    }

    protected function normalizeName(string $name): string
    {
        $name = iconv('UTF-8', 'ASCII//TRANSLIT', trim($name)) ?: $name;

        return mb_strtolower(preg_replace('/\s+/', ' ', $name));
    }

    /**
     * Doctores reservables de la especialidad (activos, vinculados a SMD, con
     * turnos). Tengan o no horarios esta semana: sin horarios igual se puede
     * pedir "otra fecha".
     *
     * @return array<int, array{id:int, name:string, unique_id:string}>
     */
    public function doctorsOf(int $specialtyId): array
    {
        return DB::table('doctors')
            ->join('doctor_specialty', 'doctor_specialty.doctor_id', '=', 'doctors.id')
            ->where('doctor_specialty.specialty_id', $specialtyId)
            ->whereIn('doctors.id', $this->bookableDoctorIds($this->today(), $this->maxDays()))
            ->orderBy('doctors.name')
            ->get(['doctors.id', 'doctors.name', 'doctors.unique_id'])
            ->map(fn ($d) => ['id' => (int) $d->id, 'name' => trim((string) $d->name), 'unique_id' => (string) $d->unique_id])
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Con SMD: 1 consulta por especialidad y rango, guardada 5 minutos
    |--------------------------------------------------------------------------
    */

    /**
     * Horarios libres de los doctores de la especialidad entre `$from` y
     * `$from + $days`. Si no están guardados, pide la consulta (una sola vez,
     * aunque varios pacientes elijan lo mismo a la vez) y la espera hasta
     * `$until` (microtime).
     *
     * @return array{failed: bool, slots?: array<int, array<string, array<int, string>>>}|null
     *                                                                                         null = todavía se está consultando
     */
    public function availability(int $specialtyId, string $from, int $days, float $until): ?array
    {
        $key = $this->key($specialtyId, $from, $days);

        if ($hit = Cache::get($key)) {
            return $hit;
        }

        // Un solo pedido por especialidad y rango, aunque toquen varios a la vez.
        if (Cache::add($key.':fetching', true, now()->addMinute())) {
            // Se consulta acá mismo, con el tiempo que le queda a la pantalla:
            // por la cola dependía de que el worker estuviera libre, y el
            // 13/10 tardó 11 s en tomarla (Meta corta a ~10 s).
            $remaining = (int) floor($until - microtime(true));

            if ($remaining >= 2) {
                $result = $this->fetch($specialtyId, $from, $days, $remaining);

                if (empty($result['timeout'])) {
                    return $result;
                }
            }

            // SMD más lento que la pantalla: termina en segundo plano y el
            // próximo toque la toma ya guardada.
            FetchSpecialtyAvailability::dispatch($specialtyId, $from, $days);

            return null;
        }

        while (microtime(true) < $until) {
            usleep(300_000);

            if ($hit = Cache::get($key)) {
                return $hit;
            }
        }

        return null;
    }

    /**
     * La consulta a SMD (la corre FetchSpecialtyAvailability): 1 request para
     * toda la especialidad, repartida entre sus doctores y cruzada con los
     * turnos y citas del CRM. Guarda el resultado; si SMD falla, guarda la
     * falla 1 minuto (para no reintentar en cada toque).
     */
    public function fetch(int $specialtyId, string $from, int $days, ?int $timeout = null): array
    {
        $key = $this->key($specialtyId, $from, $days);
        $tz = config('app.timezone');
        $start = Carbon::parse($from, $tz)->startOfDay();
        $name = trim((string) DB::table('specialties')->where('id', $specialtyId)->value('name'));
        $doctors = collect($this->doctorsOf($specialtyId))->keyBy('unique_id');

        try {
            $items = $this->smd->availabilityBySpecialty(
                $name,
                (string) config('smd.default_subsidiary', 'Santa Cruz'),
                $start->format('Y-m-d H:i:s'),
                $start->copy()->addDays($days - 1)->endOfDay()->format('Y-m-d H:i:s'),
                $timeout,
            );
        } catch (Throwable $e) {
            $items = null;
        }

        // Cortada por el tiempo de la pantalla: no es una falla de SMD, la
        // termina el job en segundo plano (no se guarda como falla).
        if ($items === null && $timeout !== null && $this->smd->lastTimedOut) {
            return ['failed' => false, 'timeout' => true];
        }

        if ($items === null) {
            $result = ['failed' => true];
            Cache::put($key, $result, now()->addMinute());
            Cache::forget($key.':fetching');

            Log::warning('[Agenda] SMD no devolvió los horarios de la especialidad', ['especialidad' => $name]);

            return $result;
        }

        $slots = [];

        foreach ($items as $item) {
            $doctor = $doctors->get((string) ($item['physician']['_id'] ?? ''));

            if (! $doctor) {
                continue;
            }

            $result = $this->availability->scheduleFromSmd(
                $doctor['id'],
                $start,
                $days,
                AvailabilityResolverService::parseSmdSlots((array) ($item['slots'] ?? []), $tz),
            );

            if ($times = $this->hourly($result['schedule'] ?? [])) {
                $slots[$doctor['id']] = $times;
            }
        }

        $result = ['failed' => false, 'slots' => $slots];
        Cache::put($key, $result, now()->addMinutes(self::TTL_MINUTES));
        Cache::forget($key.':fetching');
        $this->remember($key);

        Log::info('[Agenda] Horarios de la especialidad consultados a SMD', [
            'especialidad' => $name, 'desde' => $start->toDateString(), 'dias' => $days, 'doctores_con_horarios' => count($slots),
        ]);

        return $result;
    }

    /**
     * Horarios de los próximos días de UN doctor (para reprogramar su cita):
     * la consulta es la de su especialidad, la misma que usa el formulario de
     * agendar, así que también se comparte 5 minutos.
     *
     * @return array{failed: bool, slots?: array<string, array<int, string>>}|null null = todavía se está consultando
     */
    public function doctorAvailability(int $doctorId, float $until): ?array
    {
        $specialtyId = DB::table('doctor_specialty')->where('doctor_id', $doctorId)->orderBy('specialty_id')->value('specialty_id');

        if (! $specialtyId) {
            return ['failed' => false, 'slots' => []];
        }

        $avail = $this->availability((int) $specialtyId, $this->today()->toDateString(), $this->days(), $until);

        if ($avail === null || $avail['failed']) {
            return $avail;
        }

        return ['failed' => false, 'slots' => $avail['slots'][$doctorId] ?? []];
    }

    /**
     * Saca un horario recién reservado de las respuestas guardadas, para no
     * ofrecérselo a otro en esos 5 minutos.
     */
    public function takeSlot(int $doctorId, string $date, string $time): void
    {
        // Marca: otro paciente que ya tenía este horario en pantalla no lo
        // puede confirmar (lo revisa wasTaken() al confirmar).
        Cache::put("booking:taken:{$doctorId}:{$date}:{$time}", true, now()->addMinutes(30));

        foreach (Cache::get('booking:avail:keys', []) as $key => $_) {
            $cached = Cache::get($key);

            if (! isset($cached['slots'][$doctorId][$date])) {
                continue;
            }

            $cached['slots'][$doctorId][$date] = array_values(array_diff($cached['slots'][$doctorId][$date], [$time]));

            if (! $cached['slots'][$doctorId][$date]) {
                unset($cached['slots'][$doctorId][$date]);
            }

            Cache::put($key, $cached, now()->addMinutes(self::TTL_MINUTES));
        }
    }

    /** ¿Alguien reservó ese horario por el formulario en los últimos minutos? */
    public function wasTaken(int $doctorId, string $date, string $time): bool
    {
        return Cache::has("booking:taken:{$doctorId}:{$date}:{$time}");
    }

    /**
     * ¿Ese horario sigue libre AHORA? Consulta SMD en vivo (lo usa el job que
     * reprograma, justo antes de mover la cita).
     */
    public function isLiveFree(int $doctorId, string $date, string $time): bool
    {
        $day = Carbon::parse($date, config('app.timezone'))->startOfDay();

        try {
            $result = $this->availability->resolve($doctorId, $day, 1, ['duration_minutes' => 0]);
        } catch (Throwable $e) {
            return false;
        }

        // Sin SMD no se puede confirmar: no se mueve la cita.
        return ($result['source'] ?? null) === 'smd'
            && in_array($time, $this->hourly($result['schedule'] ?? [])[$date] ?? [], true);
    }

    /** Días que se muestran al elegir la especialidad (por defecto 7). */
    public function days(): int
    {
        return max(1, (int) (config('whatsapp.flows.booking.days') ?: 7));
    }

    /** Hasta cuántos días adelante se puede pedir "otra fecha". */
    public function maxDays(): int
    {
        return max($this->days(), (int) config('whatsapp.flows.booking.max_days', 90));
    }

    public function duration(): int
    {
        return max(15, (int) config('whatsapp.flows.booking.duration_minutes', 60));
    }

    /**
     * Ventanas libres → turnos de `duration()` en horas en punto, solo futuros.
     * (Una ventana que arranca 09:30 da 10:00, 11:00…)
     *
     * @return array<string, array<int, string>>
     */
    protected function hourly(array $schedule): array
    {
        $tz = config('app.timezone');
        $now = now($tz);
        $duration = $this->duration();
        $out = [];

        foreach ($schedule as $day) {
            $times = [];

            foreach ($day['slots'] ?? [] as $window) {
                $start = Carbon::parse($day['date'].' '.$window['start_time'], $tz);
                $end = Carbon::parse($day['date'].' '.$window['end_time'], $tz);

                $cursor = $start->copy()->minute(0)->second(0);

                if ($cursor->lt($start)) {
                    $cursor->addHour();
                }

                for (; $cursor->copy()->addMinutes($duration)->lte($end); $cursor->addMinutes($duration)) {
                    if ($cursor->gt($now)) {
                        $times[] = $cursor->format('H:i');
                    }
                }
            }

            if ($times) {
                $out[$day['date']] = array_values(array_unique($times));
            }
        }

        ksort($out);

        return $out;
    }

    protected function key(int $specialtyId, string $from, int $days): string
    {
        return "booking:avail:{$specialtyId}:{$from}:{$days}";
    }

    /** Índice de respuestas guardadas, para takeSlot(). */
    protected function remember(string $key): void
    {
        $keys = array_filter(Cache::get('booking:avail:keys', []), fn ($until) => $until > time());
        $keys[$key] = time() + self::TTL_MINUTES * 60;
        Cache::put('booking:avail:keys', $keys, now()->addMinutes(self::TTL_MINUTES));
    }

    protected function today(): Carbon
    {
        return now(config('app.timezone'))->startOfDay();
    }

    /**
     * Activos, vinculados a SMD y con turnos cargados en la ventana: sin turno
     * local la disponibilidad siempre da vacío.
     *
     * @return array<int, int>
     */
    protected function bookableDoctorIds(Carbon $from, int $days): array
    {
        return DB::table('doctors')
            ->where('is_active', 1)
            ->whereNotNull('unique_id')
            ->where('unique_id', '!=', '')
            ->whereIn('id', DB::table('doctor_shifts')
                ->whereBetween('date', [$from->toDateString(), $from->copy()->addDays($days - 1)->toDateString()])
                ->select('doctor_id'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
