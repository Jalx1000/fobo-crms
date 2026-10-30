<?php

namespace Webkul\Whatsapp\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Webkul\Lead\Models\Lead;
use Webkul\Whatsapp\Jobs\SendMessage;
use Webkul\Whatsapp\Models\Conversation;
use Webkul\Whatsapp\Models\MessageProxy;
use Webkul\Whatsapp\Services\EvalSandbox;

/**
 * Deja listo el entorno de evals del agente: un contacto con su conversación
 * por cada whatsapp.eval.contacts y, para cada uno, una cita de mañana con el
 * recordatorio enviado y sin contestar.
 *
 * Cada corrida borra antes lo que sembró la anterior (citas, leads, notas y
 * recordatorios marcados eval_seed, y el historial de las conversaciones
 * sandbox), así se puede re-sembrar después de que los evals lo consuman.
 * Las citas son solo locales: no se crean en SMD.
 */
class EvalSeed extends Command
{
    protected $signature = 'whatsapp:eval-seed
        {--clean : Solo borra lo sembrado, sin volver a sembrar}';

    protected $description = 'Siembra contactos, citas y recordatorios pendientes para los evals del agente';

    public function handle(): int
    {
        if (! EvalSandbox::allowed()) {
            $this->error('Evals deshabilitado: hace falta WHATSAPP_EVAL_ENABLED=true y una base que no sea la de producción.');

            return self::FAILURE;
        }

        $contacts = array_map(fn ($c) => $this->contact($c), (array) config('whatsapp.eval.contacts', []));

        $this->clean($contacts);

        if ($this->option('clean')) {
            $this->info('Limpieza de evals terminada.');

            return self::SUCCESS;
        }

        $day = Carbon::tomorrow(config('app.timezone'));
        $rows = [];

        foreach ($contacts as $i => $contact) {
            $from = $day->copy()->setTime(7, 0)->addMinutes(30 * $i);
            $appointment = $this->appointment($contact['person_id'], $from);
            $this->remind($contact['conversation'], $appointment['activity_id'], $from);

            $rows[] = [
                $contact['conversation']->wa_phone,
                $contact['conversation']->id,
                $contact['person_id'],
                $appointment['activity_id'],
                $appointment['lead_id'],
                $from->format('d/m H:i'),
                $contact['sandbox'] ? 'sandbox (no sale)' : 'real',
            ];

            // Un "Sí" sin activity_id solo funciona con UN recordatorio pendiente.
            $others = DB::table('whatsapp_appointment_reminders')
                ->where('conversation_id', $contact['conversation']->id)
                ->where('activity_id', '!=', $appointment['activity_id'])
                ->whereNull('response')
                ->pluck('activity_id');

            if ($others->isNotEmpty()) {
                $this->warn("  La conversación {$contact['conversation']->id} tiene además recordatorios sin contestar (fuera de evals): ".$others->implode(', '));
            }
        }

        $this->table(['wa_id', 'conversation_id', 'person_id', 'activity_id', 'lead_id', 'cita', 'canal'], $rows);

        return self::SUCCESS;
    }

    /**
     * El contacto y su conversación. Los sandbox se crean si faltan (sin pasar
     * por SMD); el real se busca por teléfono y tiene que existir.
     *
     * @return array{conversation: Conversation, person_id: int, sandbox: bool}
     */
    protected function contact(array $config): array
    {
        $phone = (string) $config['phone'];
        $sandbox = (bool) ($config['sandbox'] ?? false);
        $conversation = Conversation::where('wa_phone', $phone)->orderBy('id')->first();

        if (! $sandbox) {
            if (! $conversation?->person_id) {
                throw new \RuntimeException("El número real {$phone} no tiene conversación con persona en el CRM.");
            }

            return ['conversation' => $conversation, 'person_id' => (int) $conversation->person_id, 'sandbox' => false];
        }

        // Nunca convertir en sandbox (y vaciar) una conversación real.
        if ($conversation && $conversation->gateway !== 'sandbox') {
            throw new \RuntimeException("El número {$phone} ya es una conversación real ({$conversation->gateway}): no se usa como sandbox.");
        }

        $personId = $conversation?->person_id ?: DB::table('persons')->insertGetId([
            'name'            => $config['name'],
            'emails'          => json_encode([]),
            'contact_numbers' => json_encode([['label' => 'work', 'value' => $phone]]),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $conversation ??= Conversation::create(['wa_phone' => $phone, 'status' => 'open']);
        $conversation->forceFill([
            'gateway'         => 'sandbox',
            'wa_name'         => $config['name'],
            'person_id'       => $personId,
            'ai_enabled'      => true,
            'last_inbound_at' => now(),
        ])->save();

        return ['conversation' => $conversation, 'person_id' => (int) $personId, 'sandbox' => true];
    }

    /**
     * Borra lo sembrado antes: las citas eval_seed con sus leads y todo lo que
     * cuelga de ellos (notas de la respuesta incluidas), y el historial de las
     * conversaciones sandbox.
     */
    protected function clean(array $contacts): void
    {
        $activityIds = DB::table('activities')->where('additional', 'like', '%"eval_seed": true%')
            ->orWhere('additional', 'like', '%"eval_seed":true%')
            ->pluck('id');
        $leadIds = DB::table('lead_activities')->whereIn('activity_id', $activityIds)->pluck('lead_id')->unique();
        $allIds = $activityIds->merge(DB::table('lead_activities')->whereIn('lead_id', $leadIds)->pluck('activity_id'))->unique();

        $messageIds = DB::table('whatsapp_appointment_reminders')->whereIn('activity_id', $activityIds)->pluck('message_id')->filter();
        DB::table('whatsapp_appointment_reminders')->whereIn('activity_id', $activityIds)->delete();
        DB::table('whatsapp_messages')->whereIn('id', $messageIds)->delete();

        DB::table('lead_activities')->whereIn('lead_id', $leadIds)->delete();
        DB::table('activity_participants')->whereIn('activity_id', $allIds)->delete();
        DB::table('doctor_activities')->whereIn('activity_id', $allIds)->delete();
        DB::table('activities')->whereIn('id', $allIds)->delete();
        DB::table('leads')->whereIn('id', $leadIds)->delete();

        $sandboxConversations = collect($contacts)->where('sandbox', true)->map(fn ($c) => $c['conversation']->id);
        DB::table('whatsapp_messages')->whereIn('conversation_id', $sandboxConversations)->delete();
    }

    /**
     * @return array{activity_id: int, lead_id: int}
     */
    protected function appointment(int $personId, Carbon $from): array
    {
        $activityId = DB::table('activities')->insertGetId([
            'type'          => 'meeting',
            'title'         => '[EVAL] Cita de prueba',
            'comment'       => 'Cita sembrada para los evals del agente (whatsapp:eval-seed).',
            'schedule_from' => $from->format('Y-m-d H:i:s'),
            'schedule_to'   => $from->copy()->addMinutes(30)->format('Y-m-d H:i:s'),
            'is_done'       => 0,
            'user_id'       => 1,
            'additional'    => json_encode(['eval_seed' => true]),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        DB::table('doctor_activities')->insert(['doctor_id' => (int) config('whatsapp.eval.doctor_id'), 'activity_id' => $activityId]);
        DB::table('activity_participants')->insert(['person_id' => $personId, 'activity_id' => $activityId]);

        // Krayin le agrega sus propias actividades al lead: por eso la limpieza
        // borra todo lo colgado de él, no solo la cita.
        $lead = Lead::create(['title' => '[EVAL] Cita de prueba', 'description' => 'eval', 'entity_type' => 'leads']);
        DB::table('leads')->where('id', $lead->id)->update([
            'person_id'              => $personId,
            'lead_pipeline_stage_id' => (int) config('smd.stage_map.default'),
        ]);
        DB::table('lead_activities')->insert(['lead_id' => $lead->id, 'activity_id' => $activityId]);

        return ['activity_id' => $activityId, 'lead_id' => (int) $lead->id];
    }

    /**
     * El recordatorio, igual que lo manda whatsapp:appointment-reminders: el
     * mensaje de plantilla en el historial y la fila sin respuesta. Al número
     * real le llega de verdad; al sandbox no.
     */
    protected function remind(Conversation $conversation, int $activityId, Carbon $from): void
    {
        $doctor = (string) DB::table('doctors')->where('id', config('whatsapp.eval.doctor_id'))->value('name');

        $message = MessageProxy::modelClass()::create([
            'conversation_id' => $conversation->id,
            'direction'       => 'outbound',
            'type'            => 'template',
            'body'            => "[EVAL] Le recordamos su cita del {$from->format('d/m')} a horas {$from->format('H:i')} con el Dr(a). {$doctor}."
                ."\n\nResponda *SÍ* para confirmar o *NO* para cancelar su cita.",
            'status'          => 'queued',
            'sender'          => 'system',
            'payload'         => [
                'template'  => config('whatsapp.reminders.template_key', 'appointment_reminder'),
                'variables' => [$from->format('d/m').' a horas '.$from->format('H:i'), $doctor],
            ],
        ]);

        $conversation->forceFill(['last_message_at' => now()])->save();

        SendMessage::dispatch($message->id, config('whatsapp.reminders.gateway'))->onQueue(config('whatsapp.queue'));

        DB::table('whatsapp_appointment_reminders')->insert([
            'activity_id'     => $activityId,
            'conversation_id' => $conversation->id,
            'message_id'      => $message->id,
            'sent_at'         => now(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }
}
