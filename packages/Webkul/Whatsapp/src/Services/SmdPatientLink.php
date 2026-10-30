<?php

namespace Webkul\Whatsapp\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Contact\Models\PersonProxy;
use Webkul\Contact\Repositories\PersonRepository;

/**
 * Puente entre un número de WhatsApp y la ficha del paciente en ShareMeData.
 *
 * Cuando escribe un número que el CRM no conoce, SMD suele tener a esa persona
 * con su nombre real, CI y fecha de nacimiento. Traer eso evita que el contacto
 * quede registrado como "59176616013" y deja los dos sistemas apuntando al mismo
 * paciente mediante `persons.smd_patient_id`.
 *
 * El punto delicado es que UN TELÉFONO NO IDENTIFICA A UN PACIENTE. Sobre una
 * muestra de 40 conversaciones reales, 20 números devolvieron 2 o más pacientes
 * distintos en SMD -hermanos, familias que comparten celular, o directamente
 * personas sin relación con CI distinto- y uno devolvió 10. Por eso solo se
 * vincula cuando la coincidencia es ÚNICA: elegir uno entre varios significaría
 * pegarle a un paciente el historial clínico de otro. Con varios candidatos el
 * contacto se crea igual, sin vincular, y quedan registrados para que una
 * persona (o el agente, preguntando el nombre) desempate.
 */
class SmdPatientLink
{
    /**
     * Campos que SMD devuelve y el CRM sabe guardar.
     */
    protected const SELECT = '_id,fullName,phone,secondEmail,name,lastName,personID,birthday';

    /**
     * Busca en SMD el paciente detrás de un número.
     *
     * @return array{status: string, patient: ?array, candidates: array}
     *                                                                   status: unique | ambiguous | none | unavailable
     */
    public function lookup(?string $phone, ?string $profileName = null): array
    {
        $none = ['status' => 'none', 'patient' => null, 'candidates' => []];

        if (! config('whatsapp.smd_link.enabled') || ! $phone) {
            return ['status' => 'unavailable', 'patient' => null, 'candidates' => []];
        }

        // El CRM guarda tanto "78164851" como "59178164851", y SMD también mezcla
        // los dos formatos. Se busca por los últimos 8 dígitos, que es lo único
        // estable entre ambos.
        $tail = PhoneNumber::tail($phone);

        if (strlen($tail) < 8) {
            return $none;
        }

        try {
            $smd = app(\Webkul\Admin\Services\ShareMeDataService::class);

            // OrNull: un fallo de SMD NO es "no existe". Antes se confundían y el
            // contacto quedaba sin datos sin rastro de por qué.
            $found = $smd->searchPatientOrNull($tail);
        } catch (Throwable $e) {
            $found = null;
        }

        if ($found === null) {
            Log::warning('WhatsApp: SMD no respondió al buscar el número entrante; se reintenta en el próximo mensaje', [
                'phone' => $phone,
            ]);

            return ['status' => 'unavailable', 'patient' => null, 'candidates' => []];
        }

        // La búsqueda de SMD es un regex sobre el campo teléfono, así que puede
        // devolver un número que apenas CONTIENE esos dígitos. Se confirma que
        // realmente termine en ellos.
        $matches = array_values(array_filter($found, function ($patient) use ($tail) {
            foreach ((array) ($patient['phone'] ?? []) as $number) {
                if (str_ends_with(PhoneNumber::digits((string) $number), $tail)) {
                    return true;
                }
            }

            return false;
        }));

        if (! $matches) {
            Log::info('WhatsApp: el número no está en SMD', ['phone' => $phone]);

            return $none;
        }

        if (count($matches) === 1) {
            return ['status' => 'unique', 'patient' => $matches[0], 'candidates' => $matches];
        }

        // Varios pacientes con el mismo número (familias que comparten celular):
        // el nombre del perfil de WhatsApp desempata, pero SOLO si coincide con
        // uno. Caso real: "Indira" entre "JORGE AUZA PANTOJA" y "LIDIA INDIRA
        // PANTOJA SOLARES".
        $byName = $this->matchByProfileName($matches, $profileName);

        if ($byName) {
            Log::info('WhatsApp: número con varios pacientes en SMD, desempatado por el nombre del perfil', [
                'phone'          => $phone,
                'perfil'         => $profileName,
                'smd_patient_id' => $byName['_id'] ?? null,
            ]);

            return ['status' => 'unique', 'patient' => $byName, 'candidates' => $matches];
        }

        return ['status' => 'ambiguous', 'patient' => null, 'candidates' => $matches];
    }

    /**
     * El único candidato cuyo nombre contiene TODAS las palabras del nombre del
     * perfil de WhatsApp (sin acentos, mayúsculas ni el sufijo "(SISTEMA 2024)"
     * que arrastra SMD). Null si el perfil no tiene nombre útil o si coinciden
     * cero o varios: con más de uno elegir sería adivinar.
     */
    protected function matchByProfileName(array $candidates, ?string $profileName): ?array
    {
        $tokens = array_values(array_filter(
            explode(' ', $this->normalizeName($profileName)),
            fn ($t) => strlen($t) >= 3
        ));

        if (! $tokens) {
            return null;
        }

        $hits = array_values(array_filter($candidates, function ($patient) use ($tokens) {
            $name = $patient['fullName'] ?? trim(($patient['name'] ?? '').' '.($patient['lastName'] ?? ''));

            return ! array_diff($tokens, explode(' ', $this->normalizeName($name)));
        }));

        return count($hits) === 1 ? $hits[0] : null;
    }

    protected function normalizeName(?string $value): string
    {
        $value = explode(' (', (string) $value)[0];
        $value = iconv('UTF-8', 'ASCII//TRANSLIT', $value) ?: $value;

        return preg_replace('/\s+/', ' ', trim(strtolower(preg_replace('/[^a-zA-Z ]/', '', $value))));
    }

    /**
     * Los datos de un paciente de SMD traducidos a campos del CRM.
     *
     * Solo devuelve lo que SMD tiene: un valor vacío no debe pisar algo que ya
     * esté cargado en el CRM.
     *
     * @return array<string, mixed>
     */
    public function attributesFrom(array $patient): array
    {
        $name = trim((string) ($patient['fullName'] ?? ''))
            ?: trim(($patient['name'] ?? '').' '.($patient['lastName'] ?? ''));

        return array_filter([
            'name'             => $name ?: null,
            'smd_patient_id'   => $patient['_id'] ?? null,
            'ci_paciente'      => $patient['personID'] ?? null,
            'fecha_nacimiento' => ! empty($patient['birthday'])
                ? substr((string) $patient['birthday'], 0, 10)
                : null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * El contacto del CRM para un paciente de SMD: el que ya existe vinculado, o
     * uno nuevo.
     *
     * Buscar primero por `smd_patient_id` es lo que evita duplicar. Muchísimos
     * pacientes están en el CRM sin teléfono -vinieron de la sincronización del
     * calendario, que no trae el número-, así que el match por teléfono no los
     * encuentra y crear uno nuevo dejaría dos fichas del mismo paciente. Si ya
     * existe, lo que hacemos es justamente lo que faltaba: cargarle el número.
     *
     * @param  string  $digits  el número tal como se guarda en contact_numbers
     */
    public function resolveOrCreate(array $patient, string $digits): ?Model
    {
        $smdId = $patient['_id'] ?? null;

        $existing = $smdId
            ? PersonProxy::modelClass()::where('smd_patient_id', $smdId)->first()
            : null;

        if ($existing) {
            $this->attachPhone($existing, $digits);

            return $existing;
        }

        return $this->createPersonFrom($patient, $digits);
    }

    /**
     * Agrega el número a un contacto que no lo tenía, sin tocar los que ya están.
     */
    protected function attachPhone(Model $person, string $digits): void
    {
        $numbers = (array) $person->contact_numbers;
        $tail = PhoneNumber::tail($digits);

        foreach ($numbers as $number) {
            $value = is_array($number) ? ($number['value'] ?? '') : $number;

            if (str_ends_with(PhoneNumber::digits((string) $value), $tail)) {
                return;
            }
        }

        $numbers[] = ['label' => 'work', 'value' => $digits];

        $person->forceFill(['contact_numbers' => array_values($numbers)])->save();

        Log::info('WhatsApp: número agregado al paciente ya vinculado con SMD', [
            'person_id'      => $person->id,
            'smd_patient_id' => $person->smd_patient_id,
            'phone'          => $digits,
        ]);
    }

    /**
     * Crea el contacto en el CRM a partir de la ficha de SMD.
     *
     * @param  string  $digits  el número tal como se guarda en contact_numbers
     */
    public function createPersonFrom(array $patient, string $digits): ?Model
    {
        $data = $this->attributesFrom($patient);

        $email = trim((string) ($patient['secondEmail'] ?? ''));

        try {
            $person = app(PersonRepository::class)->create(array_merge($data, [
                'entity_type'     => 'persons',
                'name'            => $data['name'] ?? $digits,
                'emails'          => $email ? [['label' => 'work', 'value' => $email]] : [],
                'contact_numbers' => [['label' => 'work', 'value' => $digits]],
            ]));

            Log::info('WhatsApp: contacto creado desde la ficha de SMD', [
                'person_id'      => $person->id,
                'smd_patient_id' => $data['smd_patient_id'] ?? null,
                'name'           => $person->name,
            ]);

            return $person;
        } catch (Throwable $e) {
            Log::warning('WhatsApp: no se pudo crear el contacto desde SMD', [
                'phone' => $digits,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Completa un contacto que ya existía en el CRM pero no estaba vinculado.
     *
     * Solo rellena huecos: el dato cargado a mano en el CRM manda sobre el de
     * SMD, porque recepción corrige ahí y no queremos deshacerle el trabajo. Lo
     * único que siempre se escribe es el vínculo (`smd_patient_id`), que es
     * justamente lo que faltaba.
     */
    public function enrich(Model $person, array $patient): void
    {
        if ($person->smd_patient_id) {
            return;
        }

        $data = $this->attributesFrom($patient);

        if (empty($data['smd_patient_id'])) {
            return;
        }

        // El nombre del CRM se respeta salvo que sea el número puesto como
        // nombre, que es lo que deja una alta automática sin datos.
        if (! empty($data['name']) && ! $this->nameIsPlaceholder($person->name)) {
            unset($data['name']);
        }

        try {
            app(PersonRepository::class)->update(array_merge($data, [
                'entity_type' => 'persons',
            ]), $person->id, array_keys(array_diff_key($data, array_flip(['name', 'smd_patient_id']))));

            Log::info('WhatsApp: contacto vinculado con SMD', [
                'person_id'      => $person->id,
                'smd_patient_id' => $data['smd_patient_id'],
            ]);
        } catch (Throwable $e) {
            Log::warning('WhatsApp: no se pudo vincular el contacto con SMD', [
                'person_id' => $person->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * ¿El nombre es sólo el número? Es lo que deja el alta automática cuando no
     * hay ningún otro dato, y sí conviene reemplazarlo por el real.
     */
    protected function nameIsPlaceholder(?string $name): bool
    {
        return (bool) preg_match('/^\+?\d[\d\s\-]*$/', (string) $name);
    }

    /**
     * Deja constancia de los candidatos cuando el número da más de un paciente.
     */
    public function logAmbiguous(string $digits, array $candidates): void
    {
        Log::info('WhatsApp: el número corresponde a varios pacientes en SMD, no se vincula', [
            'phone'      => $digits,
            'candidatos' => array_map(fn ($p) => [
                'smd_patient_id' => $p['_id'] ?? null,
                'nombre'         => $p['fullName'] ?? trim(($p['name'] ?? '').' '.($p['lastName'] ?? '')),
                'ci'             => $p['personID'] ?? null,
            ], $candidates),
        ]);
    }
}
