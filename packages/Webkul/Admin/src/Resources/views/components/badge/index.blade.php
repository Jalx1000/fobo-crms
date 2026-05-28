@props(['type' => 'default', 'label' => ''])

@php
$classes = match($type) {
    'success' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
    'warning' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400',
    'danger'  => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
    'info'    => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
    default   => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
};
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium $classes"]) }}>
    {{ $label ?: $slot }}
</span>
