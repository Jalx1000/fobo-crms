@component('mail::message')
# {{ $assigned ? 'Te derivaron una conversación' : 'Hay un cliente esperando' }}

@if ($assigned)
Un cliente de WhatsApp pidió hablar con una persona y la conversación quedó a tu nombre.
@else
Un cliente de WhatsApp pidió hablar con una persona y **todavía no la tomó nadie**. El primero que la reclame se la queda.
@endif

**Contacto:** {{ $contactName }}
@if ($conversation->wa_phone)
**Teléfono:** {{ $conversation->wa_phone }}
@endif
@if ($conversation->handoff_reason)
**Motivo:** {{ $conversation->handoff_reason }}
@endif
**Origen:** {{ $conversation->handoff_source === 'ai' ? 'Agente IA' : 'Asesor' }}
**Solicitada:** {{ optional($conversation->handoff_requested_at)->format('d/m/Y H:i') }}

@if ($messages->isNotEmpty())
---

**Últimos mensajes**

@foreach ($messages as $message)
> **{{ $message->direction === 'inbound' ? $contactName : ($message->sender === 'ia' ? 'Agente IA' : 'Nosotros') }}:** {{ \Illuminate\Support\Str::limit((string) $message->body, 180) ?: '('.$message->type.')' }}
@endforeach
@endif

@component('mail::button', ['url' => $url])
{{ $assigned ? 'Abrir la conversación' : 'Tomar la conversación' }}
@endcomponent

El agente IA ya está desactivado en esta conversación: nadie le va a responder al cliente hasta que lo hagas vos.

@endcomponent
