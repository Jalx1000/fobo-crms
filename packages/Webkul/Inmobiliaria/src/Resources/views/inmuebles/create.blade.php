<x-admin::layouts>
    <x-slot:title>Nuevo inmueble</x-slot>

    <form method="POST" action="{{ route('admin.inmuebles.store') }}">
        @csrf
        @include('inmobiliaria::inmuebles._form', [
            'titulo'  => 'Nuevo inmueble',
            'inmueble' => null,
        ])
    </form>
</x-admin::layouts>
