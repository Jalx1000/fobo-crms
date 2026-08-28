<x-admin::layouts>
    <x-slot:title>Nuevo inmueble</x-slot>

    <form method="POST" action="{{ route('admin.inmuebles.store') }}" enctype="multipart/form-data">
        @csrf
        @include('inmobiliaria::inmuebles._form', [
            'titulo'  => 'Nuevo inmueble',
            'inmueble' => null,
        ])

        @include('inmobiliaria::inmuebles._fotos-al-crear')
    </form>
</x-admin::layouts>
