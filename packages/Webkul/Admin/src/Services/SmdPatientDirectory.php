<?php

namespace Webkul\Admin\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ficha del paciente en SMD, cacheada, como respaldo del teléfono.
 *
 * Medido sobre 400 payloads al azar: 212 traen un asistente de tipo paciente y
 * 208 de ésos incluyen el teléfono, así que el calendario alcanza en la gran
 * mayoría de los casos. Esto cubre el resto —los payloads UPDATED deshidratados,
 * donde `attendances[].phone` llega vacío— consultando `GET /patients/{_id}`,
 * que es exacta porque va por id y no por búsqueda.
 *
 * Sin este respaldo esas citas crean un contacto sin número, y ese contacto
 * nunca recibe el recordatorio de WhatsApp.
 *
 * El caché es lo que lo hace viable en una carga masiva: un mismo paciente
 * aparece en decenas de eventos, y sin caché serían decenas de llamadas HTTP
 * para el mismo dato.
 */
class SmdPatientDirectory
{
    /** Memo por request, encima del caché persistente. */
    protected array $memo = [];

    public function __construct(protected ShareMeDataService $smd) {}

    /**
     * La ficha de SMD de un paciente, o null si no se pudo obtener.
     */
    public function patient(?string $smdId): ?array
    {
        if (! $smdId) {
            return null;
        }

        if (array_key_exists($smdId, $this->memo)) {
            return $this->memo[$smdId];
        }

        $ttl = (int) config('smd.patient_cache_hours', 168);

        try {
            $patient = Cache::remember(
                'smd:patient:'.$smdId,
                now()->addHours(max(1, $ttl)),
                fn () => $this->smd->getPatient($smdId),
            );
        } catch (Throwable $e) {
            Log::warning('[SMD] No se pudo obtener la ficha del paciente', [
                'smd_patient_id' => $smdId,
                'error'          => $e->getMessage(),
            ]);

            $patient = null;
        }

        return $this->memo[$smdId] = $patient;
    }

    /**
     * Precarga en paralelo las fichas de un lote de pacientes.
     *
     * Lo llama el sync antes de procesar cada lote de archivos: así las fichas
     * se piden todas juntas en vez de una por cita, que era lo que hacía durar
     * minutos una carga completa. Después `patient()` las encuentra en memoria.
     *
     * @param  array<int, string>  $ids
     */
    public function warm(array $ids): void
    {
        $pendientes = array_values(array_filter(
            array_unique(array_filter($ids)),
            fn ($id) => ! array_key_exists($id, $this->memo),
        ));

        if (! $pendientes) {
            return;
        }

        $ttl = now()->addHours(max(1, (int) config('smd.patient_cache_hours', 168)));
        $concurrency = max(1, (int) config('smd.dropbox.concurrency', 10));

        try {
            $fichas = $this->smd->getPatientsMany($pendientes, $concurrency);
        } catch (Throwable $e) {
            Log::warning('[SMD] Falló la precarga de pacientes', ['error' => $e->getMessage()]);

            return;
        }

        foreach ($fichas as $id => $ficha) {
            $this->memo[$id] = $ficha;
            Cache::put('smd:patient:'.$id, $ficha, $ttl);
        }
    }

    /**
     * La ficha real del paciente, buscándola en cascada.
     *
     * El `_id` que trae el asistente del calendario NO siempre es el `_id` de la
     * ficha de paciente. Verificado: Emilia Montes llega como
     * `69d678fa5fc8a300086fdc41` y su ficha real es `6a1090842b1533000885f9b6`.
     * Consultar sólo por id daba "no existe en SMD" para pacientes que sí están,
     * y esos entraban al CRM sin teléfono para siempre.
     *
     * Por eso: primero por id, y si no aparece, por nombre.
     *
     * @return array|null la ficha, con su `_id` REAL (el que sirve para volver a
     *                    consultarla, no el del calendario)
     */
    public function resolve(?string $smdId, ?string $name = null): ?array
    {
        if ($patient = $this->patient($smdId)) {
            return $patient;
        }

        if (! $name || ! trim($name)) {
            return null;
        }

        $clave = 'nombre:'.mb_strtolower(trim($name));

        if (array_key_exists($clave, $this->memo)) {
            return $this->memo[$clave];
        }

        try {
            $encontrados = $this->smd->searchPatientByName($name);
        } catch (Throwable $e) {
            Log::warning('[SMD] Falló la búsqueda de paciente por nombre', [
                'nombre' => $name,
                'error'  => $e->getMessage(),
            ]);

            return $this->memo[$clave] = null;
        }

        // Sólo si es inequívoca. Con varias coincidencias elegir sería adivinar
        // de quién es la cita, y eso ya nos costó cruzar identidades antes.
        if (count($encontrados) !== 1) {
            if (count($encontrados) > 1) {
                Log::info('[SMD] El nombre coincide con varios pacientes, no se resuelve', [
                    'nombre'      => $name,
                    'candidatos'  => count($encontrados),
                ]);
            }

            return $this->memo[$clave] = null;
        }

        $patient = $encontrados[0];

        Log::info('[SMD] Paciente resuelto por nombre', [
            'nombre'          => $name,
            'id_calendario'   => $smdId,
            'id_ficha_real'   => $patient['_id'] ?? null,
        ]);

        return $this->memo[$clave] = $patient;
    }

    /**
     * ¿Es un paciente ficticio de SMD y no una persona real?
     *
     * SMD tapa los horarios en que un médico no atiende con un "paciente"
     * inventado —"Ocupado Ocupado", sin teléfono y sin CI—. Como el evento SÍ
     * trae asistente, el corte por "evento sin paciente" no lo agarra y termina
     * creando persona, lead y cita. Es la misma basura que los 409 registros
     * "Paciente" que veníamos arrastrando.
     */
    public function isDummy(?array $patient, ?string $name = null): bool
    {
        $nombre = mb_strtolower(trim(
            $patient['fullName']
            ?? trim(($patient['name'] ?? '').' '.($patient['lastName'] ?? ''))
            ?: (string) $name
        ));

        if ($nombre === '') {
            return false;
        }

        foreach ((array) config('smd.dummy_patients', []) as $patron) {
            if (str_contains($nombre, mb_strtolower($patron))) {
                return true;
            }
        }

        return false;
    }

    /**
     * El teléfono del paciente según SMD, en dígitos y sin separadores.
     *
     * SMD guarda indistintamente "78164851" y "59178164851"; se devuelve tal
     * cual viene y la normalización queda en manos de quien lo escribe, que es
     * el que sabe con qué formato tiene que comparar.
     */
    public function phone(?string $smdId): ?string
    {
        $patient = $this->patient($smdId);

        $phone = $patient['phone'] ?? null;

        if (is_array($phone)) {
            $phone = $phone[0] ?? null;
        }

        $digits = preg_replace('/\D/', '', (string) $phone);

        return $digits !== '' ? $digits : null;
    }
}
