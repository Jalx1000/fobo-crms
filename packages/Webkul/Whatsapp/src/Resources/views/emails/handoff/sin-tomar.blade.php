@component('mail::message')
# Una derivación lleva {{ $minutos }} minutos sin atender

**{{ $handoff->conversation?->wa_name ?: 'Un cliente' }}**
({{ $handoff->conversation?->wa_phone ?: 'sin teléfono' }}) fue derivado a
**{{ $handoff->asesor?->name ?: 'un asesor' }}** y todavía nadie tomó la conversación.

@if ($handoff->referencia_codigo)
Consultó por el inmueble **{{ $handoff->referencia_codigo }}**.
@endif

@if ($handoff->reason)
> {{ $handoff->reason }}
@endif

Mientras nadie la tome, **el cliente no recibe respuesta**: el agente deja de contestar en cuanto
deriva, que es lo que evita que hable encima del asesor.

@component('mail::button', ['url' => route('admin.whatsapp.index')])
Abrir el inbox
@endcomponent

Este aviso se manda una sola vez por derivación.
@endcomponent
