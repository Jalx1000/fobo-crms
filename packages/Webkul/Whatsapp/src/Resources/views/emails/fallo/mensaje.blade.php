<p>Un mensaje de WhatsApp no le llegó al cliente.</p>

<table cellpadding="6" style="border-collapse:collapse">
    <tr>
        <td><strong>Cliente</strong></td>
        <td>{{ $mensaje->conversation?->wa_name ?: 'Sin nombre' }} — {{ $mensaje->conversation?->wa_phone }}</td>
    </tr>
    <tr>
        <td><strong>Mensaje</strong></td>
        <td>
            @if ($mensaje->type === 'template')
                Plantilla <strong>{{ $mensaje->payload['template'] ?? '?' }}</strong>
            @else
                {{ $mensaje->type }}
            @endif
            ({{ $mensaje->sender }}, {{ $mensaje->created_at?->format('d/m/Y H:i') }})
        </td>
    </tr>
    @if ($mensaje->body)
        <tr>
            <td><strong>Texto</strong></td>
            <td>{{ \Illuminate\Support\Str::limit($mensaje->body, 200) }}</td>
        </tr>
    @endif
    <tr>
        <td><strong>Motivo</strong></td>
        <td style="color:#b91c1c">{!! nl2br(e($motivo)) !!}</td>
    </tr>
</table>

<p><a href="{{ $inboxUrl }}">Abrir la conversación</a></p>

<p style="color:#666;font-size:12px">
    Los códigos de error de Meta se explican en
    https://developers.facebook.com/docs/whatsapp/cloud-api/support/error-codes
</p>
