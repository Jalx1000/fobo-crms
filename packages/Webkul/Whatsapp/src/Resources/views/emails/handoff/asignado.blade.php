@component('mail::message')
# Hay un cliente esperándote

Un cliente de WhatsApp pidió hablar con una persona y la conversación quedó a tu nombre.

**Contacto:** {{ $contacto }}
@if ($handoff->conversation?->wa_phone)
**Teléfono:** {{ $handoff->conversation->wa_phone }}
@endif
@if ($handoff->referencia_codigo)
**Inmueble:** {{ $handoff->referencia_codigo }}
@endif
@if ($handoff->reason)
**Motivo:** {{ $handoff->reason }}
@endif
**Derivada:** {{ optional($handoff->opened_at)->format('d/m/Y H:i') }}

@if ($mensajes->isNotEmpty())
---

**Últimos mensajes**

@foreach ($mensajes as $mensaje)
> **{{ $mensaje->direction === 'inbound' ? $contacto : ($mensaje->sender === 'ia' ? 'Agente IA' : 'Nosotros') }}:** {{ \Illuminate\Support\Str::limit((string) $mensaje->body, 180) ?: '('.$mensaje->type.')' }}
@endforeach
@endif

@component('mail::button', ['url' => $url])
Abrir la conversación
@endcomponent

El agente IA quedó desactivado en esta conversación: nadie le va a responder al cliente hasta que lo hagas vos.

@endcomponent
