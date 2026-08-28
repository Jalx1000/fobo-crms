<x-admin::layouts>
    <x-slot:title>{{ $inmueble->codigo }} — {{ $inmueble->titulo }}</x-slot>

    <form method="POST" action="{{ route('admin.inmuebles.update', $inmueble->id) }}">
        @csrf
        @method('PUT')
        @include('inmobiliaria::inmuebles._form', [
            'titulo' => $inmueble->codigo.' — editar',
        ])
    </form>

    @include('inmobiliaria::inmuebles._galeria')
</x-admin::layouts>
