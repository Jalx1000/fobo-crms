---
name: project-persons-view-structure
description: Layout real de la vista de detalle del paciente en contacts/persons/{id}/view.blade.php — sidebar izquierdo + panel derecho con sistema de tabs via x-admin::activities
metadata:
  type: project
---

# Layout de la vista de paciente

**Archivo:** `packages/Webkul/Admin/src/Resources/views/contacts/persons/view.blade.php`

## Estructura de dos columnas

- **Sidebar izquierdo** (min-w-[394px], sticky): nombre, tags, botones de acción, badge SMD, componente v-smd-sync, atributos, organización.
- **Panel derecho** (w-full): componente `<x-admin::activities>` que renderiza tabs.

## Sistema de tabs

El componente `x-admin::activities` (en `components/activities/index.blade.php`) genera tabs dinámicas via Vue. Los tipos por defecto son: all, planned, note, call, meeting, file, system. Acepta prop `:extra-types="[...]"` para agregar tabs custom con slot nombrado.

Ejemplo de uso en la vista del paciente:
```blade
<x-admin::activities :endpoint="..." :extra-types="[['name' => 'historial', 'label' => 'Historial IA']]">
    <x-slot:historial>
        @include('admin::leads.view.historial-ia', [...])
    </x-slot>
</x-admin::activities>
```

La tab custom se renderiza dentro de `<div v-show="selectedType == type.name"><slot :name="type.name"></slot></div>`.

## Tabs actuales en la vista del paciente

1. Todas (all)
2. Planeadas (planned)
3. Notas (note)
4. Llamadas (call)
5. Reuniones (meeting)
6. Archivos (file)
7. Registro de cambios (system)
8. Historial IA (extra — slot `historial`)

**Why:** El sistema de tabs ya está operativo mediante extraTypes. Agregar una tab nueva solo requiere pasar un elemento más en el array extraTypes y agregar un x-slot con el partial correspondiente.

**How to apply:** Para agregar la tab SMD/Seguro: agregar `['name' => 'smd', 'label' => 'Seguro & SMD']` al array extraTypes y crear el partial `view/smd-insurance.blade.php`.
