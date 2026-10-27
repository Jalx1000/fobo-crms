<?php

namespace Webkul\Whatsapp\Services\Flows;

use Webkul\Whatsapp\Models\MessageProxy;

/**
 * Pre-llenado del formulario de agendar con lo que el paciente ya dijo en el
 * chat (lo manda el agente al pedir el formulario).
 *
 * Se guarda en el mensaje del formulario (no en FlowSession, que vence a las 2
 * horas) y solo se usa como valor INICIAL de una pantalla cuando coincide con
 * una opción real: un dato inexacto se ignora y el formulario abre igual.
 */
class FlowPrefill
{
    /** Valores aceptados para `relation`: el paciente mismo o un tercero. */
    protected const RELATION_ALIASES = [
        'self'    => 'self', 'para_mi' => 'self', 'yo' => 'self', 'propio' => 'self',
        'tercero' => 'tercero', 'other' => 'tercero', 'otro' => 'tercero', 'otra_persona' => 'tercero',
    ];

    /** Lo que el paciente dice cuando no sabe qué especialidad necesita. */
    protected const GENERAL_ALIASES = ['general', 'no se', 'consulta general', 'odontologia general', 'consulta', 'revision'];

    /**
     * Solo las claves conocidas, como texto recortado. Cualquier otra cosa
     * (claves de más, tipos raros, valores vacíos) se descarta sin error.
     *
     * @return array{relation?: string, relationship?: string, patient_name?: string, specialty?: string, doctor?: string, date?: string, time?: string}
     */
    public static function sanitize(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $text = fn ($v, int $max) => is_scalar($v) && trim((string) $v) !== '' ? mb_substr(trim((string) $v), 0, $max) : null;

        $clean = [];

        $relation = self::RELATION_ALIASES[self::normalize((string) ($text($raw['relation'] ?? null, 20) ?? ''), '_')] ?? null;
        if ($relation) {
            $clean['relation'] = $relation;
        }

        $relationship = $text($raw['relationship'] ?? null, 30);
        if ($relationship !== null && in_array($relationship, array_column(PatientFlow::RELATIONS, 'id'), true)) {
            $clean['relationship'] = $relationship;
        }

        if ($name = $text($raw['patient_name'] ?? null, 120)) {
            $clean['patient_name'] = $name;
        }

        if ($specialty = $text($raw['specialty'] ?? null, 100)) {
            $clean['specialty'] = $specialty;
        }

        // Doctor: id del CRM o nombre ("Dr. Eloy").
        if ($doctor = $text($raw['doctor'] ?? null, 100)) {
            $clean['doctor'] = $doctor;
        }

        // Fecha YYYY-MM-DD (una fecha inexistente se descarta).
        $date = $text($raw['date'] ?? null, 10);
        if ($date !== null && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $clean['date'] = $date;
        }

        // Hora HH:MM (acepta H:MM).
        $time = $text($raw['time'] ?? null, 5);
        if ($time !== null && preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $m)) {
            $clean['time'] = sprintf('%02d:%s', $m[1], $m[2]);
        }

        return $clean;
    }

    /** El prefill guardado en el mensaje del formulario de este token. */
    public static function forToken(string $token): array
    {
        $conversationId = FlowToken::conversationId($token);

        if (! $conversationId) {
            return [];
        }

        return cache()->remember('whatsapp:flow:prefill:'.md5($token), now()->addDay(), function () use ($conversationId, $token) {
            $payload = MessageProxy::modelClass()::query()
                ->where('conversation_id', $conversationId)
                ->where('type', 'flow')
                ->latest('id')
                ->get(['payload'])
                ->map(fn ($m) => (array) $m->payload)
                ->first(fn ($p) => ($p['flow']['token'] ?? null) === $token);

            return (array) ($payload['flow']['prefill'] ?? []);
        });
    }

    /**
     * Opción inicial de "¿Para quién es la cita?". Un tercero que ya es paciente
     * a cargo (mismo nombre) se preselecciona directo; si no, "Otra persona".
     *
     * @param  array<int, array{id: string, title: string}>  $options
     */
    public static function choice(array $prefill, array $options): string
    {
        $ids = array_column($options, 'id');

        if (($prefill['relation'] ?? null) === 'self') {
            return in_array('self', $ids, true) ? 'self' : '';
        }

        if (($prefill['relation'] ?? null) !== 'tercero') {
            return '';
        }

        if (! empty($prefill['patient_name'])) {
            $wanted = self::normalize($prefill['patient_name']);

            foreach ($options as $o) {
                if (str_starts_with($o['id'], 'p:') && self::normalize($o['title']) === $wanted) {
                    return $o['id'];
                }
            }
        }

        return in_array('other', $ids, true) ? 'other' : '';
    }

    /**
     * Especialidad inicial: por id de opción, por nombre (sin tildes ni
     * mayúsculas) o "general" si el paciente no sabe qué necesita.
     *
     * @param  array<int, array{id: string, title: string}>  $options
     */
    public static function specialty(array $prefill, array $options): string
    {
        $value = (string) ($prefill['specialty'] ?? '');

        if ($value === '') {
            return '';
        }

        $ids = array_column($options, 'id');

        if (in_array($value, $ids, true)) {
            return $value;
        }

        $wanted = self::normalize($value);

        foreach ($options as $o) {
            if (self::normalize($o['title']) === $wanted) {
                return $o['id'];
            }
        }

        return in_array($wanted, self::GENERAL_ALIASES, true) && in_array('general', $ids, true) ? 'general' : '';
    }

    /**
     * Doctor a preseleccionar entre los ofrecidos: por id o por nombre. Por
     * nombre, todas las palabras pedidas (sin "Dr."/"Dra.") tienen que estar en
     * el nombre de UN solo doctor; con dos candidatos no se adivina.
     *
     * @param  array<int|string, string>  $doctors  id => nombre
     */
    public static function doctorId(array $prefill, array $doctors): ?int
    {
        $value = (string) ($prefill['doctor'] ?? '');

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value) && array_key_exists((int) $value, $doctors)) {
            return (int) $value;
        }

        $titles = ['dr', 'dra', 'doctor', 'doctora', 'od', 'lic'];
        $words = array_values(array_filter(
            preg_split('/[\s.]+/', self::normalize($value)),
            fn ($w) => mb_strlen($w) >= 3 && ! in_array($w, $titles, true)
        ));

        if (! $words) {
            return null;
        }

        $matches = array_keys(array_filter($doctors, function ($name) use ($words) {
            $have = preg_split('/\s+/', self::normalize((string) $name));

            return ! array_diff($words, $have);
        }));

        return count($matches) === 1 ? (int) $matches[0] : null;
    }

    /** ¿El agente mandó una preferencia de agenda (doctor, día u hora)? */
    public static function wantsSchedule(array $prefill): bool
    {
        return isset($prefill['doctor']) || isset($prefill['date']) || isset($prefill['time']);
    }

    /**
     * Día a preseleccionar de la lista: el pedido si tiene horarios; si no, el
     * siguiente con horarios; "other" si el pedido está más allá de la lista;
     * sin día pedido pero con preferencia de agenda, el más próximo (R9).
     *
     * @param  array<int, string>  $dates  días ofrecidos (YYYY-MM-DD), en orden
     */
    public static function date(array $prefill, array $dates, ?string $maxDate = null): string
    {
        $wanted = $prefill['date'] ?? null;

        if ($wanted === null) {
            return self::wantsSchedule($prefill) ? (string) ($dates[0] ?? '') : '';
        }

        if (in_array($wanted, $dates, true)) {
            return $wanted;
        }

        foreach ($dates as $d) {
            if ($d > $wanted) {
                return $d;
            }
        }

        // Más allá de los días de la lista, pero dentro de lo que se puede pedir.
        return $dates && $wanted > end($dates) && ($maxDate === null || $wanted <= $maxDate) ? 'other' : '';
    }

    /**
     * Hora a preseleccionar: la pedida si está libre; si no, la libre más
     * cercana; sin hora pedida pero con preferencia de agenda, la primera (R9).
     *
     * @param  array<int, string>  $times  horarios libres (HH:MM), en orden
     */
    public static function time(array $prefill, array $times): string
    {
        if (! $times) {
            return '';
        }

        $wanted = $prefill['time'] ?? null;

        if ($wanted === null) {
            return self::wantsSchedule($prefill) ? $times[0] : '';
        }

        if (in_array($wanted, $times, true)) {
            return $wanted;
        }

        $minutes = fn (string $t) => ((int) substr($t, 0, 2)) * 60 + (int) substr($t, 3, 2);
        $best = $times[0];

        foreach ($times as $t) {
            if (abs($minutes($t) - $minutes($wanted)) < abs($minutes($best) - $minutes($wanted))) {
                $best = $t;
            }
        }

        return $best;
    }

    public static function normalize(string $text, string $space = ' '): string
    {
        $text = iconv('UTF-8', 'ASCII//TRANSLIT', trim($text)) ?: $text;

        return mb_strtolower(preg_replace('/[\s_]+/', $space, $text));
    }
}
