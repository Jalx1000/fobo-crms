<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Webkul\Admin\Services\ShareMeDataService;

/**
 * Diagnóstico paso a paso de la integración con ShareMeData, mostrando la
 * respuesta CRUDA de cada llamada (status + mensaje). Existe porque las pruebas
 * live solo dicen "falló": acá se ve el porqué.
 *
 * Solo lectura salvo --create-patient, que intenta el alta del paciente de
 * prueba con el mismo payload que usa el CRM.
 */
class SmdDiagnose extends Command
{
    protected $signature = 'smd:diagnose
        {--doctor= : Id del doctor en el CRM (default: SMD_LIVE_DOCTOR_ID)}
        {--phone= : Teléfono del paciente de prueba (default: SMD_LIVE_PATIENT_PHONE)}
        {--days=14 : Días hacia adelante para buscar huecos}
        {--create-patient : Intenta crear el paciente de prueba en SMD (ESCRIBE en producción)}';

    protected $description = 'Diagnóstico paso a paso contra ShareMeData con las respuestas crudas';

    public function handle(ShareMeDataService $smd): int
    {
        $tz = config('app.timezone', 'America/La_Paz');
        $apiKey = (string) config('services.sharemedata.api_key');

        $this->section('1. Configuración');
        $this->line('  base_url      '.config('services.sharemedata.base_url'));
        $this->line('  patients_url  '.config('services.sharemedata.patients_url'));
        $this->line('  api_key       '.($apiKey ? substr($apiKey, 0, 4).'…('.strlen($apiKey).' caracteres)' : 'FALTA'));
        $this->line('  sucursal      '.config('smd.default_subsidiary', 'Santa Cruz'));

        // ── 2. Doctor ─────────────────────────────────────────────────────────
        $this->section('2. Doctor en el CRM');
        $doctorId = (int) ($this->option('doctor') ?: env('SMD_LIVE_DOCTOR_ID'));
        $doctor = $doctorId ? DB::table('doctors')->find($doctorId) : null;

        if (! $doctor) {
            $this->error("  No existe el doctor {$doctorId}. Pasar --doctor=ID o definir SMD_LIVE_DOCTOR_ID.");

            return self::FAILURE;
        }

        $specialties = DB::table('doctor_specialty')
            ->join('specialties', 'specialties.id', '=', 'doctor_specialty.specialty_id')
            ->where('doctor_specialty.doctor_id', $doctor->id)
            ->pluck('specialties.name')->filter()->values()->all();

        $this->line("  id={$doctor->id}  nombre={$doctor->name}  activo=".($doctor->is_active ? 'sí' : 'NO'));
        $this->line('  unique_id     '.($doctor->unique_id ?: 'FALTA (no está vinculado a SMD)'));
        $this->line('  email         '.($doctor->email ?: '(vacío)'));
        $this->line('  especialidades CRM: '.($specialties ? implode(', ', $specialties) : '(ninguna)'));

        // ── 3. Catálogo de especialidades de SMD ──────────────────────────────
        $this->section('3. Especialidades en SMD');
        $catalog = (array) $smd->getSpecialties();
        $this->line('  '.$this->status($smd->getLastResponse()).'  '.count($catalog).' especialidades');
        $this->line('  '.implode(', ', array_filter($catalog, 'is_string')));

        $missing = array_diff($specialties, $catalog);
        if ($missing) {
            $this->warn('  ⚠ Especialidades del doctor que SMD NO tiene con ese nombre exacto: '.implode(', ', $missing));
        }

        // ── 4. Disponibilidad ────────────────────────────────────────────────
        $days = max(1, (int) $this->option('days'));
        $from = now($tz)->addDay()->startOfDay();
        $to = $from->copy()->addDays($days - 1)->endOfDay();

        $this->section("4. Disponibilidad {$from->toDateString()} → {$to->toDateString()}");

        if (! $doctor->unique_id) {
            $this->error('  Sin unique_id no se puede consultar.');
        } else {
            foreach ($specialties ?: array_filter($catalog, 'is_string') as $specialty) {
                $slots = $smd->checkAvailability(
                    $doctor->unique_id, $specialty, config('smd.default_subsidiary', 'Santa Cruz'),
                    $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s'),
                );

                $last = $smd->getLastResponse();
                $body = is_array($last['body'] ?? null) ? $last['body'] : [];
                $physicians = collect($body)->pluck('physician')->filter();
                $present = $physicians->contains(fn ($p) => ($p['_id'] ?? null) === $doctor->unique_id);
                $intervals = collect($slots)->flatMap(fn ($day) => collect($day)->flatten(1))->filter(fn ($iv) => ! empty($iv['start']));

                $this->line(sprintf('  %-28s %s  médicos=%d  este doctor=%s  huecos=%d%s',
                    $specialty, $this->status($last), $physicians->count(), $present ? 'presente' : 'AUSENTE',
                    $intervals->count(), $intervals->isNotEmpty() ? '  primero='.$intervals->first()['start'] : ''));

                if (! $present && $physicians->isNotEmpty()) {
                    $this->line('      médicos que sí devolvió: '.$physicians->map(fn ($p) => trim(($p['name'] ?? '').' '.($p['lastName'] ?? '')).' ['.($p['_id'] ?? '?').']')->take(8)->implode(', '));
                }

                if (! is_array($last['body'] ?? null)) {
                    $this->line('      respuesta: '.mb_substr((string) ($last['body'] ?? ''), 0, 300));
                }
            }
        }

        // ── 5. Paciente ──────────────────────────────────────────────────────
        $this->section('5. Paciente de prueba');
        $phone = preg_replace('/\D/', '', (string) ($this->option('phone') ?: env('SMD_LIVE_PATIENT_PHONE')));

        if (! $phone) {
            $this->warn('  Sin teléfono: pasar --phone o definir SMD_LIVE_PATIENT_PHONE.');

            return self::SUCCESS;
        }

        // Crudo, para ver el status: searchPatient() se traga los errores.
        app(\Webkul\Admin\Services\Smd\ApiBudget::class)->authorize('patients (diagnóstico)', 'SmdDiagnose::handle');
        $search = Http::withHeaders(['Accept' => 'application/json', 'apikey' => $apiKey])
            ->get(rtrim(config('services.sharemedata.patients_url'), '/').'/patients', [
                'where'      => 'phone=/'.$phone.'/',
                'select'     => '_id,fullName,phone,personID',
                'pageSize'   => 5,
                'pageNumber' => 1,
            ]);

        $found = $search->json('data') ?? [];
        $this->line("  búsqueda por teléfono {$phone}: HTTP {$search->status()}  encontrados=".count((array) $found));

        foreach ((array) $found as $p) {
            $this->line('    · '.($p['fullName'] ?? '?').'  _id='.($p['_id'] ?? '?').'  tel='.json_encode($p['phone'] ?? null));
        }

        if ($search->failed()) {
            $this->line('    respuesta: '.mb_substr($search->body(), 0, 300));
        }

        if (! $this->option('create-patient')) {
            $this->line('  (alta no probada: agregar --create-patient para intentarla)');

            return self::SUCCESS;
        }

        $this->warn('  Intentando el alta en SMD de PRODUCCIÓN con el payload del CRM…');
        $result = $smd->createPatient(['first_name' => 'PRUEBA', 'last_name' => 'CRM ODONTOKING', 'phone' => $phone]);

        $this->line('  payload enviado: '.json_encode([
            'name' => 'PRUEBA', 'lastName' => 'CRM ODONTOKING', 'phone' => $phone, 'nationality' => 'BO',
        ], JSON_UNESCAPED_UNICODE));
        $this->line('  resultado: '.($result['success'] ? 'OK  _id='.($result['data']['_id'] ?? '?') : 'FALLÓ'));

        if (! $result['success']) {
            $this->line('  status:    '.($result['status'] ?? 'sin respuesta (excepción)'));
            $this->line('  duplicado: '.(! empty($result['duplicate']) ? 'sí' : 'no'));
            $this->line('  mensaje:   '.(is_string($result['message']) ? $result['message'] : json_encode($result['message'], JSON_UNESCAPED_UNICODE)));
        }

        return self::SUCCESS;
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->info($title);
    }

    private function status(?array $last): string
    {
        return $last === null ? 'SIN RESPUESTA (excepción de red)' : 'HTTP '.($last['status'] ?? '?');
    }
}
