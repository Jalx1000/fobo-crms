<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Webkul\Whatsapp\Gateways\Dto\OutboundTemplate;
use Webkul\Whatsapp\Models\MessageProxy;
use Webkul\Whatsapp\Services\ConversationResolver;

/**
 * Registra en el inbox las 2 plantillas `seguimiento_solicitud` del 14/10/2026.
 *
 * Se mandaron directo contra Graph API, antes de que el módulo supiera enviar
 * plantillas, así que no quedaron en `whatsapp_messages`: el asesor veía la
 * respuesta del cliente («Sí, continuar») sin el mensaje que la provocó.
 *
 * Se guardan con su wamid real para que el hilo quede completo. Los avisos de
 * entregado/leído de Meta llegaron a las 17:53 UTC y se descartaron porque
 * nadie tenía ese wamid; no se vuelven a mandar, por eso quedan en «sent».
 *
 * Idempotente: si el wamid ya existe, no hace nada.
 */
return new class extends Migration
{
    protected array $envios = [
        ['phone' => '59176783881', 'nombre' => 'k',       'saludo' => 'k',    'wamid' => 'wamid.HBgLNTkxNzY3ODM4ODEVAgARGBI5NTU2MUIyOEM4N0E4NUZEOTUA'],
        ['phone' => '59170146583', 'nombre' => 'Moni R.', 'saludo' => 'Moni', 'wamid' => 'wamid.HBgLNTkxNzAxNDY1ODMVAgARGBJCRkEzNzk5NzgyRDkzNTNGODkA'],
    ];

    public function up(): void
    {
        $resolver = app(ConversationResolver::class);
        $enviadoEn = Carbon::parse('2026-10-14 17:53:30', 'UTC')->setTimezone(config('app.timezone'));

        foreach ($this->envios as $envio) {
            if (MessageProxy::modelClass()::where('wa_message_id', $envio['wamid'])->exists()) {
                continue;
            }

            $conversation = $resolver->findOrCreate($envio['phone'], $envio['nombre']);

            $template = OutboundTemplate::fromCatalog('seguimiento_solicitud', [$envio['saludo']]);

            $message = MessageProxy::modelClass()::make()->forceFill([
                'conversation_id' => $conversation->id,
                'direction'       => 'outbound',
                'type'            => 'template',
                'body'            => $template->rendered(),
                'payload'         => ['template' => $template->toArray()],
                'wa_message_id'   => $envio['wamid'],
                'status'          => 'sent',
                'sender'          => 'human',
                'created_at'      => $enviadoEn,
                'updated_at'      => $enviadoEn,
            ]);

            $message->save();

            // Solo adelanta `last_message_at`: si el cliente ya respondió, su
            // mensaje es más nuevo y la conversación debe seguir ordenada por él.
            if (! $conversation->last_message_at || $conversation->last_message_at->lt($enviadoEn)) {
                $conversation->forceFill(['last_message_at' => $enviadoEn])->save();
            }
        }
    }

    public function down(): void
    {
        MessageProxy::modelClass()::whereIn('wa_message_id', array_column($this->envios, 'wamid'))->delete();
    }
};
