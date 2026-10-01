@extends('admin::emails.contacto-nuevo.layout')

@section('titulo', 'Nuevo contacto registrado')

@section('contenido')
    <p style="font-size: 15px; line-height: 1.6; margin: 0 0 16px 0;">
        @if ($asesor)
            Hola {{ $asesor->name }}, se registró un contacto que te corresponde. A continuación se detallan los datos capturados:
        @else
            Se ha registrado un nuevo contacto en el CRM. A continuación se detallan los datos capturados:
        @endif
    </p>

    @php
        $telefonos = collect($person->contact_numbers ?? [])->pluck('value')->filter()->implode(', ');
        $emails = collect($person->emails ?? [])->pluck('value')->filter()->implode(', ');
    @endphp

    <div style="background-color: #f8faf5; border: 1px solid #E5E7EB; border-left: 4px solid #D41E35; padding: 16px; margin: 24px 0; border-radius: 6px;">
        <div style="margin-bottom: 8px; font-size: 14px;">
            <strong style="color: #111827;">Nombre:</strong> {{ $person->name }}
        </div>
        <div style="margin-bottom: 8px; font-size: 14px;">
            <strong style="color: #111827;">Teléfono:</strong> {{ $telefonos ?: '—' }}
        </div>
        <div style="margin-bottom: 8px; font-size: 14px;">
            <strong style="color: #111827;">Email:</strong> {{ $emails ?: '—' }}
        </div>
        <div style="margin-bottom: 8px; font-size: 14px;">
            <strong style="color: #111827;">ID del Contacto:</strong> {{ $person->id }}
        </div>
        <div style="margin-bottom: {{ $asesor ? '0' : '8px' }}; font-size: 14px;">
            <strong style="color: #111827;">Ciudad:</strong> {{ $ciudad ?? 'Sin ciudad' }}
        </div>
        @unless ($asesor)
            <div style="margin-bottom: 0; font-size: 14px;">
                <strong style="color: #111827;">Avisado a:</strong> {{ $designados ? implode(', ', $designados) : 'Ningún asesor (revisar asignación)' }}
            </div>
        @endunless
    </div>

    <p style="font-size: 15px; line-height: 1.6; margin: 0 0 16px 0;">
        Por favor, revisar este registro y dar seguimiento según el flujo comercial correspondiente.
    </p>

    <p style="margin: 24px 0 0 0;">
        <a href="{{ route('admin.contacts.persons.view', $person->id) }}" style="display: inline-block; padding: 10px 18px; background-color: #D41E35; color: #ffffff; text-decoration: none; border-radius: 6px; font-size: 14px; font-weight: bold;">Ver contacto en el CRM</a>
    </p>
@endsection
