<p>Hola{{ $advisorName ? ' '.$advisorName : '' }},</p>

<p>
    El agente de WhatsApp derivó una conversación
    @if ($cityName)
        de <strong>{{ $cityName }}</strong>
    @endif
    y necesita que la atienda una persona.
</p>

<table cellpadding="6" style="border-collapse:collapse">
    <tr>
        <td><strong>Cliente</strong></td>
        <td>{{ $conversation->wa_name ?: 'Sin nombre' }} — {{ $conversation->wa_phone }}</td>
    </tr>
    @if ($cityName)
        <tr><td><strong>Ciudad</strong></td><td>{{ $cityName }}</td></tr>
    @endif
    @if ($conversation->handoff_reason)
        <tr><td><strong>Motivo</strong></td><td>{{ $conversation->handoff_reason }}</td></tr>
    @endif
</table>

@unless ($advisorName)
    <p>
        <strong>Nadie tiene asignada esta conversación.</strong>
        La toma el primero del equipo que entre al inbox.
    </p>
@endunless

<p><a href="{{ $inboxUrl }}">Abrir la conversación</a></p>

<p style="color:#666;font-size:12px">
    El agente ya le avisó al cliente que lo atiende una persona, y dejó de responderle.
</p>
