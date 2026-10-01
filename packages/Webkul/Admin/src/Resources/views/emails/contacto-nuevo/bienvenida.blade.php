@extends('admin::emails.contacto-nuevo.layout')

@section('titulo', '¡Hola, '.$person->name.'!')

@section('firma', 'Equipo de Imprimir')

@section('pie', 'Este es un mensaje automático de Imprimir')

@section('contenido')
    <p style="font-size: 15px; line-height: 1.6; margin: 0 0 16px 0;">
        Gracias por contactarte con <strong style="color: #111827;">Imprimir</strong>. Ya registramos tus datos y un asesor te va a atender a la brevedad.
    </p>

    <div style="background-color: #f8faf5; border: 1px solid #E5E7EB; border-left: 4px solid #D41E35; padding: 16px; margin: 24px 0; border-radius: 6px; font-size: 14px; line-height: 1.6;">
        Si querés adelantar tu consulta, podés responder este correo o escribirnos por WhatsApp.
    </div>
@endsection
