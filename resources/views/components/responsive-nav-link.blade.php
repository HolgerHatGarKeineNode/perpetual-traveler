@props(['active'])

@php
$classes = ($active ?? false)
            ? 'block w-full ps-3 pe-4 py-2 border-l-4 border-gold-400 text-start text-base font-medium text-navy-900 dark:text-navy-50 bg-gold-400/10 focus:outline-none transition duration-150 ease-in-out'
            : 'block w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-navy-600 dark:text-navy-300 hover:text-navy-900 dark:hover:text-navy-100 hover:bg-navy-100/60 dark:hover:bg-white/5 hover:border-navy-200 dark:hover:border-white/20 focus:outline-none transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
