@extends('admin::emails.notificaciones.layout')

@section('titulo', 'Nueva cotización registrada #'.$quote->id)

@php
    $person = $quote->person;
    $telefonos = collect($person?->contact_numbers ?? [])->pluck('value')->filter()->implode(', ');
    $emails = collect($person?->emails ?? [])->pluck('value')->filter()->implode(', ');

    $bloque = 'background-color: #f8faf5; border: 1px solid #E5E7EB; border-left: 4px solid #D41E35; padding: 16px; margin: 0 0 20px 0; border-radius: 6px;';
    $subtitulo = 'font-size: 13px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; color: #D41E35; margin: 0 0 10px 0;';
    $fila = 'margin-bottom: 6px; font-size: 14px;';
    $celda = 'padding: 8px; border-bottom: 1px solid #E5E7EB; font-size: 13px;';
@endphp

@section('contenido')
    <p style="font-size: 15px; line-height: 1.6; margin: 0 0 20px 0;">
        @if ($asesor)
            Hola {{ $asesor->name }}, se registró una cotización a tu nombre. A continuación se detallan los datos:
        @else
            Se ha registrado una nueva cotización en el CRM. A continuación se detallan los datos:
        @endif
    </p>

    {{-- Cotización --}}
    <div style="{{ $bloque }}">
        <p style="{{ $subtitulo }}">Cotización</p>
        <div style="{{ $fila }}"><strong style="color: #111827;">N°:</strong> #{{ $quote->id }}</div>
        @foreach ($datos as $etiqueta => $valor)
            <div style="{{ $fila }}"><strong style="color: #111827;">{{ $etiqueta }}:</strong> {{ $valor }}</div>
        @endforeach
        @unless ($asesor)
            <div style="{{ $fila }}"><strong style="color: #111827;">Avisado a:</strong> {{ $designados ? implode(', ', $designados) : 'Ningún asesor (revisar asignación)' }}</div>
        @endunless
    </div>

    {{-- Cliente --}}
    <div style="{{ $bloque }}">
        <p style="{{ $subtitulo }}">Cliente</p>
        <div style="{{ $fila }}"><strong style="color: #111827;">Nombre:</strong> {{ $person?->name ?? '-' }}</div>
        @if ($person?->organization)
            <div style="{{ $fila }}"><strong style="color: #111827;">Organización:</strong> {{ $person->organization->name }}</div>
        @endif
        <div style="{{ $fila }}"><strong style="color: #111827;">Teléfono:</strong> {{ $telefonos ?: '-' }}</div>
        <div style="{{ $fila }}"><strong style="color: #111827;">Email:</strong> {{ $emails ?: '-' }}</div>
    </div>

    {{-- Oportunidad --}}
    @if ($lead)
        <div style="{{ $bloque }}">
            <p style="{{ $subtitulo }}">Oportunidad</p>
            <div style="{{ $fila }}"><strong style="color: #111827;">N°:</strong> #{{ $lead->id }} - {{ $lead->title }}</div>
            @if ($lead->pipeline || $lead->stage)
                <div style="{{ $fila }}"><strong style="color: #111827;">Etapa:</strong> {{ collect([$lead->pipeline?->name, $lead->stage?->name])->filter()->implode(' › ') }}</div>
            @endif
            @if ($lead->user)
                <div style="{{ $fila }}"><strong style="color: #111827;">Asesor:</strong> {{ $lead->user->name }}</div>
            @endif
        </div>
    @endif

    {{-- Productos --}}
    <p style="{{ $subtitulo }}">Productos</p>
    <table style="width: 100%; border-collapse: collapse; margin: 0 0 8px 0;" width="100%" cellspacing="0" cellpadding="0">
        <thead>
            <tr style="background-color: #f3f4f6;">
                <th style="{{ $celda }} text-align: left;">Producto</th>
                <th style="{{ $celda }} text-align: center;">Cant.</th>
                <th style="{{ $celda }} text-align: right;">Precio</th>
                <th style="{{ $celda }} text-align: right;">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($quote->items as $item)
                @php
                    /*
                     * `variante` tiene cast a array en QuoteItem: las cotizaciones del
                     * agente guardan los criterios (medida, color…). Las viejas pueden
                     * traer texto. Se muestra «Medida: 50 cm · Color: Negro».
                     */
                    $variante = is_array($item->variante)
                        ? collect($item->variante)
                            ->map(fn ($valor, $clave) => trim(
                                (is_string($clave) ? ucfirst(str_replace('_', ' ', $clave)).': ' : '')
                                .(is_array($valor) ? collect($valor)->flatten()->filter(fn ($v) => is_scalar($v))->implode(', ') : (string) $valor)
                            ))
                            ->filter(fn ($texto) => $texto !== '' && ! str_ends_with($texto, ':'))
                            ->implode(' · ')
                        : (string) $item->variante;
                @endphp
                <tr>
                    <td style="{{ $celda }}">
                        {{ $item->name }}
                        @if ($variante !== '')
                            <br><span style="color: #6b6e76; font-size: 12px;">{{ $variante }}</span>
                        @endif
                    </td>
                    <td style="{{ $celda }} text-align: center;">{{ rtrim(rtrim(number_format((float) $item->quantity, 2, '.', ''), '0'), '.') }}</td>
                    <td style="{{ $celda }} text-align: right;">{!! core()->formatBasePrice($item->price, true) !!}</td>
                    <td style="{{ $celda }} text-align: right;">{!! core()->formatBasePrice($item->total, true) !!}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table style="width: 100%; border-collapse: collapse; margin: 0 0 24px 0;" width="100%" cellspacing="0" cellpadding="0">
        <tbody>
            @foreach ([
                'Subtotal'  => $quote->sub_total,
                'Descuento' => $quote->discount_amount,
                'Impuesto'  => $quote->tax_amount,
                'Ajuste'    => $quote->adjustment_amount,
            ] as $etiqueta => $monto)
                @if ((float) $monto != 0 || $etiqueta === 'Subtotal')
                    <tr>
                        <td style="padding: 4px 8px; font-size: 13px; text-align: right; color: #6b6e76;">{{ $etiqueta }}</td>
                        <td style="padding: 4px 8px; font-size: 13px; text-align: right; width: 120px;">{!! core()->formatBasePrice($monto, true) !!}</td>
                    </tr>
                @endif
            @endforeach
            <tr>
                <td style="padding: 8px; font-size: 15px; text-align: right; font-weight: bold; color: #111827;">Total</td>
                <td style="padding: 8px; font-size: 15px; text-align: right; font-weight: bold; color: #D41E35; width: 120px;">{!! core()->formatBasePrice($quote->grand_total, true) !!}</td>
            </tr>
        </tbody>
    </table>

    <p style="font-size: 15px; line-height: 1.6; margin: 0 0 16px 0;">
        Por favor, revisar esta cotización y dar seguimiento según el flujo comercial correspondiente.
    </p>

    <p style="margin: 20px 0 0 0;">
        <a href="{{ route('admin.quotes.edit', $quote->id) }}" style="display: inline-block; padding: 10px 18px; background-color: #D41E35; color: #ffffff; text-decoration: none; border-radius: 6px; font-size: 14px; font-weight: bold;">Ver cotización</a>
        @if ($lead)
            &nbsp;
            <a href="{{ route('admin.leads.view', $lead->id) }}" style="display: inline-block; padding: 10px 18px; background-color: #ffffff; color: #D41E35; text-decoration: none; border: 1px solid #D41E35; border-radius: 6px; font-size: 14px; font-weight: bold;">Ver oportunidad</a>
        @endif
    </p>
@endsection
